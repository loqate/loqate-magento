<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Controller;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\ShopperScopedSession;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Unit tests for the captured-address store: Helper\Controller::storeCapturedAddress()
 * and the session attribute it owns, Controller::CAPTURED_ADDRESSES_SESSION_KEY.
 *
 * WHAT THIS STORE IS. Every address a shopper picks from the Loqate Capture lookup is
 * serialised into this session attribute by Controller::retrieve(), its only writer.
 * Helper\Validator then treats a match against it as "Loqate authored this address" and
 * SKIPS the billable Cleansing verify entirely - on both the single-address path
 * (verifyAddress()) and the batch path (verifyMultipleAddresses()). It is therefore a
 * verify BYPASS held in session state, which is what makes both of its properties matter:
 * how much of it there is, and whose it is.
 *
 * WHAT LOQ-16978 CHANGED, and what these tests pin. The store used to grow WITHOUT LIMIT
 * for the whole session and to append a fresh copy of an address every time it was
 * re-picked, so a shopper cycling one address through the lookup inflated the session
 * payload unboundedly (a PHP session is read, unserialised and written on EVERY request,
 * so this is paid per request and, on the Redis/database session handlers, over the wire).
 * It is now bounded to Controller::CAPTURED_ADDRESSES_LIMIT entries with FIFO eviction -
 * oldest first, mirroring Validator::storeVerifyResult() - and a re-captured address MOVES
 * to the newest position instead of being duplicated. Both halves are asserted here twice
 * over: structurally, on the array actually left in the session
 * (testCapturedAddressStoreIsBoundedToTheDocumentedLimit(),
 * testRecapturingAnAddressStoresNoSecondCopy()), and behaviourally, through the bypass the
 * store exists to grant - because a bound that quietly dropped the WRONG entry, or an
 * eviction that fired one entry too early, would satisfy a count assertion while costing a
 * shopper a billable verify (testEveryCapturedAddressUpToTheLimitStillSkipsVerification(),
 * testOnlyTheEvictedCapturedAddressIsVerifiedAgain()).
 *
 * The FIFO direction is not interchangeable: the address currently being checked out is
 * always the one most recently captured, so evicting the NEWEST entry would defeat the
 * bypass for exactly the address that is about to be verified 3-5 times (see the call-path
 * list on Validator::verifyAddress()).
 *
 * WHAT "ALREADY IN THE STORE" MEANS, and why it is a subject in its own right here. The
 * de-duplication is decided by Validator::capturedAddressSignature() - the very relation
 * Validator::checkForCapturedAddress() grants the bypass on - and NOT by the serialised
 * bytes it used to compare. Byte comparison disagreed with the matcher in four ways, always
 * in the damaging direction, so a bounded store could still be filled by one address cycled
 * through the lookup. That relation, and the requirement that the store and the matcher
 * AGREE about it, is pinned by
 * testTheStoreDeDuplicatesOnExactlyTheRelationTheBypassMatcherUses() over a table of pairs
 * that must and must not collapse, with
 * testALegacyEntryWithNoSecondLineAtAllCollapsesOntoTheSameAddress() and
 * testTwoDifferentUnidentifiableCapturesAreNeverCollapsedOntoEachOther() covering the two
 * shapes that table cannot express.
 *
 * THE LIMIT'S VALUE is pinned separately from its use, in
 * testTheCapturedStoreBoundIsTheSameAsTheSingleAddressVerdictCacheBound(). Every other bound
 * test here reads the constant, which is correct but leaves them all satisfied by a limit of
 * 1; the requirement is that this bound stay consistent with the neighbours it is documented
 * against.
 *
 * WHAT A MALFORMED STORE MAY COST, and why that is asserted on BOTH READERS. This is a bare
 * session key shared with every other module on the installation, ShopperScopedSession flushes
 * it by writing null, and sessions come back from storage that truncates - so "not a list of
 * entries this module wrote" is a routine state, not a hypothetical one. The contract is
 * therefore a DEGRADATION, stated as such: a store that cannot be read costs a MISSED BYPASS -
 * the address is verified, which means BILLED - and never a fatal. Both halves are asserted
 * every time (the accepted verdict AND the billable request, see billedStreets()), because
 * either one alone is satisfied by the opposite defect. It is asserted through
 * Validator::verifyAddress() AND Validator::verifyMultipleAddresses() because they are two
 * separate call sites of the same matcher, each testing the attribute for TRUTHINESS only -
 * which is exactly why the whole-attribute guard has to live inside the matcher, ahead of its
 * loop, rather than in either caller. See
 * testAForeignElementInTheStoreCostsABypassRatherThanAFatalInEitherReader() and
 * testATruthyNonListStoreCostsABypassRatherThanAFatalInEitherReader(), with
 * testAForeignElementDoesNotHideACapturedAddressBehindItFromEitherReader() pinning that an
 * unreadable element is stepped OVER rather than ending the search. Those tests run the reader
 * under Magento's own error handler (verifyThrough()), since half of what has to be prevented
 * is an E_WARNING that a live store promotes to an exception.
 *
 * The OWNERSHIP half of LOQ-16978 - that this store is flushed when the logged-in shopper
 * changes - is not repeated here: it belongs to the seam that enforces it and is covered by
 * ShopperScopedSessionTest, together with the two verdict caches that share the rule. What
 * IS pinned here is that this class cannot reach the store any other way, see
 * testTheStoreIsReachedOnlyThroughTheShopperOwnershipGuard().
 */
class CapturedAddressStoreTest extends TestCase
{
    /** Any non-empty key lets both helpers build their API connectors. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /** AVC strictly better than the baked-in default threshold "P40-U00-P0-95" => accepted. */
    private const PASSING_AVC = 'V55-I22-P9-99';

    /** Threshold the BATCH reader judges an AQI against; the connector double answers 'A'. */
    private const AQI_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /** The module's own shipped default for that threshold - etc/config.xml:20. */
    private const DEFAULT_AQI_THRESHOLD = 'A';

    /** Session attribute the captured-address store must live under. */
    private const CAPTURED_ADDRESSES_SESSION_KEY = 'captured_addresses';

    /**
     * The two readers of the captured-address store, by the method that reads it.
     *
     * Both are named in every malformed-store test below because they are two separate call
     * sites of Validator::checkForCapturedAddress() with two separate pre-checks on the
     * attribute - each only tests it for TRUTHINESS - so a guard that lives in one caller
     * protects one path and leaves the other one fatal (LOQ-16978).
     */
    private const READER_SINGLE = 'verifyAddress';
    private const READER_BATCH = 'verifyMultipleAddresses';

    /** The address every malformed-store test captures, verifies and expects to be billed. */
    private const ADDRESS_UNDER_TEST = 1;

    /**
     * Stands in, inside a malformedStoreProvider() row, for the serialised entry the store
     * legitimately holds for the address under test - as the WHOLE attribute rather than as an
     * element of it.
     *
     * A placeholder for the same reason as self::THE_ADDRESS_BEING_CAPTURED: the provider is
     * static and the entry is whatever Controller::storeCapturedAddress() actually wrote.
     */
    private const THE_ENTRY_UNWRAPPED = '<the serialised entry, stored bare instead of in a list>';

    /**
     * Stands in, inside a malformedStoreProvider() row, for an OBJECT whose public property
     * holds that same entry.
     *
     * The one malformed whole attribute that foreach() accepts - iterating an object's public
     * properties is legal - so it is the row that pins the guard as a guard on the TYPE of the
     * store rather than as a way of dodging the foreach() warning.
     */
    private const AN_OBJECT_AROUND_THE_ENTRY = '<an object whose public property holds the entry>';

    /**
     * Stands in, inside a mixedStoreProvider() row, for the serialised entry the capture under
     * test is going to de-duplicate.
     *
     * A placeholder rather than the entry itself because the provider is static and the entry
     * is whatever the serializer double produces; substituting it inside the test lets a row
     * put the existing entry at any position among the foreign elements, which is the whole
     * point of those rows.
     */
    private const THE_ADDRESS_BEING_CAPTURED = '<the address already in the store>';

    /**
     * Build one independent "shopper": a Helper\Controller (which writes the captured-address
     * store) and a Helper\Validator (which reads it) wired to the SAME customer session
     * double, plus the Validator's own billable connector mock.
     *
     * The two helpers have to share one session for these tests to mean anything: the whole
     * point of the store is that what Controller::retrieve() wrote on one request is what
     * lets Validator::verifyAddress() skip the billable call on the next.
     *
     * Pass an existing $session to model the same browser session on a later request.
     *
     * @param ArrayObject|null $session Session backing store to reuse, or null for a new session.
     * @return array{controller: Controller, validator: Validator, connector: Verify&MockObject,
     *     requests: ArrayObject, session: ArrayObject}
     */
    private function createShopper(?ArrayObject $session = null): array
    {
        $sessionStore = $session ?? new ArrayObject();
        $requests = new ArrayObject();

        $logger = $this->createMock(Logger::class);

        // The shared Test/stubs Session is a no-op (getData() returns null, setData() stores
        // nothing), so the captured-address store could never survive between calls. This
        // double retains data in $sessionStore, which also lets the tests assert exactly what
        // was written.
        //
        // getData()/setData() have to be *added* to the double when the real
        // Magento\Customer\Model\Session is present, because it does not declare them:
        // SessionManager __call-forwards them to Session\Storage, so createMock() could not
        // configure them. The Test/stubs Session used when Magento is absent does declare
        // them, and PHPUnit refuses to "add" a method that already exists - hence the
        // method_exists() filter, which keeps this double working on both sides.
        $sessionBuilder = $this->getMockBuilder(Session::class)->disableOriginalConstructor();
        $undeclared = array_values(array_filter(
            ['getData', 'setData'],
            static fn (string $method): bool => !method_exists(Session::class, $method)
        ));
        if ($undeclared) {
            $sessionBuilder->addMethods($undeclared);
        }
        $sessionMock = $sessionBuilder->getMock();
        $sessionMock->method('getData')->willReturnCallback(
            static function ($key = '', $clear = false) use ($sessionStore) {
                return $sessionStore[$key] ?? null;
            }
        );
        $sessionMock->method('setData')->willReturnCallback(
            static function ($key, $value = null) use ($sessionStore, $sessionMock) {
                $sessionStore[$key] = $value;

                return $sessionMock;
            }
        );
        // One shopper, one identity, for the whole of this file: the store belongs to the
        // same customer throughout, so ShopperScopedSession adopts it and never flushes.
        // The identity-change behaviour is ShopperScopedSessionTest's subject, and leaving it
        // out here is what keeps a failure in these tests attributable to the bound itself.
        $sessionMock->method('getCustomerId')->willReturn(7);

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '9.9.9']);

        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static function ($configPath) {
                // A non-empty API key is required twice over: both constructors only build
                // their connector when it is set, and verifyAddress() short-circuits with
                // noKeyFound when it is not.
                if ($configPath === 'loqate_settings/settings/api_key') {
                    return self::API_KEY;
                }

                // The BATCH reader judges its verdicts against this threshold instead of
                // against an AVC, and an unset one would make every billed address in
                // verifyMultipleAddresses() come back INVALID ('A' <= '' is false as a string
                // comparison) - which would leave the batch half of the malformed-store tests
                // asserting a verdict that says more about this harness than about the store.
                // The module's own default is used so the answer stays the shipped one.
                if ($configPath === self::AQI_CONFIG_PATH) {
                    return self::DEFAULT_AQI_THRESHOLD;
                }

                // Everything else reads as empty, which is what an untouched admin field
                // leaves behind - in particular show_advanced_avc_settings != 1, so
                // checkAVCStatus() uses the baked-in default thresholds.
                return '';
            }
        );
        $helper->method('getCurrentStore')->willReturn(0);

        $serializer = $this->createSerializerDouble();

        $controller = new Controller(
            $this->createMock(ResultFactory::class),
            $this->createMock(RequestInterface::class),
            $logger,
            $sessionMock,
            $moduleList,
            $helper,
            $serializer
        );

        $validator = new Validator(
            $logger,
            $sessionMock,
            $this->createMock(RegionFactory::class),
            $moduleList,
            $helper,
            $serializer
        );

        // The connector is built inside the constructor (new Verify($apiKey)), so the only
        // way to intercept the billable call is to swap the private property afterwards.
        $connector = $this->createMock(Verify::class);
        $connector->method('verifyAddress')->willReturnCallback(
            static function ($params) use ($requests) {
                $requests[] = $params;

                return [[['AVC' => self::PASSING_AVC, 'AQI' => 'A']]];
            }
        );
        $apiConnector = new ReflectionProperty(Validator::class, 'apiConnector');
        $apiConnector->setAccessible(true);
        $apiConnector->setValue($validator, $connector);

        return [
            'controller' => $controller,
            'validator' => $validator,
            'connector' => $connector,
            'requests' => $requests,
            'session' => $sessionStore,
        ];
    }

    /**
     * A SerializerInterface double that fails the way the production serializer fails.
     *
     * The configured serializer for this module is Magento\Framework\Serialize\Serializer\Json,
     * and its unserialize() THROWS \InvalidArgumentException on anything it cannot decode -
     * including the empty string and null - rather than answering null. A double written as
     * `fn ($v) => json_decode($v, true)` therefore makes the whole
     * "an entry in the store cannot be read back" family of paths unreachable from the
     * harness: Controller::capturedEntrySignature() and Validator::checkForCapturedAddress()
     * both wrap the call in `try { ... } catch (\InvalidArgumentException $e)`, and against a
     * lenient double those catch blocks can never run, so deleting either of them would leave
     * the suite green while a truncated session payload became a fatal mid-checkout
     * (LOQ-16978 review). Mirroring the real failure mode here is what makes
     * testAnUnreadableEntryInTheStoreCostsADeDuplicationRatherThanAFatal() a real test.
     *
     * The SECOND failure mode it has to mirror is the TypeError, and that one is answered by
     * the READER's is_string() guard: json_decode()'s first parameter is declared
     * `string $json`, so an array or object element raises an \Error, which the
     * \InvalidArgumentException catch above does NOT cover. A lenient double swallows that as
     * well, and testAForeignElementInTheStoreCostsABypassRatherThanAFatalInEitherReader() would
     * then pass against a build that fatals in front of a shopper. Hence the note below.
     *
     * @return SerializerInterface&MockObject
     */
    private function createSerializerDouble()
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn ($value) => json_encode($value));
        $serializer->method('unserialize')->willReturnCallback(
            static function ($value) {
                // Magento\Framework\Serialize\Serializer\Json::unserialize(), verbatim in
                // behaviour: the two rejected-outright values first, then a decode whose
                // failure is reported by json_last_error() rather than by a null return -
                // null being a legitimately decodable value.
                if ($value === false || $value === null || $value === '') {
                    throw new \InvalidArgumentException('Unable to unserialize value.');
                }

                // NOT cast to string first, because the production serializer does not cast
                // either: an array or an object reaching json_decode() is a TypeError there
                // and must be one here, or this double would quietly make a caller that hands
                // it a non-string look safe when production would fatal.
                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Unable to unserialize value, string is corrupted.');
                }

                return $decoded;
            }
        );

        return $serializer;
    }

    /**
     * THE bound. Capturing more addresses than the limit must leave EXACTLY the limit's worth
     * of entries: no more, because an unbounded store is re-read, unserialised and written on
     * every single request for the rest of the session, and no fewer, because a store that
     * under-fills re-bills addresses it had room to keep.
     */
    public function testCapturedAddressStoreIsBoundedToTheDocumentedLimit(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        for ($i = 1; $i <= $limit + 5; $i++) {
            $this->captureAddress($shopper, $i);
        }

        $this->assertCount(
            $limit,
            $this->capturedStore($shopper),
            'Once more than Controller::CAPTURED_ADDRESSES_LIMIT addresses have been captured the store must '
            . 'hold exactly CAPTURED_ADDRESSES_LIMIT entries: the session payload must stay bounded, and it '
            . 'must still use every slot it advertises.'
        );
    }

    /**
     * The limit's VALUE, not merely its use - the one thing every other bound test in this
     * file is blind to.
     *
     * All of them read Controller::CAPTURED_ADDRESSES_LIMIT from the constant, which is
     * correct (they must not restate a number that may legitimately be tuned) but means the
     * whole file would pass with the limit set to 1 - a store that holds one address, evicts
     * the shopper's shipping address the moment they capture their billing address, and
     * re-bills it. The requirement LOQ-16978 actually states is that this bound be CONSISTENT
     * WITH ITS NEIGHBOURS, so that is what is asserted here.
     */
    public function testTheCapturedStoreBoundIsTheSameAsTheSingleAddressVerdictCacheBound(): void
    {
        $this->assertSame(
            $this->verifyCacheLimit(),
            $this->capturedLimit(),
            'Controller::CAPTURED_ADDRESSES_LIMIT and Validator::VERIFY_CACHE_LIMIT must be the same number. '
            . 'The two stores are consulted for the SAME addresses by the SAME verify call - '
            . 'Validator::verifyAddress() reads the captured-address store first and then the verdict cache - '
            . 'so a different bound on each only means one of them still holding entries the other has long '
            . 'since evicted: either capacity nothing can use, or a bypass that expires while the verdict '
            . 'behind it is still live. Neither store is written by an import, which is why both are '
            . 'deliberately smaller than the batch cache; if one of them genuinely needs a different figure, '
            . 'the reason belongs in both docblocks and in this message.'
        );

        $this->assertGreaterThan(
            1,
            $this->capturedLimit(),
            'The store must hold more than one address. The shortest realistic session captures two - a '
            . 'shipping address and a billing address - so a limit of 1 would evict the first the moment the '
            . 'second is picked and re-bill it on every one of the call paths listed on '
            . 'Validator::verifyAddress().'
        );

        $this->assertLessThan(
            $this->batchCacheLimit(),
            $this->capturedLimit(),
            'The captured-address store must stay SMALLER than Validator::BATCH_VERIFY_CACHE_LIMIT. That '
            . 'larger figure exists solely to hold a 100-row customer-import chunk, and no import writes this '
            . 'store: its only writer is a person picking an address out of the Capture lookup by hand.'
        );
    }

    /**
     * The eviction DIRECTION, which no count assertion can express and which is not
     * interchangeable: the oldest entry goes first.
     *
     * The address currently being checked out is always the most recently captured one, so a
     * store that dropped the NEWEST entry would remove the bypass for precisely the address
     * about to be verified 3-5 times over (see the call-path list on
     * Validator::verifyAddress()) - a change that a "count === limit" test cannot see.
     */
    public function testTheOldestCapturedAddressesAreTheOnesEvicted(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        for ($i = 1; $i <= $limit + 5; $i++) {
            $this->captureAddress($shopper, $i);
        }

        $this->assertSame(
            $this->expectedStreets(range(6, $limit + 5)),
            $this->capturedStreets($shopper),
            'Eviction must be FIFO: the five oldest captures must be the ones dropped, and the survivors must '
            . 'stay in capture order with the newest last.'
        );
    }

    /**
     * The store is a LIST and has to stay one. array_shift() renumbers integer keys, but the
     * unset() that removes a re-captured address does not, so the store is re-indexed after
     * it: without that, the attribute serialised into the session degrades from a JSON array
     * into a JSON object with sparse numeric keys, and grows a key per eviction.
     */
    public function testTheStoreStaysACleanZeroIndexedList(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        // Overflow the store AND re-capture an address, so both the eviction path and the
        // unset-then-append path have run before the keys are inspected.
        for ($i = 1; $i <= $limit + 5; $i++) {
            $this->captureAddress($shopper, $i);
        }
        $this->captureAddress($shopper, $limit);

        $store = $this->capturedStore($shopper);

        $this->assertSame(
            range(0, $limit - 1),
            array_keys($store),
            'The captured-address store must remain a clean 0-indexed list: a sparse array serialises into '
            . 'the session as an object and carries a redundant key per entry.'
        );
        $this->assertTrue(array_is_list($store), 'The store must be a list, not a keyed map.');
    }

    /**
     * The bound must be a bound, not a cliff: every one of the CAPTURED_ADDRESSES_LIMIT
     * addresses the store claims to hold has to genuinely still skip the billable verify.
     *
     * This is what distinguishes a real FIFO store of LIMIT entries from one that keeps only
     * the newest capture, or one whose effective limit is far smaller than advertised - both
     * of which satisfy a bare "count <= limit" assertion while quietly re-billing shoppers.
     */
    public function testEveryCapturedAddressUpToTheLimitStillSkipsVerification(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        for ($i = 1; $i <= $limit; $i++) {
            $this->captureAddress($shopper, $i);
        }

        // Verify all of them, oldest first.
        for ($i = 1; $i <= $limit; $i++) {
            $result = $shopper['validator']->verifyAddress($this->magentoAddress($i));
            $this->assertSame(
                ['error' => false],
                $result,
                'Captured address ' . $i . ' must be accepted without being verified.'
            );
        }

        $this->assertSame(
            0,
            $this->apiCallCount($shopper),
            'A store bounded to ' . $limit . ' entries must actually grant the bypass to all ' . $limit
            . ' of them: not one of these addresses may reach the billable Loqate API.'
        );
    }

    /**
     * The other side of the same coin, and the only address the bound is allowed to cost:
     * once the store overflows, the evicted (oldest) address is verified again while the
     * newest still is not.
     *
     * Asserted in this order on purpose - the evicted address first - so the second assertion
     * cannot be satisfied by a store that simply stopped granting the bypass to anything.
     */
    public function testOnlyTheEvictedCapturedAddressIsVerifiedAgain(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        for ($i = 1; $i <= $limit + 1; $i++) {
            $this->captureAddress($shopper, $i);
        }

        $shopper['validator']->verifyAddress($this->magentoAddress(1));

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The oldest captured address must have been evicted, so it no longer skips the billable verify.'
        );

        $shopper['validator']->verifyAddress($this->magentoAddress($limit + 1));

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The most recently captured address is the one being checked out, so it must survive eviction and '
            . 'keep its bypass.'
        );
    }

    /**
     * Re-picking an address from the lookup - which a shopper does routinely, correcting a
     * typo and searching again, or capturing the same address into the billing form - must
     * not add a second copy of it.
     *
     * Duplicates are what made the old store grow without bound in the one scenario a bound
     * alone does not fix: cycling ONE address through the lookup would otherwise fill every
     * slot with copies of it and evict every other address the shopper had captured.
     *
     * This is the EXACT re-capture - the same lookup record twice - which is the easy half
     * and the only one byte comparison ever caught. The near-identical re-captures that byte
     * comparison missed, and that the shopper actually produces (a re-search that returns the
     * same address with a different ProvinceName, or in a different case), are the subject of
     * testTheStoreDeDuplicatesOnExactlyTheRelationTheBypassMatcherUses(); both live here
     * because the "cannot be filled by one address" guarantee needs both.
     */
    public function testRecapturingAnAddressStoresNoSecondCopy(): void
    {
        $shopper = $this->createShopper();

        $this->captureAddress($shopper, 1);
        $this->captureAddress($shopper, 2);
        $this->captureAddress($shopper, 1);

        $this->assertCount(
            2,
            $this->capturedStore($shopper),
            'Re-capturing an address already in the store must refresh it, not append a duplicate.'
        );
        $this->assertSame(
            $this->expectedStreets([2, 1]),
            $this->capturedStreets($shopper),
            'The re-captured address must MOVE to the newest position, so it is the last thing evicted rather '
            . 'than keeping the age of the copy it replaced.'
        );
    }

    /**
     * THE DE-DUPLICATION RELATION ITSELF, and the property the LOQ-16978 review turned it
     * into: the store must collapse two captures if and only if the BYPASS MATCHER treats
     * them as one address.
     *
     * WHAT CHANGED. De-duplication used to compare the SERIALISED BYTES of the six
     * ADDRESS_CAPTURE_MAPPING fields, while Validator::checkForCapturedAddress() grants the
     * bypass on Validator::capturedAddressSignature() - a normalised, upper-cased,
     * whitespace-collapsed projection of FIVE of them, with ProvinceName excluded outright.
     * The two relations therefore disagreed, and always in the damaging direction: two
     * captures that differ only in letter case, in whitespace, in ProvinceName - which
     * capture.js rewrites routinely - or in an empty versus an absent Line2 were ONE address
     * for bypass purposes but TWO slots in a bounded store. That is exactly how cycling a
     * SINGLE address through the lookup could still fill every slot and evict every other
     * address the shopper had captured, which is the failure the de-duplication exists to
     * prevent. Both are now decided by capturedAddressSignature().
     *
     * WHY THE TWO HALVES ARE ASSERTED TOGETHER, in one test over one table of pairs. Either
     * relation is easy to satisfy alone - a store that collapsed everything would pass a
     * "one slot" assertion, and a matcher that matched everything would pass a "bypass
     * granted" one. What has to hold is that they AGREE, so the same pair drives both: how
     * many slots the pair occupies, and whether the second address skips the billable verify
     * after only the first was captured. A widening of one without the other re-opens the
     * defect in whichever direction it was widened.
     *
     * THE NEGATIVE ROWS ARE NOT PADDING. A "collapse whenever in doubt" store would satisfy
     * every positive row while silently merging genuinely different addresses - the shopper
     * would then be granted a bypass for an address Loqate never authored, which is a wider
     * hole than the one being fixed. One row per matched field, so dropping any single field
     * from the projection fails here.
     *
     * @param array $first Loqate lookup record captured first.
     * @param array $second Loqate lookup record captured second.
     * @param bool $sameAddress Whether the two are the same address for bypass purposes.
     */
    #[DataProvider('capturedAddressPairProvider')]
    public function testTheStoreDeDuplicatesOnExactlyTheRelationTheBypassMatcherUses(
        array $first,
        array $second,
        bool $sameAddress
    ): void {
        // Half one: how many SLOTS the pair occupies in the store.
        $store = $this->createShopper();
        $this->captureRecord($store, $first);
        $this->captureRecord($store, $second);

        $this->assertSame(
            $sameAddress ? [$this->projection($second)] : [$this->projection($first), $this->projection($second)],
            $this->capturedProjections($store),
            $sameAddress
                ? 'These two captures are ONE address as far as Validator::checkForCapturedAddress() is '
                    . 'concerned, so they must occupy ONE slot - holding the newest of the two, because a '
                    . 'refreshed entry must not keep the age of the one it replaces. Two slots for one '
                    . 'address is how re-picking a single address fills a bounded store and evicts every '
                    . 'other address in it.'
                : 'These two captures differ in a field the bypass matcher compares, so they are two '
                    . 'different addresses and must keep their own slots. Collapsing them would silently '
                    . 'discard a bypass the shopper earned - and, worse, a de-duplication looser than the '
                    . 'matcher would let one captured address stand in for another.'
        );

        // Half two: whether the MATCHER agrees, asserted through the bypass itself rather
        // than on the signature, so it holds for whatever relation the matcher actually
        // applies. A fresh session, holding only the FIRST address.
        $matcher = $this->createShopper();
        $this->captureRecord($matcher, $first);

        $result = $matcher['validator']->verifyAddress($this->magentoShapeOf($second));

        $this->assertSame(
            $sameAddress ? 0 : 1,
            $this->apiCallCount($matcher),
            $sameAddress
                ? 'The store collapsed these two captures into one slot, so the matcher MUST also treat the '
                    . 'second as already captured and skip the billable verify. If it does not, the store is '
                    . 'de-duplicating more aggressively than the bypass it feeds and the shopper has just '
                    . 'lost a bypass they earned.'
                : 'The store gave these two captures separate slots, so the matcher must NOT hand the second '
                    . 'one the first one\'s bypass: it is a different address and Loqate has never judged it.'
        );
        $this->assertSame(
            ['error' => false],
            $result,
            'Either way the address is accepted here - by the bypass or by the passing stub verdict - so the '
            . 'call count above is what distinguishes the two, not the return value.'
        );
    }

    /**
     * Pairs of Loqate lookup records, and whether they are the same address for bypass
     * purposes.
     *
     * The positive rows are precisely the four ways byte comparison used to disagree with the
     * matcher, plus the '|' substitution normaliseSignatureValue() applies (documented on
     * that method as a deliberate, symmetric widening). The negative rows walk the five
     * fields capturedAddressSignature() actually projects, one at a time.
     *
     * @return array<string, array{0: array, 1: array, 2: bool}>
     */
    public static function capturedAddressPairProvider(): array
    {
        $base = self::baseLookupRecord();

        return [
            // ---- the same address: differences the matcher normalises or ignores ----
            'differing only in letter case' => [
                $base,
                array_merge($base, [
                    'Line1' => '1 hIGH sTREET',
                    'Line2' => 'fLAT 2',
                    'City' => 'lONDON',
                    'PostalCode' => 'sw1a 1aa',
                    'CountryIso2' => 'gb',
                ]),
                true,
            ],
            'differing only in whitespace' => [
                $base,
                array_merge($base, [
                    'Line1' => "  1   High \t Street  ",
                    'PostalCode' => ' SW1A  1AA ',
                    'City' => " London\n",
                ]),
                true,
            ],
            // The field capture.js rewrites from the SDK's ProvinceName on a bubbling change
            // event, which is why capturedAddressSignature() excludes it altogether.
            'differing only in the province name' => [
                $base,
                array_merge($base, ['ProvinceName' => 'London']),
                true,
            ],
            'differing only in an empty versus an absent second line' => [
                array_merge($base, ['Line2' => '']),
                array_merge($base, ['Line2' => null]),
                true,
            ],
            // '|' joins the signature parts, so normaliseSignatureValue() replaces it with a
            // space on BOTH sides of every comparison.
            'differing only by the signature separator character' => [
                $base,
                array_merge($base, ['Line1' => '1 High|Street']),
                true,
            ],

            // ---- different addresses: one row per projected field ----
            'a different first line' => [$base, array_merge($base, ['Line1' => '2 High Street']), false],
            'a different second line' => [$base, array_merge($base, ['Line2' => 'Flat 3']), false],
            'a different city' => [$base, array_merge($base, ['City' => 'Manchester']), false],
            'a different postcode' => [$base, array_merge($base, ['PostalCode' => 'SW1A 2AA']), false],
            'a different country' => [$base, array_merge($base, ['CountryIso2' => 'IE']), false],
        ];
    }

    /**
     * An entry written by an OLDER release, whose serialised form has no Address2 key at all,
     * must still collapse onto a capture of the same address with an empty second line.
     *
     * Not hypothetical, and not reachable through the writer: storeCapturedAddress() always
     * projects all six mapped fields, so only an entry left in the session by a previous
     * deployment - or by a truncated payload - can be missing one. It matters because the
     * matcher reads `$address['Address2'] ?? null`, so it already treats such an entry as
     * having an empty second line and grants the bypass on it; the store has to agree, or
     * that legacy entry occupies a slot forever while a duplicate of it accumulates
     * alongside. This also exercises the unserialize-then-project path
     * (Controller::capturedEntrySignature()) rather than the byte comparison, which cannot
     * see through a difference in serialised shape.
     */
    public function testALegacyEntryWithNoSecondLineAtAllCollapsesOntoTheSameAddress(): void
    {
        $shopper = $this->createShopper();
        $legacy = $this->projection(self::baseLookupRecord());
        unset($legacy['Address2']);
        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = [json_encode($legacy)];

        $this->captureRecord($shopper, array_merge(self::baseLookupRecord(), ['Line2' => '']));

        $this->assertSame(
            [$this->projection(array_merge(self::baseLookupRecord(), ['Line2' => '']))],
            $this->capturedProjections($shopper),
            'A stored entry with no Address2 key and a capture whose Line2 is empty are the same address to '
            . 'Validator::capturedAddressSignature(), which reads the field with ?? null - so they must share '
            . 'one slot, and the freshly captured entry must be the one that survives.'
        );
    }

    /**
     * The de-duplication must never collapse two entries that carry nothing identifiable.
     *
     * capturedAddressSignature() answers '' for such an address, and the matcher deliberately
     * refuses to match on '' - otherwise every empty-ish address would be granted every other
     * one's bypass. The store has to honour that same sentinel, which is why byte equality is
     * kept as the FIRST test inside isSameCapturedAddress(): it is the only thing that can
     * still collapse an exact duplicate of an unidentifiable entry, and it collapses nothing
     * else.
     */
    public function testTwoDifferentUnidentifiableCapturesAreNeverCollapsedOntoEachOther(): void
    {
        $empty = [
            'Line1' => '',
            'Line2' => '',
            'CountryIso2' => '',
            'PostalCode' => '',
            'City' => '',
            'ProvinceName' => 'Greater London',
        ];
        $shopper = $this->createShopper();

        $this->captureRecord($shopper, $empty);
        // Differs ONLY in ProvinceName, which the signature excludes - so both signatures are
        // '' and only the bytes tell them apart.
        $this->captureRecord($shopper, array_merge($empty, ['ProvinceName' => 'Kent']));

        $this->assertCount(
            2,
            $this->capturedStore($shopper),
            'Two captures whose signature is the "nothing identifiable" sentinel must not be collapsed onto '
            . 'each other: the matcher never matches on that sentinel, so treating them as the same address '
            . 'in the store would make the store disagree with the bypass in the one case the sentinel exists '
            . 'to keep out of every comparison.'
        );

        // ...while an EXACT duplicate of one of them still collapses, on bytes alone.
        $this->captureRecord($shopper, $empty);

        $this->assertCount(
            2,
            $this->capturedStore($shopper),
            'An exact byte-for-byte duplicate must still be de-duplicated even when its signature is empty: '
            . 'that is the one thing the byte comparison is retained for, and without it a shopper cycling an '
            . 'unidentifiable capture could still fill the store.'
        );
    }

    /**
     * The observable consequence of dropping the existing copy BEFORE the eviction loop
     * rather than after it: re-capturing an address while the store is FULL must not cost an
     * unrelated address its bypass.
     *
     * Without the unset, the store is still at the limit when the loop runs, so it shifts the
     * oldest entry out and then appends a DUPLICATE of the re-captured one - the shopper pays
     * a billable verify for an address they never touched, and the store holds the same
     * address twice. The re-captured address must not be the oldest one for this to be
     * visible, since refreshing the front entry evicts and immediately re-appends that same
     * entry, which harms nothing.
     */
    public function testRecapturingWhileTheStoreIsFullDoesNotCostAnUnrelatedAddressItsBypass(): void
    {
        $limit = $this->capturedLimit();
        $shopper = $this->createShopper();

        for ($i = 1; $i <= $limit; $i++) {
            $this->captureAddress($shopper, $i);
        }

        // Refresh the SECOND oldest entry: the oldest (#1) is the one a stray eviction takes.
        $this->captureAddress($shopper, 2);

        $this->assertCount(
            $limit,
            $this->capturedStore($shopper),
            'Refreshing an entry that is already present replaces it, so the store must stay exactly full.'
        );

        $shopper['validator']->verifyAddress($this->magentoAddress(1));

        $this->assertSame(
            0,
            $this->apiCallCount($shopper),
            'Re-capturing an address while the store is full must not evict an UNRELATED address: that address '
            . 'would then be re-verified - and re-billed - for no reason.'
        );

        // ...and the refreshed entry really is the newest, so the NEXT capture takes #1
        // rather than it.
        $this->captureAddress($shopper, $limit + 1);

        $this->assertSame(
            $this->expectedStreets(array_merge(range(3, $limit), [2, $limit + 1])),
            $this->capturedStreets($shopper),
            'The refreshed entry must have been moved to the newest position, so the next eviction takes the '
            . 'genuinely oldest address instead.'
        );
    }

    /**
     * The store must degrade rather than throw when the session attribute holds something
     * that is not a list of addresses.
     *
     * Reachable in more ways than a corrupted payload: this attribute is a bare session key
     * shared with every other module on the installation, ShopperScopedSession deliberately
     * FLUSHES it by writing null (see that class for why null rather than an unset), and
     * sessions are restored from storage that can truncate. A fatal here would happen inside
     * a Capture retrieve request, i.e. while the shopper is picking their address, so the
     * only acceptable behaviour is to start a fresh list.
     *
     * @param mixed $corrupt Value found in the session attribute instead of a list.
     */
    #[DataProvider('corruptStoreValueProvider')]
    public function testANonArrayValueInTheSessionAttributeIsReplacedRatherThanAppendedTo($corrupt): void
    {
        $shopper = $this->createShopper();
        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = $corrupt;

        $this->captureAddress($shopper, 1);

        $this->assertSame(
            $this->expectedStreets([1]),
            $this->capturedStreets($shopper),
            'An unusable value in the session attribute must be replaced by a fresh list holding just the '
            . 'address being captured.'
        );

        $result = $shopper['validator']->verifyAddress($this->magentoAddress(1));

        $this->assertSame(['error' => false], $result);
        $this->assertSame(
            0,
            $this->apiCallCount($shopper),
            'The address captured over the corrupt value must still be granted its bypass: recovering from '
            . 'the corruption may not silently cost the shopper a billable verify.'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function corruptStoreValueProvider(): array
    {
        return [
            // What ShopperScopedSession itself writes when it flushes the store, so this case
            // is not hypothetical: it is reached on the first capture after a login.
            'null left by a shopper-ownership flush' => [null],
            'a truncated string payload' => ['a:1:{i:0;s:5:"parti'],
            'an empty string' => [''],
            'an integer' => [42],
            'a boolean' => [true],
            'an object' => [new stdClass()],
        ];
    }

    /**
     * A foreign ELEMENT inside the store - as opposed to a foreign value in place of the whole
     * attribute - must be stepped over: it neither breaks the de-duplication nor gets pruned
     * by it.
     *
     * WHY THIS IS A SEPARATE CASE FROM
     * testANonArrayValueInTheSessionAttributeIsReplacedRatherThanAppendedTo(). That test
     * corrupts the WHOLE attribute, so it is answered by the `!is_array($capturedAddresses)`
     * check at the top of storeCapturedAddress() and never reaches the de-duplication at all.
     * The array itself being a list of addresses says nothing about its ELEMENTS: this is a
     * bare session key shared with every other module on the installation, and appending to a
     * list somebody else populated is exactly as reachable as replacing it. Until the
     * `!is_string($stored)` guard existed, an element of the wrong type reached
     * Controller::capturedEntrySignature(), whose parameter is declared `string` - so an array
     * element was a TypeError raised inside a Capture retrieve request, i.e. while the shopper
     * was picking their address (LOQ-16978 review).
     *
     * BOTH HALVES ARE ASSERTED, and the second is the one a "does not fatal" test would miss.
     * Skipping a foreign element must mean "this is not the address being stored", NOT "this
     * is the address being stored": treating it as a match would DELETE it - the store would
     * silently prune another module's data on every capture, which is a worse outcome than the
     * TypeError, because nothing would report it. Asserting the exact resulting array pins
     * both directions at once, plus the position: the foreign elements keep their order, the
     * duplicate entry is gone, and the freshly captured one is last.
     *
     * @param array $seed Session attribute before the capture, with
     *                    self::THE_ADDRESS_BEING_CAPTURED marking the entry to be collapsed.
     */
    #[DataProvider('mixedStoreProvider')]
    public function testAForeignElementInsideTheStoreIsSteppedOverRatherThanPrunedOrMatched(array $seed): void
    {
        $shopper = $this->createShopper();
        $record = self::baseLookupRecord();
        $entry = json_encode($this->projection($record));

        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = array_map(
            static fn ($element) => $element === self::THE_ADDRESS_BEING_CAPTURED ? $entry : $element,
            $seed
        );

        $expected = array_values(array_filter(
            $seed,
            static fn ($element): bool => $element !== self::THE_ADDRESS_BEING_CAPTURED
        ));
        $expected[] = $entry;

        $this->captureRecord($shopper, $record);

        $this->assertSame(
            $expected,
            $this->capturedStore($shopper),
            'Every element of this store that this class did not write must come out of a capture EXACTLY as '
            . 'it went in, in the same order: pruning another module\'s data is not this writer\'s job, and '
            . 'the alternative failure - counting a foreign element as the address being stored - would delete '
            . 'it silently on every capture. Meanwhile the entry this class DID write for the same address '
            . 'must still have been collapsed and re-appended last, so a foreign neighbour cannot cost the '
            . 'store its de-duplication and let one address fill a bounded store.'
        );
    }

    /**
     * Elements a list of captured addresses can hold that this class never wrote, and where
     * they sit relative to the entry the capture is going to collapse.
     *
     * One row per type that reaches isSameCapturedAddress() differently: the scalars are
     * harmless to `===` but fatal to a `string` parameter, the array and the object are fatal
     * to both, and null is what a half-written entry leaves. The positions vary so that the
     * re-indexing after array_filter() is exercised with survivors before, after and on both
     * sides of the hole.
     *
     * @return array<string, array{0: array}>
     */
    public static function mixedStoreProvider(): array
    {
        return [
            'an integer before the stored address' => [[42, self::THE_ADDRESS_BEING_CAPTURED]],
            'a nested array after the stored address' => [[self::THE_ADDRESS_BEING_CAPTURED, ['nested']]],
            'a null on either side of the stored address' => [[null, self::THE_ADDRESS_BEING_CAPTURED, null]],
            'a boolean and a float around the stored address' => [
                [true, self::THE_ADDRESS_BEING_CAPTURED, 1.5],
            ],
            'an object before the stored address' => [[new stdClass(), self::THE_ADDRESS_BEING_CAPTURED]],
            // The whole list from the LOQ-16978 review, in one row: every foreign type at once
            // with the entry to be collapsed last, so the re-index leaves three survivors and
            // the append lands behind all of them.
            'the full mixed list' => [[42, ['nested'], null, self::THE_ADDRESS_BEING_CAPTURED]],
        ];
    }

    /**
     * An entry that IS a string but cannot be read back as an address must cost a
     * de-duplication and nothing more - specifically, it must not escape as an exception from
     * inside a Capture retrieve request.
     *
     * WHAT MAKES THIS TEST POSSIBLE, and why it did not exist before. The production
     * serializer, Magento\Framework\Serialize\Serializer\Json, reports a payload it cannot
     * decode by THROWING \InvalidArgumentException - it does not answer null. That is why
     * Controller::capturedEntrySignature() wraps the call in a try/catch at all, and it is why
     * the doubles in this file now fail the same way (see createSerializerDouble()): against a
     * `json_decode()`-shaped double that answers null on garbage, this catch block is
     * unreachable, so removing it left the whole suite green while a truncated session payload
     * became a fatal in front of a shopper.
     *
     * A truncated payload is the reachable case: the attribute is written on every request and
     * restored from storage - Redis, the database, files - that can and does truncate, which
     * is exactly why corruptStoreValueProvider() carries the same shape for the whole
     * attribute. The unreadable entry must SURVIVE for the same reason a foreign element does:
     * pruning is not this writer's job, and the matcher already steps over it.
     */
    public function testAnUnreadableEntryInTheStoreCostsADeDuplicationRatherThanAFatal(): void
    {
        $shopper = $this->createShopper();
        // A truncated serialised address: a genuine string, so it gets past the is_string()
        // guard and is actually handed to the serializer, and not byte-equal to the entry
        // being stored, so the signature comparison - the only path that unserialises - is the
        // one that has to answer for it.
        $truncated = '{"Address1":"1 High Str';
        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = [$truncated];

        $record = self::baseLookupRecord();
        $this->captureRecord($shopper, $record);

        $this->assertSame(
            [$truncated, json_encode($this->projection($record))],
            $this->capturedStore($shopper),
            'An entry the serializer refuses to decode must cost this capture nothing but the chance to '
            . 'de-duplicate against it: the exception must be caught, the unreadable entry must be left where '
            . 'it is, and the address being captured must be appended as usual. Letting the exception out '
            . 'would abort the Capture retrieve request the shopper is in the middle of, over a session value '
            . 'this class is not even the author of.'
        );

        // ...and the same capture repeated still de-duplicates against ITSELF, so the
        // unreadable neighbour has not disabled the de-duplication for the rest of the list.
        $this->captureRecord($shopper, $record);

        $this->assertCount(
            2,
            $this->capturedStore($shopper),
            'The unreadable entry must not stop the readable ones being de-duplicated: an entry that throws '
            . 'has to be skipped, not treated as the end of the store.'
        );
    }

    /**
     * A foreign ELEMENT in the store must cost the READERS a bypass and nothing more - on BOTH
     * of them.
     *
     * THE OTHER SIDE OF testAForeignElementInsideTheStoreIsSteppedOverRatherThanPrunedOrMatched().
     * That test covers the WRITER, Helper\Controller::isSameCapturedAddress(), which guards its
     * own `string` parameter with is_string(). The same session attribute is READ by
     * Validator::checkForCapturedAddress(), which hands each element to the serializer - and
     * the production serializer, Magento\Framework\Serialize\Serializer\Json::unserialize(),
     * passes its argument straight to json_decode(), whose first parameter is declared
     * `string $json`. An array or object element is therefore a TypeError, which is an \Error
     * and NOT covered by that method's `catch (\InvalidArgumentException)` - so it escaped as a
     * fatal from inside a checkout submission until the mirror is_string() guard was added
     * (LOQ-16978). The double this file uses fails exactly that way; see
     * createSerializerDouble().
     *
     * THE DEGRADATION CONTRACT, asserted rather than assumed: a store this class cannot read is
     * allowed to cost a MISSED BYPASS - the address is verified, which means BILLED - and is
     * never allowed to cost an exception. Both halves are needed, because "did not blow up" is
     * also satisfied by a reader that answered "captured" and skipped the verify, and "was
     * billed" is also satisfied by a reader that threw before it ever got there. So the test
     * demands the billable request AND the ordinary accepted verdict that follows it.
     *
     * @param mixed $element Element found in the store that this module never wrote.
     * @param string $reader Which reader of the store is under test.
     */
    #[DataProvider('foreignStoreElementAndReaderProvider')]
    public function testAForeignElementInTheStoreCostsABypassRatherThanAFatalInEitherReader(
        $element,
        string $reader
    ): void {
        $shopper = $this->createShopper();
        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = [$element];

        $result = $this->verifyThrough($shopper, $reader, $this->magentoAddress(self::ADDRESS_UNDER_TEST));

        $this->assertSame(
            $this->acceptedVerdict($reader),
            $result,
            $reader . '() must answer an ordinary verdict over a store element it cannot read: the shopper is '
            . 'in the middle of a checkout submission, so the only acceptable cost of somebody else\'s data in '
            . 'this shared session key is the bypass itself.'
        );
        $this->assertSame(
            $this->expectedStreets([self::ADDRESS_UNDER_TEST]),
            $this->billedStreets($shopper),
            $reader . '() must have gone on to BILL this address. Asserting the verdict alone would not say '
            . 'that: the accepted verdict is byte-identical to the one the bypass returns, so a reader that '
            . 'wrongly matched the unreadable element would satisfy it while silently waving an unverified '
            . 'address through.'
        );
    }

    /**
     * ...and a foreign element must be STEPPED OVER, not treated as the end of the store: a
     * genuine entry sitting behind one still has to be found, by both readers.
     *
     * Without this, the guard could be satisfied by a `break` or an early `return false`, which
     * would turn one foreign element - in a session key shared with every other module on the
     * installation - into a lost bypass for EVERY address captured after it, i.e. into a
     * re-billing of the whole store. This is the reader's counterpart of the "must still
     * de-duplicate" half of
     * testAnUnreadableEntryInTheStoreCostsADeDuplicationRatherThanAFatal().
     *
     * @param mixed $element Element found in the store that this module never wrote.
     * @param string $reader Which reader of the store is under test.
     */
    #[DataProvider('foreignStoreElementAndReaderProvider')]
    public function testAForeignElementDoesNotHideACapturedAddressBehindItFromEitherReader(
        $element,
        string $reader
    ): void {
        $shopper = $this->createShopper();
        $this->captureAddress($shopper, self::ADDRESS_UNDER_TEST);

        // The foreign element FIRST, so the genuine entry is only reachable by stepping over it.
        $store = $this->capturedStore($shopper);
        array_unshift($store, $element);
        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = $store;

        $result = $this->verifyThrough($shopper, $reader, $this->magentoAddress(self::ADDRESS_UNDER_TEST));

        $this->assertSame(
            $this->acceptedVerdict($reader),
            $result,
            $reader . '() must still accept an address this shopper captured from the Loqate lookup.'
        );
        $this->assertSame(
            [],
            $this->billedStreets($shopper),
            'An element ' . $reader . '() cannot read must be SKIPPED, not treated as the end of the store: '
            . 'the captured address behind it must keep its bypass, or one foreign element in a shared session '
            . 'key re-bills every address the shopper captured after it.'
        );
    }

    /**
     * Elements a captured-address store can hold that this module never wrote, crossed with the
     * two readers of that store.
     *
     * ONE ROW PER TYPE THE READER REACHES DIFFERENTLY, mirroring mixedStoreProvider() so the
     * writer and the readers are pinned against the same values - they read the same session
     * key and must survive the same content. Where they differ is which of these is FATAL: the
     * writer's `string` parameter rejects every non-string, whereas the reader hands the element
     * to the serializer, so the array and the object are TypeErrors from json_decode() while
     * the scalars are coerced to strings and merely fail to decode, and null is rejected by the
     * serializer itself as an \InvalidArgumentException. The harmless rows are kept because a
     * guard that admitted them would still be wrong, and because they are what proves the fatal
     * rows are fatal for the reason claimed.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function foreignStoreElementAndReaderProvider(): array
    {
        $elements = [
            // json_decode() TypeError: neither is coercible to string, so these two are the
            // rows that were fatal in production before the is_string() guard.
            'a nested array' => [['Address1' => '1 Test Street']],
            'an object' => [new stdClass()],
            // Coerced to '42' / '1' / '1.5', decoded to a non-array, stepped over.
            'an integer' => [42],
            'a boolean' => [true],
            'a float' => [1.5],
            // What a half-written entry leaves; rejected by the serializer itself.
            'a null' => [null],
        ];

        return self::crossWithReaders($elements);
    }

    /**
     * The WHOLE attribute being something other than a list must cost a bypass too, on both
     * readers - and this guard has to sit BEFORE the loop, where neither caller's own check can
     * substitute for it.
     *
     * WHY IT CANNOT LIVE IN THE CALLERS. Both of them test the attribute for TRUTHINESS only -
     * `if ($checkForCaptured && ($storedAddresses = ...))` in verifyAddress() and
     * `if ($storedAddresses && ...)` in verifyMultipleAddresses() - so a truthy non-array
     * reached the foreach(). Iterating a scalar is an E_WARNING, and Magento's error handler
     * turns every reported PHP error into an exception (Magento\Framework\App\ErrorHandler,
     * registered by Bootstrap::run() with error_reporting(E_ALL)), so in a live store that
     * warning IS the fatal - which is why these tests install the same handler; see
     * verifyThrough().
     *
     * WHY THE ATTRIBUTE IS NOT A LIST, in practice: it is a bare session key shared with every
     * other module on the installation, ShopperScopedSession deliberately writes null into it
     * to flush it, and sessions are restored from storage that can truncate - the same reasons
     * spelled out on corruptStoreValueProvider(), which pins the WRITER against this same set.
     * Only truthy values appear here, because a falsy one never reaches the reader at all.
     *
     * @param mixed $malformed Value found in the session attribute instead of a list.
     * @param string $reader Which reader of the store is under test.
     */
    #[DataProvider('malformedStoreAndReaderProvider')]
    public function testATruthyNonListStoreCostsABypassRatherThanAFatalInEitherReader(
        $malformed,
        string $reader
    ): void {
        $shopper = $this->createShopper();
        $this->captureAddress($shopper, self::ADDRESS_UNDER_TEST);
        $entry = $this->capturedStore($shopper)[0];

        $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = $this->materialise($malformed, $entry);

        $result = $this->verifyThrough($shopper, $reader, $this->magentoAddress(self::ADDRESS_UNDER_TEST));

        $this->assertSame(
            $this->acceptedVerdict($reader),
            $result,
            $reader . '() must answer an ordinary verdict when the captured-address attribute is not a list at '
            . 'all: iterating it is an E_WARNING, and under Magento\'s error handler that warning aborts the '
            . 'request the shopper is in the middle of.'
        );
        $this->assertSame(
            $this->expectedStreets([self::ADDRESS_UNDER_TEST]),
            $this->billedStreets($shopper),
            'A store that is not a list of entries this module wrote grants NO bypass: ' . $reader . '() must '
            . 'bill the address instead. The two rows that carry a genuine entry are the point - reaching into '
            . 'a value of the wrong shape to honour it is exactly the "it happened to work" behaviour that put '
            . 'a foreach() over a scalar in front of a shopper.'
        );
    }

    /**
     * Truthy values the captured-address attribute can hold instead of a list, crossed with the
     * two readers.
     *
     * The last two rows are placeholders resolved in the test body (see materialise()), and
     * they are the strongest ones: both carry a GENUINE entry for the address under test, one
     * bare and one behind an object property. A reader without the is_array() guard answers
     * them differently from each other - the bare string warns and, under Magento, throws,
     * while the object iterates cleanly and grants a bypass off a store shape this module never
     * wrote - so between them they pin the guard from both sides.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function malformedStoreAndReaderProvider(): array
    {
        $malformed = [
            'a truncated string payload' => ['a:1:{i:0;s:5:"parti'],
            'an integer' => [42],
            'a boolean' => [true],
            'a float' => [1.5],
            'a bare object' => [new stdClass()],
            'a genuine entry stored bare instead of in a list' => [self::THE_ENTRY_UNWRAPPED],
            'an object whose property holds a genuine entry' => [self::AN_OBJECT_AROUND_THE_ENTRY],
        ];

        return self::crossWithReaders($malformed);
    }

    /**
     * The store must not be reachable except through the shopper-ownership guard.
     *
     * Helper\Controller is handed the raw Magento\Customer\Model\Session by DI and wraps it
     * in a ShopperScopedSession; if it ALSO kept the raw session in a property, a later edit
     * could read or write captured_addresses directly and silently skip the identity check
     * that stops shopper B inheriting shopper A's verify bypasses (LOQ-16978). Asserted on
     * the constructed object rather than by reading the source, so it holds however the
     * property is declared.
     */
    public function testTheStoreIsReachedOnlyThroughTheShopperOwnershipGuard(): void
    {
        $shopper = $this->createShopper();
        $held = $this->propertyValues($shopper['controller']);

        foreach ($held as $name => $value) {
            $this->assertNotInstanceOf(
                Session::class,
                $value,
                sprintf(
                    'Helper\Controller::$%s holds the raw customer session. The captured-address store must '
                    . 'be reachable only through ShopperScopedSession, or a future edit can read and write it '
                    . 'without the shopper-ownership check that flushes it on a login or a logout.',
                    $name
                )
            );
        }

        $guards = array_filter($held, static fn ($value): bool => $value instanceof ShopperScopedSession);
        $this->assertCount(
            1,
            $guards,
            'Helper\Controller must hold exactly one ShopperScopedSession: that is the seam every access to '
            . 'the captured-address store has to go through.'
        );
    }

    /**
     * Capture one distinct address, exactly as Controller::retrieve() does with a Loqate
     * lookup record.
     *
     * storeCapturedAddress() is protected, so it is invoked by reflection rather than through
     * retrieve(): retrieve() would additionally need the Capture connector swapped out and the
     * request/result factory driven, none of which this store's behaviour depends on.
     *
     * @param array $shopper Harness from createShopper().
     * @param int $index Which distinct address to capture.
     */
    private function captureAddress(array $shopper, int $index): void
    {
        if (!method_exists(Controller::class, 'storeCapturedAddress')) {
            $this->fail(
                'Helper\Controller::storeCapturedAddress() does not exist: the captured-address store must '
                . 'have exactly one writer, so its bound and its de-duplication live in one place.'
            );
        }

        $this->captureRecord($shopper, $this->lookupRecord($index));
    }

    /**
     * Capture one arbitrary Loqate lookup record, for the tests that care about the exact
     * field values rather than about "the Nth distinct address".
     *
     * @param array $shopper Harness from createShopper().
     * @param array $record A Loqate Capture "retrieve" record.
     */
    private function captureRecord(array $shopper, array $record): void
    {
        if (!method_exists(Controller::class, 'storeCapturedAddress')) {
            $this->fail(
                'Helper\Controller::storeCapturedAddress() does not exist: the captured-address store must '
                . 'have exactly one writer, so its bound and its de-duplication live in one place.'
            );
        }

        $method = new ReflectionMethod(Controller::class, 'storeCapturedAddress');
        $method->setAccessible(true);
        $method->invoke($shopper['controller'], $record);
    }

    /**
     * A single, fully populated Loqate lookup record the de-duplication pairs are varied
     * from.
     *
     * Every mapped source field is populated because storeCapturedAddress() projects them all
     * unguarded, and Line2 is non-empty so that a genuinely different second line is a
     * variation this base can express.
     *
     * @return array<string, string>
     */
    private static function baseLookupRecord(): array
    {
        return [
            'Line1' => '1 High Street',
            'Line2' => 'Flat 2',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ];
    }

    /**
     * A lookup record projected exactly as storeCapturedAddress() stores it - through
     * Validator::ADDRESS_CAPTURE_MAPPING, in that constant's key order, so the result can be
     * compared with assertSame() against what the store actually holds.
     *
     * @param array $record A Loqate Capture "retrieve" record.
     * @return array<string, mixed>
     */
    private function projection(array $record): array
    {
        $projected = [];
        foreach (Validator::ADDRESS_CAPTURE_MAPPING as $stored => $source) {
            $projected[$stored] = $record[$source] ?? null;
        }

        return $projected;
    }

    /**
     * The same lookup record in the Magento shape checkout submits, so it reaches
     * Validator::checkForCapturedAddress() through parseAddress() exactly as a real
     * submission would - including parseAddress()'s own handling of an empty or absent second
     * line, which extractStreetLines() drops.
     *
     * @param array $record A Loqate Capture "retrieve" record.
     * @return array
     */
    private function magentoShapeOf(array $record): array
    {
        return [
            'street' => [$record['Line1'] ?? null, $record['Line2'] ?? null],
            'city' => $record['City'] ?? null,
            'region' => $record['ProvinceName'] ?? null,
            'postcode' => $record['PostalCode'] ?? null,
            'country_id' => $record['CountryIso2'] ?? null,
        ];
    }

    /**
     * The store's entries decoded back into the projected address arrays they hold, in stored
     * order - so a de-duplication failure reports which ADDRESSES are in the store rather
     * than a diff of JSON blobs.
     *
     * @param array $shopper Harness from createShopper().
     * @return array<int, mixed>
     */
    private function capturedProjections(array $shopper): array
    {
        $decoded = [];
        foreach ($this->capturedStore($shopper) as $entry) {
            $decoded[] = json_decode((string)$entry, true);
        }

        return $decoded;
    }

    /**
     * A Loqate Capture "retrieve" record, in the shape ADDRESS_CAPTURE_MAPPING reads.
     *
     * Every mapped source field is populated, because storeCapturedAddress() projects them
     * all unguarded - a fixture missing one would raise a PHP warning rather than test the
     * store.
     *
     * @param int $index Distinguishes one captured address from another.
     * @return array<string, string>
     */
    private function lookupRecord(int $index): array
    {
        return [
            'Line1' => $index . ' Test Street',
            'Line2' => '',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A ' . $index . 'AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ];
    }

    /**
     * The same address as lookupRecord(), in the Magento shape checkout submits, so that
     * Validator::parseAddress() projects it onto the identical captured-address signature.
     *
     * @param int $index Distinguishes one address from another.
     * @return array
     */
    private function magentoAddress(int $index): array
    {
        return [
            'street' => [$index . ' Test Street'],
            'city' => 'London',
            'region' => 'Greater London',
            'postcode' => 'SW1A ' . $index . 'AA',
            'country_id' => 'GB',
        ];
    }

    /**
     * The captured-address store as currently held in the session.
     *
     * @param array $shopper Harness from createShopper().
     * @return array
     */
    private function capturedStore(array $shopper): array
    {
        $store = $shopper['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    /**
     * The store rendered as the street lines it holds, in stored order - a readable stand-in
     * for the serialised entries, so an eviction-order failure reports which addresses are
     * actually in the store rather than a diff of JSON blobs.
     *
     * @param array $shopper Harness from createShopper().
     * @return string[]
     */
    private function capturedStreets(array $shopper): array
    {
        $streets = [];
        foreach ($this->capturedStore($shopper) as $entry) {
            $decoded = json_decode((string)$entry, true);
            $streets[] = is_array($decoded) ? (string)($decoded['Address1'] ?? '') : '(unreadable)';
        }

        return $streets;
    }

    /**
     * The street lines the given capture indexes are expected to leave, in order.
     *
     * @param int[] $indexes Capture indexes, oldest first.
     * @return string[]
     */
    private function expectedStreets(array $indexes): array
    {
        return array_map(static fn (int $index): string => $index . ' Test Street', array_values($indexes));
    }

    /** Number of billable Loqate Cleansing requests the shopper's Validator has issued. */
    private function apiCallCount(array $shopper): int
    {
        return count($shopper['requests']);
    }

    /**
     * The street lines of every address actually SENT to the billable Cleansing API, in the
     * order they were sent, across every request.
     *
     * Addresses, not requests, because the Cleansing API is billed PER ADDRESS (see the billing
     * note on Validator::verifyMultipleAddresses()) - so this is the invoice, and it is the
     * same measure for the single-address reader, which sends one address per request, and for
     * the batch one, which sends several. Reporting the STREETS rather than a count is what
     * makes a failure say WHICH address lost or gained its bypass.
     *
     * @param array $shopper Harness from createShopper().
     * @return string[]
     */
    private function billedStreets(array $shopper): array
    {
        $streets = [];
        foreach ($shopper['requests'] as $request) {
            foreach ($request['Addresses'] ?? [] as $address) {
                $streets[] = (string)($address['Address1'] ?? '');
            }
        }

        return $streets;
    }

    /**
     * Run one address through one of the two readers of the captured-address store, under the
     * error handler a live Magento installation has installed, and report a reader-specific
     * failure if anything escapes.
     *
     * WHY THE ERROR HANDLER. Magento\Framework\App\ErrorHandler, registered by
     * Bootstrap::run(), THROWS on every PHP error that error_reporting() reports, and
     * app/bootstrap.php sets error_reporting(E_ALL) - so in a live store an E_WARNING such as
     * "foreach() argument must be of type array|object" is not a log line, it is an aborted
     * request in front of a shopper. Reproducing that here is what makes the is_array() guard
     * testable at all: with PHP's default handler the warning is emitted and execution
     * continues, so a test could only assert the guard's SIDE EFFECTS and would pass against a
     * build that fatals in production.
     *
     * WHY $this->fail() RATHER THAN LETTING IT PROPAGATE: an escaping Throwable is the defect
     * itself, so it is reported as a failed EXPECTATION naming the reader, the exception class
     * and the line - not as an errored test that reads like the harness broke.
     *
     * The batch reader is given a ONE-address batch deliberately: createShopper()'s connector
     * answers exactly one response row, and verifyMultipleAddresses() returns false unless the
     * row count matches the number of addresses sent, so a longer batch would fail for a reason
     * that has nothing to do with the captured-address store.
     *
     * @param array $shopper Harness from createShopper().
     * @param string $reader self::READER_SINGLE or self::READER_BATCH.
     * @param array $address The address to verify, in the Magento shape checkout submits.
     * @return mixed Whatever the reader answered.
     */
    private function verifyThrough(array $shopper, string $reader, array $address)
    {
        $validator = $shopper['validator'];
        $call = match ($reader) {
            self::READER_SINGLE => static fn () => $validator->verifyAddress($address),
            self::READER_BATCH => static fn () => $validator->verifyMultipleAddresses([$address]),
            default => null,
        };

        if ($call === null) {
            $this->fail(sprintf('Unknown captured-address store reader "%s".', $reader));
        }

        // BOTH HALVES OF app/bootstrap.php, and the error_reporting() call is NOT optional
        // here. PHP invokes a user error handler for every error regardless of
        // error_reporting(), so it is the handler's own error_reporting() test - Magento's
        // included - that decides whether the error is promoted, and PHPUnit runs tests with
        // error_reporting() set to 245: E_WARNING, E_NOTICE and E_DEPRECATED are all excluded.
        // Installing the handler alone therefore reproduced Magento's handler faithfully but
        // Magento's ENVIRONMENT not at all, and every foreach()-over-a-scalar warning was
        // handed straight back to PHP - which is precisely the "degrades to a skipped loop"
        // reading that makes a live store's fatal invisible to a test.
        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) {
            // Exactly Magento\Framework\App\ErrorHandler::handler(): a suppressed or unreported
            // error is handed back to PHP, everything else becomes an exception.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $call();
        } catch (\Throwable $escaped) {
            $this->fail(sprintf(
                'Validator::%s() let a malformed captured-address store escape as %s: "%s" (%s:%d). A store '
                . 'this module cannot read may cost the shopper a BYPASS - the address is verified and billed '
                . 'again - but never a fatal: this runs inside a checkout submission, over a bare session key '
                . 'shared with every other module on the installation.',
                $reader,
                get_class($escaped),
                $escaped->getMessage(),
                $escaped->getFile(),
                $escaped->getLine()
            ));
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }
    }

    /**
     * What each reader answers for an address it ACCEPTS, whether it was bypassed or billed.
     *
     * The two shapes are deliberately different because the readers' contracts are: the
     * single-address reader answers the ['error' => false] envelope its checkout callers read,
     * the batch reader answers one boolean verdict per input key, under that key - the
     * LOQ-16977 return shape. Note that the accepted verdict is IDENTICAL whether the address
     * was bypassed or verified, which is precisely why every test here pairs it with
     * billedStreets().
     *
     * @param string $reader self::READER_SINGLE or self::READER_BATCH.
     * @return array
     */
    private function acceptedVerdict(string $reader): array
    {
        return $reader === self::READER_BATCH ? [0 => true] : ['error' => false];
    }

    /**
     * Resolve a malformedStoreAndReaderProvider() placeholder into the value the test actually
     * puts in the session attribute.
     *
     * @param mixed $malformed A provider value, possibly a placeholder.
     * @param string $entry The entry Controller::storeCapturedAddress() wrote for the address
     *                      under test.
     * @return mixed
     */
    private function materialise($malformed, string $entry)
    {
        if ($malformed === self::THE_ENTRY_UNWRAPPED) {
            return $entry;
        }

        if ($malformed === self::AN_OBJECT_AROUND_THE_ENTRY) {
            return (object)['entry' => $entry];
        }

        return $malformed;
    }

    /**
     * Cross a table of single-argument provider rows with the two readers of the store, naming
     * each row after both.
     *
     * The cross product is built here rather than written out because the requirement is
     * literally "every one of these values, through EVERY reader": the guards under test live
     * in the shared matcher, and it was the existence of a second caller with its own truthiness
     * check that decided where they had to go.
     *
     * @param array<string, array{0: mixed}> $rows
     * @return array<string, array{0: mixed, 1: string}>
     */
    private static function crossWithReaders(array $rows): array
    {
        $crossed = [];
        foreach ($rows as $name => $arguments) {
            foreach ([self::READER_SINGLE, self::READER_BATCH] as $reader) {
                $crossed[$name . ', read by ' . $reader . '()'] = [$arguments[0], $reader];
            }
        }

        return $crossed;
    }

    /**
     * The documented bound, read from the production constant so the tests cannot drift from
     * it - and so removing the bound fails loudly instead of silently skipping these tests.
     */
    private function capturedLimit(): int
    {
        if (!defined(Controller::class . '::CAPTURED_ADDRESSES_LIMIT')) {
            $this->fail(
                'Controller::CAPTURED_ADDRESSES_LIMIT is not defined: the captured-address store must be '
                . 'bounded, or one session can grow it without limit for as long as the shopper keeps picking '
                . 'addresses from the lookup.'
            );
        }

        return (int)constant(Controller::class . '::CAPTURED_ADDRESSES_LIMIT');
    }

    /**
     * The single-address verdict cache's bound, the neighbour this store's bound must match.
     */
    private function verifyCacheLimit(): int
    {
        if (!defined(Validator::class . '::VERIFY_CACHE_LIMIT')) {
            $this->fail(
                'Validator::VERIFY_CACHE_LIMIT is not defined: the verdict cache consulted alongside this '
                . 'store must be bounded too, and this store\'s bound is defined as being the same as it.'
            );
        }

        return (int)constant(Validator::class . '::VERIFY_CACHE_LIMIT');
    }

    /**
     * The BATCH verdict cache's bound - the one neighbour that is deliberately LARGER, so
     * "consistent with its neighbours" cannot be satisfied by making all three equal.
     */
    private function batchCacheLimit(): int
    {
        if (!defined(Validator::class . '::BATCH_VERIFY_CACHE_LIMIT')) {
            $this->fail(
                'Validator::BATCH_VERIFY_CACHE_LIMIT is not defined: the batch verdict cache must be bounded '
                . 'too, and this store\'s bound is defined by contrast with it.'
            );
        }

        return (int)constant(Validator::class . '::BATCH_VERIFY_CACHE_LIMIT');
    }

    /**
     * Every property an object holds, including private and inherited ones, by name.
     *
     * @param object $object
     * @return array<string, mixed>
     */
    private function propertyValues(object $object): array
    {
        $values = [];
        for ($class = new ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                $property->setAccessible(true);
                if (!array_key_exists($property->getName(), $values) && $property->isInitialized($object)) {
                    $values[$property->getName()] = $property->getValue($object);
                }
            }
        }

        return $values;
    }
}
