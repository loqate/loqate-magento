<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Controller;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\ShopperScopedAddressStores;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Test\Support\ProductionSerializerDouble;
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
use ReflectionProperty;
use stdClass;

/**
 * Unit tests for Helper\ShopperScopedAddressStores: the one seam through which this module
 * reaches the three session attributes that can make an address SKIP a billable Loqate
 * verify, and the guard that keeps them belonging to ONE shopper (LOQ-16978).
 *
 * THE DEFECT. All three stores - Controller::CAPTURED_ADDRESSES_SESSION_KEY (the Capture
 * bypass), Validator::VERIFY_CACHE_SESSION_KEY and
 * Validator::BATCH_VERIFY_CACHE_SESSION_KEY (the LOQ-16969/LOQ-16976 verdict caches) -
 * live in the customer session, and a PHP session OUTLIVES a login: Magento calls
 * session_regenerate_id() on login and on logout, which changes the session ID while
 * PRESERVING every value in $_SESSION. Nothing in Magento clears a third-party session
 * attribute on an identity change. So on a shared browser - a family device, a public
 * terminal, a click-and-collect kiosk - shopper B could inherit shopper A's bypasses and
 * check out an address that was never verified against B's own submission. Every entry in
 * these stores is a licence to skip a verification, which is why they share a lifetime and
 * are flushed TOGETHER: flushing two of the three still leaves the third granting B a
 * bypass A earned.
 *
 * WHAT IS PINNED HERE, and in which direction:
 *  - the flush fires on EVERY identity change, in all three directions a browser can take
 *    it - guest to logged in, one customer straight to another, logged in back to guest -
 *    see testEveryIdentityChangeFlushesEveryShopperScopedStore(), and it fires whichever of
 *    the stores is touched first, see
 *    testTouchingAnyOneStoreAfterAnIdentityChangeFlushesAllOfThem();
 *  - it does NOT fire for the same shopper, which is just as load-bearing: a guard that
 *    flushed on every request would silently undo LOQ-16969 and LOQ-16976 and re-bill every
 *    checkout, and it would do so with the whole suite green, because "cache never hits" is
 *    invisible to a test that only checks for over-sharing. Hence
 *    testTheSameShopperKeepsItsOwnStoresAndIsNotWrittenTo(),
 *    testANumericStringCustomerIdIsTheSameShopperAsTheIntegerId() (the '7' versus 7 case:
 *    Magento hands back either depending on how the id reached the session, and a typed
 *    comparison would flush on every single request) and
 *    testAGuestSessionIsNotFlushedOnEveryRequest();
 *  - LEGACY data, written before the marker existed, is ADOPTED rather than thrown away,
 *    see testLegacyDataWithNoOwnerMarkerIsAdopted(). Anything already in the session was by
 *    definition written in THIS session, so it belongs to whoever is at this browser now,
 *    and flushing it at deploy time would cost every live shopper their bypasses for
 *    nothing. An UNREADABLE marker is the opposite case and is answered the opposite way,
 *    see testAnUnreadableOwnerMarkerIsTreatedAsAnIdentityChange();
 *  - an unreadable IDENTITY is a third owner of its own and is never folded onto the guest,
 *    see testACustomerIdThatCannotBeReadIsItsOwnOwnerAndNeverTheGuest(). That is a
 *    REGRESSION test, not a completeness one: mapping an unreadable customer id onto the
 *    guest id made "a customer whose id we cannot read" and "nobody is logged in" compare
 *    EQUAL, so a logout out of that state did not flush and the guest that followed
 *    inherited all three bypass stores - the exact hand-off this class exists to stop;
 *  - the ENROLMENT assertion: an attribute missing from the flush list is refused by both
 *    getData() and setData() rather than quietly reached through the guard without ever
 *    being flushed, see testAnUnenrolledAttributeIsRejectedByBothGetDataAndSetData() and
 *    its mirror testEveryEnrolledAttributeIsAcceptedByBothGetDataAndSetData();
 *  - a session storage EMPTIED mid-request, which is the one door the owner marker cannot
 *    hold shut, because the wipe takes the marker with the stores it describes - the
 *    destroy() inside Magento\Customer\Model\Session::logout() and
 *    Magento\Framework\Session\SessionManager::clearStorage() both do it. Three tests, and
 *    all three are needed. The verdicts of the run in progress must not cross the wipe into
 *    the identity that follows it, see
 *    testAVerdictSurvivesNeitherTheStorageWipeNorTheIdentityChangeThatFollowsIt(); the price
 *    of that - one re-bill when the identity did NOT change - is asserted deliberately in
 *    testEmptyingTheStorageUnderOneIdentityRebillsWhatThatRunHadRemembered() rather than
 *    left to be met later as a "regression"; and, in the opposite direction, an unmarked
 *    session must still be able to report an UNCHANGED epoch twice running, see
 *    testConsecutiveLookupsOnAnUnmarkedSessionReportOneUnchangedOwnershipEpoch(), because
 *    "unchanged" is the answer that licenses a caller to keep derived data and a guard that
 *    can never give it re-bills every repeated row of every import.
 *
 * The end of the chain - that the two helpers really do reach those attributes only through
 * this class - is asserted behaviourally for all three stores
 * (testACapturedAddressBypassDoesNotSurviveALogin(), testACachedVerdictDoesNotSurviveALogin(),
 * testABatchVerdictDoesNotSurviveALogin()) and structurally
 * (testNeitherHelperKeepsAReferenceToTheRawCustomerSession()), because a single raw
 * $session->getData() left anywhere in Controller or Validator re-opens the defect while
 * every test in this file still passes.
 *
 * ONE STORE IS ONLY NOMINALLY COVERED IN PRODUCTION, and that is pinned here rather than
 * left to the class docblock: the BATCH verdict cache is written exclusively from adminhtml,
 * where the customer session carries no customer id, so its owner is permanently the guest
 * and the flush is a no-op on that path. The guard machinery works for it - proved by
 * testABatchVerdictDoesNotSurviveALogin(), which drives a real customer identity change
 * through verifyMultipleAddresses() - but on the path that actually writes it there is no
 * identity to change. See
 * testOnTheAdminhtmlPathTheBatchCacheIsOwnedByTheGuestAndTheFlushIsANoOp(), which pins that
 * ACCEPTED LIMIT as documented behaviour so it cannot change silently in either direction.
 */
class ShopperScopedAddressStoresTest extends TestCase
{
    /** The serializer double, shared with every other harness that reads a payload back. */
    use ProductionSerializerDouble;

    /** Any non-empty key lets both helpers build their API connectors. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /** AVC strictly better than the baked-in default threshold "P40-U00-P0-95" => accepted. */
    private const PASSING_AVC = 'V55-I22-P9-99';

    /**
     * Address quality index the BATCH path is judged against, and the value the stub
     * connector answers with, so checkQualityIndex()'s 'A' <= 'A' passes.
     */
    private const PASSING_AQI = 'A';

    /** Session attribute the captured-address store lives under. */
    private const CAPTURED_ADDRESSES_SESSION_KEY = 'captured_addresses';

    /** Session attribute the single-address verdict cache lives under. */
    private const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /** Session attribute the batch verdict cache lives under. */
    private const BATCH_VERIFY_CACHE_SESSION_KEY = 'loqate_verified_batch_addresses';

    /**
     * The second identity source ShopperScopedAddressStores deliberately does NOT read.
     *
     * Held as a STRING and never imported: this class is a backend one, it is not among the
     * handful stubbed under Test/stubs, and the harness runs without Magento installed - so a
     * `use` statement or an `instanceof` on it would be a fatal here rather than an assertion.
     * See isSessionCollaborator() for how it is matched.
     */
    private const BACKEND_AUTH_SESSION = 'Magento\Backend\Model\Auth\Session';

    /** A Magento-shaped address as it arrives from checkout. */
    private const ADDRESS = [
        'street' => ['1 High St', 'Flat 2'],
        'city' => 'London',
        'region' => 'Greater London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
    ];

    /**
     * Build a ShopperScopedAddressStores over a customer session double whose data persists and
     * whose logged-in identity can be changed between calls, exactly as a login or a logout
     * changes it between two requests.
     *
     * Every setData() is recorded as well as applied, so a test can assert not only what the
     * session ENDS UP holding but that the hot path (same shopper) writes NOTHING at all -
     * which is what makes running the check on every single access affordable.
     *
     * @param array<string, mixed> $data Session attributes present before the first access.
     * @param int|string|null $customerId Logged-in customer, null for a guest.
     * @return array{guard: ShopperScopedAddressStores, session: ArrayObject, identity: ArrayObject,
     *     writes: ArrayObject}
     */
    private function createGuard(array $data = [], $customerId = null): array
    {
        $sessionStore = new ArrayObject($data);
        $identity = new ArrayObject(['customerId' => $customerId]);
        $writes = new ArrayObject();

        $sessionMock = $this->createSessionDouble($sessionStore, $identity, $writes);

        return [
            'guard' => new ShopperScopedAddressStores($sessionMock),
            'session' => $sessionStore,
            'identity' => $identity,
            'writes' => $writes,
        ];
    }

    /**
     * A Magento\Customer\Model\Session double that actually stores what it is given.
     *
     * The shared Test/stubs Session is a no-op (getData() returns null, setData() stores
     * nothing), so nothing under test could ever be observed. getData()/setData() have to be
     * *added* to the double when the real Magento Session is present, because it does not
     * declare them - SessionManager __call-forwards them to Session\Storage - while the stub
     * does declare them and PHPUnit refuses to "add" an existing method; hence the
     * method_exists() filter, which keeps this double working on both sides.
     *
     * @param ArrayObject $sessionStore Backing store for the session attributes.
     * @param ArrayObject $identity Holds 'customerId', read LIVE so a test can log in mid-test.
     * @param ArrayObject|null $writes Records every setData() call, in order.
     * @return Session&MockObject
     */
    private function createSessionDouble(
        ArrayObject $sessionStore,
        ArrayObject $identity,
        ?ArrayObject $writes = null
    ) {
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
            static function ($key, $value = null) use ($sessionStore, $sessionMock, $writes) {
                if ($writes !== null) {
                    $writes[] = ['key' => $key, 'value' => $value];
                }
                $sessionStore[$key] = $value;

                return $sessionMock;
            }
        );
        $sessionMock->method('getCustomerId')->willReturnCallback(
            static fn () => $identity['customerId']
        );

        return $sessionMock;
    }

    /**
     * THE guarantee, in all three directions a shared browser can take an identity: every
     * shopper-scoped store is cleared and the new owner is recorded.
     *
     * All three transitions matter and none of them subsumes another. Guest to logged in is
     * the family-device case (a guest browses, then someone signs in). Customer A to
     * customer B is one login straight after another, which fires no logout in between on
     * some flows. Logged in to guest is the LOGOUT, and it is the one an "only track logged-in
     * customers" implementation misses: without a value standing for "guest", logging out
     * looks identical to a session that was never marked, so A's bypasses would be left
     * sitting in front of whoever uses the browser next.
     *
     * @param int|string|null $before Logged-in identity that wrote the stores.
     * @param int|string|null $after Logged-in identity making the next request.
     * @param int $expectedOwner Owner id that must be recorded after the change.
     */
    #[DataProvider('identityChangeProvider')]
    public function testEveryIdentityChangeFlushesEveryShopperScopedStore(
        $before,
        $after,
        int $expectedOwner
    ): void {
        $harness = $this->createGuard($this->seededStores(), $before);

        // First request: the stores belong to $before, so nothing is flushed and the marker
        // records that identity.
        $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);
        $this->assertSame(
            $this->seededStores(),
            $this->managedAttributes($harness),
            'The stores must survive a request made by the shopper they belong to.'
        );

        // ...and then the identity changes, as a login, a logout or a second login does.
        $harness['identity']['customerId'] = $after;
        $read = $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);

        $this->assertNull(
            $read,
            'The very access that detects the identity change must already answer with the flushed value: a '
            . 'store cleared only on the NEXT request still grants this request its bypass.'
        );
        foreach ($this->managedKeys() as $key) {
            $this->assertNull(
                $harness['session'][$key] ?? null,
                sprintf(
                    'Session attribute "%s" survived an identity change. All three stores are verify '
                    . 'bypasses, so they must be flushed TOGETHER: leaving any one of them lets the new '
                    . 'shopper check out an address that was only ever verified for the previous one.',
                    $key
                )
            );
        }
        $this->assertSame(
            $expectedOwner,
            $harness['session'][$this->ownerKey()] ?? null,
            'The new identity must be recorded as the owner, or the stores it now writes are flushed again '
            . 'on the next access - the caches would never hit and every address would be re-billed.'
        );
    }

    /**
     * @return array<string, array{0: int|string|null, 1: int|string|null, 2: int}>
     */
    public static function identityChangeProvider(): array
    {
        return [
            'a guest logs in' => [null, 7, 7],
            'one customer logs in straight after another' => [7, 8, 8],
            'a customer logs out' => [7, null, 0],
            // Magento hands back an int or a numeric string depending on how the id reached
            // the session, so a genuine change has to be detected across both shapes too.
            'a customer logs out, having arrived as a numeric string' => ['7', null, 0],
            'a guest logs in and the id arrives as a numeric string' => [null, '8', 8],
        ];
    }

    /**
     * The flush must not depend on which store the request happens to touch first.
     *
     * Checkout reaches these attributes in different orders on different paths -
     * verifyAddress() reads the captured-address store before the verdict cache,
     * verifyMultipleAddresses() reads the captured store and then the BATCH cache, and a
     * Capture retrieve writes the captured store without reading either cache. A guard
     * wired into only one of those entry points would leave the other two stores intact on
     * exactly the requests that do not go through it.
     *
     * Driven from the production list of managed keys, so a fourth store added to it is
     * covered by this test automatically.
     *
     * @param string $touched Managed session key the request reads first.
     */
    #[DataProvider('managedStoreProvider')]
    public function testTouchingAnyOneStoreAfterAnIdentityChangeFlushesAllOfThem(string $touched): void
    {
        $harness = $this->createGuard(
            array_merge($this->seededStores(), [$this->ownerKey() => 7]),
            8
        );

        $harness['guard']->getData($touched);

        foreach ($this->managedKeys() as $key) {
            $this->assertNull(
                $harness['session'][$key] ?? null,
                sprintf(
                    'Reading "%s" after an identity change must flush "%s" as well: the three stores are one '
                    . 'shopper\'s data and have one lifetime.',
                    $touched,
                    $key
                )
            );
        }
    }

    /**
     * One case per managed store, taken from the production constant so a store added to the
     * flush list is exercised without anyone remembering to extend this file.
     *
     * @return array<string, array{0: string}>
     */
    public static function managedStoreProvider(): array
    {
        $cases = [];
        foreach (self::readManagedKeys() as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    /**
     * The coverage gate on the flush list itself: it must name exactly the three attributes
     * that grant a verify bypass, reached through the names Controller and Validator publish.
     *
     * This is the one thing the behavioural tests cannot see. Reading or writing an attribute
     * through ShopperScopedAddressStores does NOT enrol it in the flush - only this list does - so
     * a fourth store could be added, reached through the guard, and still be inherited by the
     * next shopper with every other test in this file green.
     *
     * The literals are asserted alongside the list because the attribute names MOVED onto
     * this class in the LOQ-16978 review (Controller::CAPTURED_ADDRESSES_SESSION_KEY and
     * Validator's two are now aliases of the constants here, to break a circular dependency:
     * the flush list pointed at those classes while both of them construct this one). Two
     * things have to survive that move and neither is visible from the list alone - the
     * aliases must still resolve, because every other reference in the module and in three
     * other test files goes through them, and the VALUES must be unchanged, or every live
     * session loses its stores at deploy time and every shopper mid-checkout is re-billed.
     */
    public function testTheFlushListNamesExactlyTheStoresThatGrantAVerifyBypass(): void
    {
        $managed = $this->managedKeys();

        $this->assertEqualsCanonicalizing(
            [
                Controller::CAPTURED_ADDRESSES_SESSION_KEY,
                Validator::VERIFY_CACHE_SESSION_KEY,
                Validator::BATCH_VERIFY_CACHE_SESSION_KEY,
            ],
            $managed,
            'Every session attribute that can make an address skip the billable Loqate verify must be in the '
            . 'flush list: the Capture bypass and BOTH verdict caches. One left out is one bypass a shopper '
            . 'can inherit from whoever used the browser before them.'
        );
        $this->assertSame(
            [
                self::CAPTURED_ADDRESSES_SESSION_KEY,
                self::VERIFY_CACHE_SESSION_KEY,
                self::BATCH_VERIFY_CACHE_SESSION_KEY,
            ],
            [
                Controller::CAPTURED_ADDRESSES_SESSION_KEY,
                Validator::VERIFY_CACHE_SESSION_KEY,
                Validator::BATCH_VERIFY_CACHE_SESSION_KEY,
            ],
            'The three attribute NAMES are unchanged by the constants moving onto ShopperScopedAddressStores. They '
            . 'are the keys of live customer sessions: renaming one silently empties that store for every '
            . 'shopper mid-checkout at deploy time, which re-bills every address they have already had '
            . 'verified.'
        );
        $this->assertSame(
            [
                ShopperScopedAddressStores::CAPTURED_ADDRESSES_SESSION_KEY,
                ShopperScopedAddressStores::VERIFY_CACHE_SESSION_KEY,
                ShopperScopedAddressStores::BATCH_VERIFY_CACHE_SESSION_KEY,
            ],
            [
                Controller::CAPTURED_ADDRESSES_SESSION_KEY,
                Validator::VERIFY_CACHE_SESSION_KEY,
                Validator::BATCH_VERIFY_CACHE_SESSION_KEY,
            ],
            'Controller and Validator must keep publishing these names as ALIASES of the constants on the '
            . 'guard. The guard owns them because the guard is what enforces their lifetime, but every other '
            . 'reference in the module still goes through the old names - and an alias that drifts from what '
            . 'it aliases is two attributes with one meaning, of which only one is in the flush list.'
        );
        $this->assertSame(
            count($managed),
            count(array_unique($managed)),
            'No attribute may be listed twice: the list is iterated to flush, so a duplicate is dead weight.'
        );
        $this->assertNotContains(
            $this->ownerKey(),
            $managed,
            'The owner marker must NOT be one of the flushed attributes: flushing it would erase the identity '
            . 'just recorded, so the very next access would flush the stores all over again and no verdict '
            . 'could ever be cached.'
        );
    }

    /**
     * The marker is a bare key in the customer session, shared with Magento core and every
     * other module on the installation, so it has to be namespaced. A collision would be read
     * as an identity change (or worse, as a match) on every request.
     */
    public function testTheOwnerMarkerIsNamespacedToThisModule(): void
    {
        $marker = $this->ownerKey();

        $this->assertNotSame('', $marker, 'The ownership marker must be a non-empty session attribute name.');
        $this->assertStringStartsWith(
            'loqate_',
            $marker,
            'The ownership marker shares the customer session with Magento core and every other module, so '
            . 'its name must be namespaced to this one.'
        );
    }

    /**
     * The over-flushing guard, and the reason it matters as much as the flush itself: for the
     * SAME shopper the stores must survive untouched, and the check must write NOTHING.
     *
     * A guard that flushed whenever it was unsure would silently undo LOQ-16969 and
     * LOQ-16976 - every checkout would be re-billed - and would do it with the rest of the
     * suite green, because "the cache never hits" looks exactly like "the cache is safe" to a
     * test that only asserts nothing is over-shared. The write assertion is the second half:
     * this check runs on EVERY access to any of the three stores, so the matching case has to
     * be reads only - the owner marker and the customer id, two of them - and not a rewrite of
     * four attributes per lookup.
     */
    public function testTheSameShopperKeepsItsOwnStoresAndIsNotWrittenTo(): void
    {
        $harness = $this->createGuard(
            array_merge($this->seededStores(), [$this->ownerKey() => 7]),
            7
        );

        $read = $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);

        $this->assertSame(
            ['cached' => 'verdict'],
            $read,
            'The shopper who owns the stores must be handed their contents back unchanged.'
        );
        $this->assertSame(
            $this->seededStores(),
            $this->managedAttributes($harness),
            'None of the stores may be flushed while the same shopper is making the requests.'
        );
        $this->assertSame(
            [],
            iterator_to_array($harness['writes']),
            'The matching case must write nothing at all: this check runs on every single access to every '
            . 'store, so it has to cost the two session reads it already makes - the owner marker and the '
            . 'customer id - and no writes.'
        );
    }

    /**
     * The '7' versus 7 case, pinned as behaviour because it decides whether the caches work
     * at all.
     *
     * Magento\Customer\Model\Session::getCustomerId() answers an int or a numeric string
     * depending on how the id reached the session (a fresh login versus a session restored
     * from storage), so the SAME shopper can present both shapes across two requests. A
     * typed comparison would read that as an identity change, flush the stores on the second
     * request, and re-bill every address in the checkout - while every test asserting that
     * data is not over-shared stayed green. The ids are therefore normalised to int before
     * they are compared, and both orders are asserted here because a normalisation applied
     * to only one side of the comparison passes one direction and fails the other.
     *
     * @param int|string $before Identity that wrote the stores.
     * @param int|string $after Identity on the next request - the same shopper, differently typed.
     */
    #[DataProvider('sameShopperDifferentTypeProvider')]
    public function testANumericStringCustomerIdIsTheSameShopperAsTheIntegerId($before, $after): void
    {
        $harness = $this->createGuard($this->seededStores(), $before);

        $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);
        $harness['identity']['customerId'] = $after;
        $read = $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);

        $this->assertSame(
            ['cached' => 'verdict'],
            $read,
            sprintf(
                'Customer id %s and %s are the same shopper: the ids must be normalised before they are '
                . 'compared, or the type Magento happens to hand back decides whether a shopper keeps their '
                . 'own caches - and every address in the checkout is billed again.',
                var_export($before, true),
                var_export($after, true)
            )
        );
        $this->assertSame(
            $this->seededStores(),
            $this->managedAttributes($harness),
            'No store may be flushed when only the TYPE of the customer id changed.'
        );
        $this->assertSame(
            7,
            $harness['session'][$this->ownerKey()] ?? null,
            'The marker must hold the normalised int form, so the comparison is decided by the id and never '
            . 'by its type.'
        );
    }

    /**
     * @return array<string, array{0: int|string, 1: int|string}>
     */
    public static function sameShopperDifferentTypeProvider(): array
    {
        return [
            'string then int' => ['7', 7],
            'int then string' => [7, '7'],
            'string throughout' => ['7', '7'],
        ];
    }

    /**
     * A guest browsing the site is an identity too, and one that must not flush on every
     * request: a guest checkout is exactly where the verify caches earn their keep, since
     * the same address is replayed across three to five call paths in one checkout.
     */
    public function testAGuestSessionIsNotFlushedOnEveryRequest(): void
    {
        $harness = $this->createGuard([], null);

        // First access marks the session as the guest's.
        $harness['guard']->setData(self::VERIFY_CACHE_SESSION_KEY, ['cached' => 'verdict']);

        $this->assertSame(
            0,
            $harness['session'][$this->ownerKey()] ?? null,
            'A guest must be recorded with a real owner id, not left unmarked: an unmarked session is '
            . 'indistinguishable from legacy data, so a later logout could not be detected as a change.'
        );

        // Two more requests by the same guest.
        $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);
        $read = $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);

        $this->assertSame(
            ['cached' => 'verdict'],
            $read,
            'A guest must keep their own verdict cache for the whole session: this is the shopper the cache '
            . 'was written for, and re-flushing it would re-bill every address in a guest checkout.'
        );
    }

    /**
     * A write made on the very request that detects an identity change must survive it.
     *
     * The ownership check runs BEFORE the write for exactly this reason: were it to run
     * afterwards - or were the flush deferred - the first verdict the new shopper earned
     * would be wiped a moment after it was stored, and their first address would be verified
     * twice over.
     */
    public function testAValueWrittenOnTheRequestThatFlushesTheStoresSurvives(): void
    {
        $harness = $this->createGuard(
            array_merge($this->seededStores(), [$this->ownerKey() => 7]),
            8
        );

        $harness['guard']->setData(self::VERIFY_CACHE_SESSION_KEY, ['fresh' => 'verdict']);

        $this->assertSame(
            ['fresh' => 'verdict'],
            $harness['session'][self::VERIFY_CACHE_SESSION_KEY] ?? null,
            'The new shopper\'s own write must land in the freshly flushed store, not be wiped by the flush '
            . 'it triggered.'
        );
        $this->assertNull(
            $harness['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] ?? null,
            'The stores the write did not touch must still have been flushed.'
        );
        $this->assertSame(
            ['fresh' => 'verdict'],
            $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY),
            'Reading it straight back must return it: the flush must not fire a second time for the shopper '
            . 'who has just been recorded as the owner.'
        );
    }

    /**
     * Data written BEFORE this guard existed carries no owner marker, and must be ADOPTED
     * rather than thrown away.
     *
     * It is safe: anything in the session was by definition written during THIS session, so
     * it belongs to whoever is at this browser now, and any identity change from here on is
     * caught normally - which the second half of this test asserts, so "adopt" can never
     * quietly become "never check again". Flushing instead would buy nothing and would cost
     * every live shopper their bypasses at deploy time.
     *
     * @param int|string|null $customerId Identity of whoever is at the browser on the first access.
     * @param int $expectedOwner Owner id that must be recorded for them.
     */
    #[DataProvider('legacyAdoptionProvider')]
    public function testLegacyDataWithNoOwnerMarkerIsAdopted($customerId, int $expectedOwner): void
    {
        $harness = $this->createGuard($this->seededStores(), $customerId);

        $read = $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);

        $this->assertSame(
            ['captured'],
            $read,
            'Data written before the marker existed must be adopted, not discarded: it was written in this '
            . 'same session, so it belongs to whoever is at this browser.'
        );
        $this->assertSame(
            $this->seededStores(),
            $this->managedAttributes($harness),
            'Adoption must leave all three stores intact.'
        );
        $this->assertSame(
            $expectedOwner,
            $harness['session'][$this->ownerKey()] ?? null,
            'Adoption must RECORD the adopting identity, or the data stays unmarked and a later login could '
            . 'not be told apart from this first access.'
        );

        // Adoption happens once. The next identity change is an ordinary one.
        $harness['identity']['customerId'] = 99;
        $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);

        $this->assertSame(
            [null, null, null],
            array_values($this->managedAttributes($harness)),
            'Adopted data must still be flushed when the shopper changes: adoption applies to the FIRST '
            . 'access only, and must not become a permanent exemption from the check.'
        );
    }

    /**
     * @return array<string, array{0: int|string|null, 1: int}>
     */
    public static function legacyAdoptionProvider(): array
    {
        return [
            'adopted by a guest' => [null, 0],
            'adopted by a logged-in customer' => [7, 7],
            'adopted by a logged-in customer whose id arrives as a string' => ['7', 7],
        ];
    }

    /**
     * The mirror image of adoption, answered the opposite way: a marker that is PRESENT but
     * cannot be read as a customer id is treated as an identity change and the stores are
     * flushed.
     *
     * That asymmetry is deliberate. An ABSENT marker means "nobody has claimed this data
     * yet"; an unreadable one means the marker was written and is now something we do not
     * understand - a corrupted session payload, another module writing to the key - and it
     * cannot be shown to belong to this shopper. Flushing costs at most a few re-verified
     * addresses; trusting it risks handing one shopper another's bypasses.
     *
     * @param mixed $marker Value found in the owner attribute.
     */
    #[DataProvider('unreadableOwnerMarkerProvider')]
    public function testAnUnreadableOwnerMarkerIsTreatedAsAnIdentityChange($marker): void
    {
        $harness = $this->createGuard(
            array_merge($this->seededStores(), [$this->ownerKey() => $marker]),
            7
        );

        $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);

        $this->assertSame(
            [null, null, null],
            array_values($this->managedAttributes($harness)),
            'An owner marker that cannot be read as a customer id cannot be shown to belong to this shopper, '
            . 'so the stores must be flushed rather than trusted.'
        );
        $this->assertSame(
            7,
            $harness['session'][$this->ownerKey()] ?? null,
            'The unreadable marker must be replaced by the current identity, or every request repeats the '
            . 'flush and nothing can ever be cached.'
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableOwnerMarkerProvider(): array
    {
        return [
            'a non-numeric string' => ['not-a-customer'],
            'an empty string' => [''],
            'a boolean' => [true],
            'an array' => [[7]],
            'an object' => [new stdClass()],
        ];
    }

    /**
     * THE REGRESSION TEST for the LOQ-16978 review defect: a customer id that cannot be read
     * is its OWN owner, and must never be recorded as the guest.
     *
     * THE BUG. resolveOwnerId() used to answer `is_numeric($id) ? (int)$id : GUEST_OWNER_ID`,
     * so "the session answered a customer id we cannot understand" and "nobody is logged in"
     * produced the SAME owner, 0. Two genuinely different identities therefore compared equal
     * and no flush fired between them. The logout direction is the damaging one: a shopper
     * whose id could not be read earns bypasses in all three stores, logs out, and the guest
     * at the browser next inherits every one of them - precisely the shared-browser hand-off
     * this class exists to stop. The login direction is the same defect seen from the other
     * side.
     *
     * WHY BOTH ORDERINGS. They fail for different reasons and neither implies the other: a
     * fix applied to only one side of the comparison - or a sentinel that happened to equal
     * the guest id in one direction only - passes one and fails the other. Under the old
     * logic BOTH of these cases see owner 0 on both requests and flush nothing, so both fail.
     *
     * The owner assertion is the load-bearing half. A guard that flushed on some unrelated
     * grounds would satisfy the flush assertions while leaving the two identities recorded
     * identically, so the NEXT transition out of the unreadable state would still be missed.
     *
     * @param int|string|null $before Identity that wrote the stores.
     * @param int|string|null $after Identity making the next request.
     * @param string $expectedOwner 'guest' or 'unreadable' - resolved from the production
     *                              constants, because the point is which SENTINEL is
     *                              recorded, not which literal integer it happens to be.
     */
    #[DataProvider('unreadableIdentityChangeProvider')]
    public function testACustomerIdThatCannotBeReadIsItsOwnOwnerAndNeverTheGuest(
        $before,
        $after,
        string $expectedOwner
    ): void {
        $harness = $this->createGuard($this->seededStores(), $before);

        // First request: whatever $before is, it adopts the stores and is recorded.
        $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);
        $this->assertSame(
            $this->seededStores(),
            $this->managedAttributes($harness),
            'The stores must survive the first request, or the flush asserted below could be the adoption '
            . 'branch rather than the identity change.'
        );

        // ...and then the identity changes between an unreadable id and a guest.
        $harness['identity']['customerId'] = $after;
        $read = $harness['guard']->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);

        $this->assertNull(
            $read,
            'The access that detects the change must already answer with the flushed value: a store cleared '
            . 'only on the NEXT request still grants THIS request its bypass.'
        );
        foreach ($this->managedKeys() as $key) {
            $this->assertNull(
                $harness['session'][$key] ?? null,
                sprintf(
                    'Session attribute "%s" survived the move between a guest and a customer whose id could '
                    . 'not be read. Those are two different identities, so all three bypass stores must be '
                    . 'flushed; folding the unreadable id onto the guest id makes them compare equal and hands '
                    . 'one shopper\'s bypasses to the next person at the browser.',
                    $key
                )
            );
        }
        $this->assertSame(
            $this->ownerConstant($expectedOwner === 'guest' ? 'GUEST_OWNER_ID' : 'UNREADABLE_OWNER_ID'),
            $harness['session'][$this->ownerKey()] ?? null,
            'An unreadable customer id must be recorded under its own sentinel, never under the guest id. '
            . 'Recording both as the guest is what made the two states indistinguishable in the first place, '
            . 'so a test that only checked the flush would pass again the moment the sentinel was removed.'
        );
    }

    /**
     * Both directions between a guest and a customer whose id cannot be read.
     *
     * 'not-a-customer' stands for anything getCustomerId() can answer that is neither null
     * nor numeric. It cannot arrive through Magento's own API - the id is an int, a numeric
     * string or null - so reaching it needs another module writing to the customer session's
     * 'customer_id'. That is exactly why it must not be answered by guessing "guest": an
     * identity this class cannot read is one it must not claim to recognise.
     *
     * @return array<string, array{0: int|string|null, 1: int|string|null, 2: string}>
     */
    public static function unreadableIdentityChangeProvider(): array
    {
        return [
            'a customer whose id cannot be read logs out' => ['not-a-customer', null, 'guest'],
            'a guest acquires a customer id that cannot be read' => [null, 'not-a-customer', 'unreadable'],
        ];
    }

    /**
     * The three owner classes have to stay DISJOINT, which is the property the sentinel's
     * value is chosen for rather than an incidental fact about it.
     *
     * Customer ids are positive auto-increment values, the guest is 0, so the unreadable
     * sentinel has to be negative. Asserted structurally because the behavioural test above
     * can only see the two identities it drives: a sentinel changed to 0 fails that test, but
     * one changed to a positive number would pass it while silently colliding with a real
     * customer id - handing shopper #1's bypasses to any session with an unreadable id.
     */
    public function testTheUnreadableOwnerSentinelCannotCollideWithAGuestOrACustomer(): void
    {
        $guest = $this->ownerConstant('GUEST_OWNER_ID');
        $unreadable = $this->ownerConstant('UNREADABLE_OWNER_ID');

        $this->assertSame(
            0,
            $guest,
            'The guest owner must be 0: customer ids are positive auto-increment values, so 0 is the one '
            . 'value that can stand for "not logged in" without ever colliding with one.'
        );
        $this->assertNotSame(
            $guest,
            $unreadable,
            'The unreadable-id sentinel must not equal the guest id. That collision IS the defect: it made a '
            . 'logout out of an unreadable identity look like no change at all, so the stores were not '
            . 'flushed and the guest inherited all three bypasses.'
        );
        $this->assertLessThan(
            0,
            $unreadable,
            'The unreadable-id sentinel must be negative. Customer ids are positive and the guest is 0, so '
            . 'only a negative value is guaranteed not to collide with either; a positive sentinel would '
            . 'share an owner with a real customer account.'
        );
    }

    /**
     * The ENROLMENT assertion, in the direction that rejects: an attribute that is not in
     * SHOPPER_SCOPED_SESSION_KEYS may not be reached through this class at all - not for
     * reading and not for writing.
     *
     * WHY THIS IS WORTH A TEST. Reading a new attribute through the guard LOOKS protected -
     * the call site is identical to the three that are - while the attribute is never
     * actually flushed, which silently keeps the defect LOQ-16978 exists to close. The throw
     * is what makes "reachable through this class" and "flushed by this class" the same set;
     * without it they drift apart at the first new call site and every test in this file
     * stays green.
     *
     * Both directions are asserted because only setData() is enrolled-checked by the writer
     * and only getData() by the reader: a check added to one and forgotten on the other
     * leaves half the hole open.
     *
     * The "nothing was written" assertion pins the ORDERING inside the guard. assertEnrolled()
     * runs BEFORE enforceOwnership(), so a rejected call must not have written the owner
     * marker either - a rejected access must leave the session exactly as it found it.
     *
     * @param string $key Attribute that is not enrolled in the flush.
     */
    #[DataProvider('unenrolledSessionKeyProvider')]
    public function testAnUnenrolledAttributeIsRejectedByBothGetDataAndSetData(string $key): void
    {
        $calls = [
            'getData()' => static fn (ShopperScopedAddressStores $guard) => $guard->getData($key),
            'setData()' => static fn (ShopperScopedAddressStores $guard) => $guard->setData($key, 'a new value'),
        ];

        foreach ($calls as $label => $call) {
            $harness = $this->createGuard([$key => 'pre-existing'], 7);

            $thrown = null;
            try {
                $call($harness['guard']);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(
                \InvalidArgumentException::class,
                $thrown,
                sprintf(
                    '%s must refuse the unenrolled attribute "%s". Letting it through gives that attribute '
                    . 'the guard\'s appearance without its protection: the ownership check runs, but the '
                    . 'attribute is never in the flush, so it is inherited by the next shopper anyway.',
                    $label,
                    $key
                )
            );
            $this->assertStringContainsString(
                $key,
                $thrown->getMessage(),
                'The message must name the offending attribute, or the developer who trips this has to go '
                . 'looking for which call site it was.'
            );
            $this->assertStringContainsString(
                'SHOPPER_SCOPED_SESSION_KEYS',
                $thrown->getMessage(),
                'The message must name the fix - the list to add the attribute to - because this throw is '
                . 'only ever reached by a programming error, and the message is the instruction.'
            );
            $this->assertSame(
                'pre-existing',
                $harness['session'][$key] ?? null,
                sprintf('A refused %s must leave the attribute itself untouched.', $label)
            );
            $this->assertSame(
                [],
                iterator_to_array($harness['writes']),
                sprintf(
                    'A refused %s must write NOTHING at all, not even the owner marker: the enrolment check '
                    . 'runs before the ownership check precisely so a rejected access leaves the session '
                    . 'exactly as it found it.',
                    $label
                )
            );
        }
    }

    /**
     * Attributes that must NOT be reachable through the guard.
     *
     * The first four are the module's real un-enrolled session attributes, named in the
     * ShopperScopedAddressStores class docblock as deliberately out of scope for LOQ-16978. They
     * are the ones a future edit is most likely to route through this class by analogy, which
     * is exactly the mistake the throw exists to catch: they would gain the guard's
     * appearance without ever being added to the flush.
     *
     * The owner marker is included because it is the one attribute this class writes itself
     * and must never expose - flushing it would erase the identity just recorded - and the
     * last two cover a near-miss typo and the empty key.
     *
     * @return array<string, array{0: string}>
     */
    public static function unenrolledSessionKeyProvider(): array
    {
        return [
            // Plugin\AbstractPlugin::shouldVerify() - unbounded lists of verified emails and
            // phone numbers, bypasses of the same kind but out of scope for this ticket.
            'the email bypass list' => ['loqate_email'],
            'the phone bypass list' => ['loqate_phone'],
            // Plugin\Frontend\AccountManagement / CheckoutShippingInformation.
            'the pending email address' => ['loqate_email_to_validate'],
            // Plugin\Frontend\CheckoutBillingAddress, read by PlaceOrder/PlaceOrderGuest.
            'the billing error gate' => ['loqate_billing_errors'],
            'the ownership marker itself' => [self::readOwnerKey()],
            'a near miss of an enrolled name' => ['captured_address'],
            'the empty key' => [''],
        ];
    }

    /**
     * The mirror of the rejection, and the half that stops the assertion being tightened into
     * a wall: every attribute that IS enrolled must go through untouched, in both directions.
     *
     * Driven from the production flush list, so a fourth store added to it is exercised here
     * automatically - and a store added to the list but somehow unreachable through the guard
     * is reported as a failure rather than as silence.
     *
     * @param string $key A managed session key.
     */
    #[DataProvider('managedStoreProvider')]
    public function testEveryEnrolledAttributeIsAcceptedByBothGetDataAndSetData(string $key): void
    {
        $harness = $this->createGuard([$key => 'already here'], 7);

        $this->assertSame(
            'already here',
            $harness['guard']->getData($key),
            sprintf('"%s" is in the flush list, so reading it through the guard must be allowed.', $key)
        );

        $harness['guard']->setData($key, 'written through the guard');

        $this->assertSame(
            'written through the guard',
            $harness['session'][$key] ?? null,
            sprintf('"%s" is in the flush list, so writing it through the guard must be allowed.', $key)
        );
    }

    /**
     * End to end on the Capture bypass, which is the oldest and widest of the three stores:
     * an address shopper A picked from the Loqate lookup must not let shopper B check the
     * same address out unverified.
     *
     * Asserted through Validator::verifyAddress() rather than on the attribute, because that
     * is where the bypass is actually granted: this fails if the store is reached anywhere
     * without the guard, however correct the guard itself is.
     */
    public function testACapturedAddressBypassDoesNotSurviveALogin(): void
    {
        $shopper = $this->createShopper([
            self::CAPTURED_ADDRESSES_SESSION_KEY => [json_encode([
                'Address1' => '1 High St',
                'Address2' => 'Flat 2',
                'Country' => 'GB',
                'PostalCode' => 'SW1A 1AA',
                'Address3' => 'London',
                'Address4' => 'Greater London',
            ])],
        ]);

        // The guest who captured it gets the bypass.
        $asGuest = $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(['error' => false], $asGuest);
        $this->assertSame(
            0,
            $this->apiCallCount($shopper),
            'The shopper who captured the address must skip the billable verify: that is what the store is '
            . 'for, and this test would prove nothing if it did not hold first.'
        );

        // Somebody signs in on the same browser.
        $shopper['identity']['customerId'] = 7;
        $asCustomer = $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'A shopper who logs in must NOT inherit the previous user\'s captured-address bypass: the address '
            . 'has to be verified against Loqate for them, or an address nobody verified for this account '
            . 'reaches checkout unchecked.'
        );
        $this->assertSame(['error' => false], $asCustomer, 'The fresh verification stands on its own verdict.');
    }

    /**
     * The same guarantee for the verdict cache (LOQ-16969), which unlike the captured-address
     * store also covers TYPED addresses - so it is the store most likely to hold an entry for
     * whatever the next person at the browser types.
     */
    public function testACachedVerdictDoesNotSurviveALogin(): void
    {
        $shopper = $this->createShopper();

        $shopper['validator']->verifyAddress(self::ADDRESS);
        $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The cache must work for the shopper who earned the verdict, or the flush below would be '
            . 'indistinguishable from a cache that never hits.'
        );

        $shopper['identity']['customerId'] = 7;
        $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'A shopper who logs in must not be served a verdict cached for whoever used the browser before '
            . 'them: the address must be verified again for the new identity.'
        );

        // ...and the new shopper's own cache works normally from here on, which is what rules
        // out a guard that simply flushes on every request.
        $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'Once the new shopper is recorded as the owner, their own verdicts must be cached as usual - a '
            . 'guard that kept flushing would re-bill every call path of every checkout.'
        );
    }

    /**
     * The third store, driven end to end through the method that actually reads and writes
     * it: a BATCH verdict (LOQ-16976) must not be replayed for a different shopper.
     *
     * The batch cache was previously covered only at the guard level - "the attribute named
     * BATCH_VERIFY_CACHE_SESSION_KEY is in the flush list and is set to null" - which proves
     * the list, not the wiring. verifyMultipleAddresses() reaches the attribute through its
     * own cache key (namespaced by the resolved AQI threshold) and its own accessors
     * (getCachedBatchVerifyResult()/storeBatchVerifyResult()), none of which the single-address
     * tests exercise; either of those reading the raw session would leave this store shared
     * with every other test in this file green.
     *
     * The cache-hit assertion before the identity change is what makes the third assertion
     * mean anything: without it, "billed again after the login" is indistinguishable from a
     * cache that never hits at all.
     *
     * NOTE the ACCEPTED LIMIT this does NOT contradict, pinned separately in
     * testOnTheAdminhtmlPathTheBatchCacheIsOwnedByTheGuestAndTheFlushIsANoOp(): the guard
     * machinery below works, but in production this store is only ever written from
     * adminhtml, where there is no customer identity to change.
     */
    public function testABatchVerdictDoesNotSurviveALogin(): void
    {
        $shopper = $this->createShopper();
        $batch = [0 => self::ADDRESS];

        $first = $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            [0 => true],
            $first,
            'The address must pass on its own AQI first, or this test is measuring a rejection rather than a '
            . 'cached verdict.'
        );
        $this->assertSame(1, $this->apiCallCount($shopper), 'The first batch must be billed.');

        $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The batch verdict must be cached for the shopper who earned it, or the flush asserted below is '
            . 'indistinguishable from a cache that never hits.'
        );

        $shopper['identity']['customerId'] = 7;
        $afterLogin = $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'A shopper who logs in must not be handed a BATCH verdict cached for whoever used the browser '
            . 'before them: like the other two stores, this one is a licence to skip a billable verify, and '
            . 'it must be re-earned by the new identity.'
        );
        $this->assertSame([0 => true], $afterLogin, 'The fresh batch stands on its own verdict.');

        $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'Once the new shopper owns the stores their own batch verdicts must cache as usual - a guard that '
            . 'kept flushing would re-bill every admin order re-submission LOQ-16976 exists to de-duplicate.'
        );
    }

    /**
     * The same identity change on the IMPORT shape of the call - verifyMultipleAddresses($batch,
     * FALSE) - where NOTHING else in the request touches a shopper-scoped attribute first.
     *
     * NOT A DUPLICATE OF THE TEST ABOVE, and the difference is the whole point. That one passes
     * $checkForCaptured at its default of true, so the captured-address read happens BEFORE any
     * verdict is looked up, and that read runs the ownership check as a side effect. Every later
     * lookup in the request therefore sees a generation taken AFTER the flush no matter how the
     * generation is obtained - which means it cannot distinguish a guard that enforces ownership
     * from one that merely reports a stale counter.
     *
     * Plugin\Admin\ValidateImportAddress::afterValidateData() passes FALSE. On that path the
     * run-scoped verdict map is consulted before a single session attribute is read, so the
     * ownership check has to happen INSIDE the generation lookup itself; if it does not, the
     * first address of the request is answered out of the previous shopper's verdicts, and
     * because a hit short-circuits before the session store is read, no attribute is touched and
     * NO flush ever happens - the whole chunk is served from the old identity's memory.
     *
     * That is a licence to skip a billable verify being handed to a shopper who never earned it,
     * which is precisely what ShopperScopedAddressStores exists to prevent, arriving through a
     * store this class cannot see. Asserted here because the batch tests one layer up cannot see
     * it either: the run map answers them identically either way.
     */
    public function testABatchVerdictDoesNotSurviveALoginOnTheImportPathThatReadsNoCapturedStore(): void
    {
        $shopper = $this->createShopper();
        $batch = [0 => self::ADDRESS];

        $first = $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            [0 => true],
            $first,
            'The address must pass on its own AQI first, or this test is measuring a rejection rather than a '
            . 'remembered verdict.'
        );
        $this->assertSame(1, $this->apiCallCount($shopper), 'The first chunk must be billed.');

        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'A repeated address must be remembered for the shopper who earned it, or the flush asserted '
            . 'below is indistinguishable from a memory that never answers.'
        );

        $shopper['identity']['customerId'] = 7;
        $afterLogin = $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'On the import shape of the call NOTHING touches a shopper-scoped attribute before the verdict '
            . 'is looked up, so the identity change must be detected by the lookup itself. A generation '
            . 'reported without enforcing ownership hands the new identity the previous one\'s verdict, and '
            . 'the hit short-circuits before any session attribute is read - so the stores are never '
            . 'flushed either, and the entire chunk is answered from the old shopper\'s memory.'
        );
        $this->assertSame([0 => true], $afterLogin, 'The re-verified chunk stands on its own verdict.');

        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'And the new shopper\'s own verdicts must then be remembered as usual: a guard that discarded '
            . 'the map on every lookup would re-bill every repeated row of every import, which is the '
            . 'saving LOQ-17148 exists to deliver.'
        );
    }

    /**
     * THE SAME HAND-OFF, arriving through the one door the owner marker cannot hold shut: a
     * session storage EMPTIED between the two identities. A verdict earned by customer 7 must
     * not be served to the guest that follows, even though the marker that would have proved
     * the identity changed was erased in the same breath.
     *
     * WHY THIS IS REACHABLE, and not a thought experiment.
     * Magento\Customer\Model\Session::logout() calls destroy(), whose default 'clear_storage'
     * option is TRUE, so a logout empties EVERY attribute of the session storage - the three
     * shopper-scoped stores and, sitting beside them, the ownership marker that names who they
     * belonged to. Magento\Framework\Session\SessionManager::clearStorage() does the same for
     * any other module that calls it. The next access therefore finds NO marker, which is the
     * ADOPTION branch, not the flush branch: nothing is flushed, because the storage the flush
     * would have cleared is already empty.
     *
     * WHY THAT IS NOT HARMLESS. It is harmless for the three SESSION stores - they went with
     * the marker - but Validator::verifyMultipleAddresses() also remembers this run's batch
     * verdicts in a plain map on the Validator INSTANCE, which the storage wipe does not
     * touch. That map is a set of licences to skip a billable Cleansing call, so its lifetime
     * has to be the ownership one; if the guard counts only FLUSHES, the wipe-then-change
     * sequence moves no counter, the map is kept, and the guest is answered out of customer
     * 7's verdicts. What that measures ON THE WIRE is one billable call where two are owed:
     * one identity served a verdict another identity paid for, which is the hand-off
     * LOQ-16978 exists to stop, reaching a store LOQ-16978's flush cannot see.
     *
     * WHY $checkForCaptured IS FALSE, and why the test is worthless without it. That is the
     * import shape of the call (Plugin\Admin\ValidateImportAddress::afterValidateData() passes
     * false), and on it NOTHING reads the captured-address store first. With the default true,
     * that read runs the ownership check as a side effect and re-establishes ownership before
     * a single verdict is looked up, so the run map is discarded for a reason that has nothing
     * to do with what this test is about - and the assertion below would pass with the counter
     * tied to flushes alone.
     *
     * THE FIRST TWO CALLS ARE THE PRECONDITION, not decoration: they prove the run map really
     * does answer a repeat for the identity that earned it. Without that, "billed again after
     * the wipe" is indistinguishable from a memory that never answers anything.
     */
    public function testAVerdictSurvivesNeitherTheStorageWipeNorTheIdentityChangeThatFollowsIt(): void
    {
        $shopper = $this->createShopper();
        $batch = [0 => self::ADDRESS];

        $shopper['identity']['customerId'] = 7;
        $shopper['validator']->verifyMultipleAddresses($batch, false);
        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'Customer 7 must pay once and have the repeat answered from this run\'s memory, or everything '
            . 'asserted below is measuring a memory that never answers rather than one that stops answering '
            . 'across an identity change.'
        );

        // The logout: destroy() empties the storage - stores AND owner marker - and the
        // identity becomes the guest.
        $this->emptyTheSessionStorage($shopper);
        $shopper['identity']['customerId'] = null;

        $afterTheWipe = $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'The guest left at the browser after the wipe must be BILLED for this address rather than handed '
            . 'the verdict customer 7 paid for. The wipe erased the owner marker along with the stores, so '
            . 'the next access is an ADOPTION and flushes nothing - which means a guard that opened a new '
            . 'ownership epoch only on a FLUSH reports an unmoved epoch here, the run-scoped verdict map is '
            . 'kept, and the identity that follows is served out of the identity that preceded it. That is '
            . 'the hand-off LOQ-16978 exists to stop, arriving through a store its flush cannot reach.'
        );
        $this->assertSame(
            [0 => true],
            $afterTheWipe,
            'The re-verified address stands on its own freshly earned verdict.'
        );

        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'And from there the guest\'s OWN repeats must be free again: the epoch moved once, when ownership '
            . 'was re-established, and not on every lookup - or every repeated row of every import is '
            . 're-billed and the saving LOQ-17148 delivers is gone.'
        );
    }

    /**
     * THE PRICE OF THE RULE ABOVE, asserted deliberately rather than left to be discovered as a
     * "regression": emptying the session storage under an UNCHANGED identity costs that identity
     * one re-bill of everything the run had remembered.
     *
     * This is the case where the wipe is NOT accompanied by an identity change - a module
     * calling Magento\Framework\Session\SessionManager::clearStorage() mid-request, say - and
     * the run map is discarded anyway, because the marker that PROVED ownership was continuous
     * went with it. The licence to skip a billable Cleansing call is only valid while ownership
     * is demonstrably unbroken; after a wipe the guard cannot tell this from the logout in the
     * test above, and the two are indistinguishable from inside the class by construction, so
     * paying is the only safe reading. Correctness is not affected in either direction: the row
     * is asked about again and answered on its own merits.
     *
     * PINNED SO IT CANNOT BE "FIXED" QUIETLY. The obvious way to make this one call cheaper is
     * to stop opening a new epoch on adoption - and that is exactly the defect the test above
     * exists to catch, so the two must be read together. The same price is already paid by the
     * three session stores the wipe emptied; this only says the derived map pays it too.
     */
    public function testEmptyingTheStorageUnderOneIdentityRebillsWhatThatRunHadRemembered(): void
    {
        $shopper = $this->createShopper();
        $batch = [0 => self::ADDRESS];

        $shopper['validator']->verifyMultipleAddresses($batch, false);
        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The repeat must be free while the storage is intact, or the extra call asserted below is not '
            . 'the price of the wipe but the price of a memory that never worked.'
        );

        // No login, no logout: the same shopper throughout, with the storage emptied under them.
        $this->emptyTheSessionStorage($shopper);

        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'ACCEPTED COST, asserted so it cannot change unnoticed: a storage wipe re-bills the run\'s '
            . 'remembered verdicts even when the identity did not change. The wipe erased the ownership '
            . 'marker, so the guard can no longer show the stores have belonged to one identity throughout - '
            . 'and a licence to skip a BILLABLE call is only valid while it can. Making this call free again '
            . 'means not opening an epoch on adoption, which is precisely what lets a wipe followed by a '
            . 'logout hand one identity\'s verdicts to the next: read this test with '
            . 'testAVerdictSurvivesNeitherTheStorageWipeNorTheIdentityChangeThatFollowsIt().'
        );

        $shopper['validator']->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            2,
            $this->apiCallCount($shopper),
            'And the cost is ONE re-bill, not a permanent one: ownership is re-established by the access '
            . 'that noticed, so the run remembers again from there.'
        );
    }

    /**
     * The structural half of the epoch contract, and the one the billing tests above cannot
     * see: with NO marker recorded and NO identity change, two consecutive lookups must report
     * the SAME epoch.
     *
     * WHY THIS NEEDS ITS OWN TEST. "Open a new epoch on adoption" is satisfiable by simply
     * bumping the counter whenever no marker is found - without RECORDING the identity that
     * adopted. Every wipe test above still passes: the epoch moves after the wipe, so the run
     * map is discarded and the address is billed. What such an implementation loses is the
     * ability to ever say "unchanged" on a session that started life unmarked, because every
     * subsequent lookup finds no marker and opens yet another epoch - so every derived memory
     * is thrown away on every access, and every repeated row of every import is re-billed.
     * That is the LOQ-17148 saving itself, and it is invisible to a test that only asks whether
     * data is over-shared.
     *
     * ADOPTION MUST THEREFORE END OWNERSHIP BEING OPEN: the epoch is stable exactly because the
     * adopting identity is written to the marker, which is what the second assertion pins.
     * "Unchanged" is the strong statement in this contract - it is the answer that licenses a
     * caller to KEEP derived data - so an implementation that can never make it is not a
     * conservative one, it is one that has no contract left.
     *
     * The guest case is not academic: on the adminhtml import path, the only path that reads
     * this epoch in anger, the customer session carries no customer id at all.
     *
     * @param int|string|null $customerId Identity at the browser for the whole test.
     */
    #[DataProvider('unmarkedSessionIdentityProvider')]
    public function testConsecutiveLookupsOnAnUnmarkedSessionReportOneUnchangedOwnershipEpoch(
        $customerId,
        int $expectedOwner
    ): void {
        $harness = $this->createGuard($this->seededStores(), $customerId);

        $first = $harness['guard']->ownershipGeneration();
        $second = $harness['guard']->ownershipGeneration();

        $this->assertSame(
            $first,
            $second,
            'Two lookups in a row, with nothing whatever happening in between, must report the same '
            . 'ownership epoch. A guard that opens a new epoch on every unmarked lookup can never report '
            . '"unchanged", so every holder of derived data discards it on every access - which re-bills '
            . 'every repeated row of every import while every over-sharing test in this file stays green.'
        );
        $this->assertSame(
            $expectedOwner,
            $harness['session'][$this->ownerKey()] ?? null,
            'The adopting identity must be RECORDED, which is what makes the epoch above stable: an adoption '
            . 'that opens an epoch without writing the marker leaves the session unmarked forever, so the '
            . 'next lookup adopts all over again.'
        );

        $harness['guard']->getData(self::VERIFY_CACHE_SESSION_KEY);

        $this->assertSame(
            $first,
            $harness['guard']->ownershipGeneration(),
            'Reaching a store in between must not move the epoch either: for one identity with the marker '
            . 'in place, every access is the same epoch, whichever method it arrives through.'
        );
    }

    /**
     * Both identities a session with no owner marker can be adopted by.
     *
     * @return array<string, array{0: int|string|null, 1: int}>
     */
    public static function unmarkedSessionIdentityProvider(): array
    {
        return [
            // The adminhtml/import case: no customer id for the whole of the session.
            'a guest' => [null, 0],
            'a logged-in customer' => [7, 7],
        ];
    }

    /**
     * Empty the session storage the way Magento does, in place and behind the guard's back.
     *
     * Magento\Framework\Session\SessionManager::clearStorage() and the destroy() inside
     * Magento\Customer\Model\Session::logout() (whose 'clear_storage' option defaults to true)
     * both remove EVERY attribute, so the ownership marker goes with the stores it describes -
     * which is the whole point of the tests that call this. Removing the keys rather than
     * nulling them matters: a null marker is what the guard reads as "never marked", and
     * writing one would model the wipe by hand instead of reproducing it.
     *
     * @param array $shopper Harness from createShopper().
     */
    private function emptyTheSessionStorage(array $shopper): void
    {
        foreach (array_keys($shopper['session']->getArrayCopy()) as $key) {
            unset($shopper['session'][$key]);
        }
    }

    /**
     * THE ACCEPTED LIMIT, pinned as documented behaviour rather than left implicit: on the
     * only path that writes the batch cache in production, the flush is a NO-OP.
     *
     * Both writers of Validator::BATCH_VERIFY_CACHE_SESSION_KEY are adminhtml plugins -
     * Plugin\Admin\OrderSave and Plugin\Admin\ValidateImportAddress - and there the customer
     * session normally holds no customer id at all. So the owner recorded for that store is
     * permanently the guest, and an ADMIN USER swap inside one browser session changes
     * nothing the guard can see.
     *
     * WHAT THE CONSEQUENCE ACTUALLY IS: BILLING, NOT EXPOSURE. It is worth being precise,
     * because "one admin is served another admin's verdict" reads like a data leak and is not
     * one. The batch cache key already namespaces every entry by store view and by the
     * configured AQI threshold, and storeBatchVerifyResult() stores PASSES ONLY - so the
     * verdict the second admin is handed is the SAME verdict their own submission would have
     * earned, for the same address, under the same configuration. Nothing about the first
     * admin's data is disclosed by it: the second admin had to submit that address themselves
     * to reach the entry at all. What is genuinely shared is the BILLABLE CALL - the second
     * submission does not pay for a Cleansing request, so the one call is attributed to
     * whichever admin submitted first.
     *
     * THIS IS NOT A FAILING TEST AND IT IS NOT A WORKAROUND. It is the documented limit on
     * ShopperScopedAddressStores, asserted so it cannot change in either direction unnoticed - if
     * someone injects the backend auth session and closes it, this test fails and says so; if
     * someone assumes it is already closed, this test is the counter-example. It is
     * deliberately accepted upstream: the admin panel is a trusted, authenticated, non-shared
     * surface, the shared verdict is one the second admin would have earned anyway, and
     * injecting Magento\Backend\Model\Auth\Session would drag a backend dependency into a
     * helper constructed on every frontend checkout request. The SHOPPER-facing risk the
     * ticket exists to close - where the two identities are strangers and the shared bypass
     * decides whether an address is checked at all - is fully covered by the tests above.
     */
    public function testOnTheAdminhtmlPathTheBatchCacheIsOwnedByTheGuestAndTheFlushIsANoOp(): void
    {
        // Exactly the adminhtml situation: a customer session with no customer id, for the
        // whole of the session, whichever admin user is driving it.
        $shopper = $this->createShopper();
        $batch = [0 => self::ADDRESS];

        $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'The first admin submission must be billed.'
        );
        $this->assertNotSame(
            [],
            $shopper['session'][self::BATCH_VERIFY_CACHE_SESSION_KEY] ?? [],
            'The passing verdict must actually have been cached, or the limitation below would be nothing '
            . 'more than an empty store.'
        );
        $this->assertSame(
            $this->ownerConstant('GUEST_OWNER_ID'),
            $shopper['session'][$this->ownerKey()] ?? null,
            'On the adminhtml path the customer session carries no customer id, so the batch cache is owned '
            . 'by the GUEST. This is the documented ACCEPTED LIMIT on ShopperScopedAddressStores: the guard scopes '
            . 'by customer identity only, and there is no customer identity here to scope by.'
        );

        // A different admin user now drives the same browser session. Nothing about the
        // customer session changes, because the admin identity lives in a different session
        // object (Magento\Backend\Model\Auth\Session) that this guard deliberately does not
        // read - so there is nothing for it to detect.
        $shopper['validator']->verifyMultipleAddresses($batch);

        $this->assertSame(
            1,
            $this->apiCallCount($shopper),
            'DOCUMENTED LIMIT, asserted so it cannot drift: an admin-user swap inside one browser session '
            . 'does NOT flush the batch verdict cache, because the guard tracks the CUSTOMER id and adminhtml '
            . 'has none, so the second admin\'s identical submission replays the cached PASS instead of being '
            . 'billed. The consequence is BILLING ATTRIBUTION, not exposure - the entry is namespaced by store '
            . 'view and AQI threshold and only passes are stored, so it is the same verdict the second admin '
            . 'would have earned. Accepted upstream (the admin panel is trusted, authenticated and not '
            . 'shared); if this assertion ever fails because the backend auth session was injected, that is an '
            . 'improvement - update this test and the ACCEPTED LIMITS note on ShopperScopedAddressStores together.'
        );

        // The structural half of the same limit, and the reason the behavioural half above
        // cannot say more than it does: there is no admin identity in this class to change.
        $harness = $this->createGuard();
        $identitySources = array_filter(
            $this->propertyValues($harness['guard']),
            fn ($value): bool => $this->isSessionCollaborator($value)
        );

        $this->assertCount(
            1,
            $identitySources,
            sprintf(
                'ShopperScopedAddressStores must read exactly ONE identity source. Holding a %s alongside the '
                . 'customer session would mean the admin-swap limit asserted above has been closed, which is '
                . 'an improvement but makes the assertion above wrong: change both together, and the ACCEPTED '
                . 'LIMITS note on ShopperScopedAddressStores with them. Note that this counts SESSIONS only - the '
                . 'guard is free to acquire other collaborators (a logger is the standing candidate, named on '
                . 'assertEnrolled()) without touching what it can see about identity. Found: %s.',
                self::BACKEND_AUTH_SESSION,
                $this->describeCollaborators($harness['guard'])
            )
        );
        $this->assertInstanceOf(
            Session::class,
            reset($identitySources),
            sprintf(
                'The one session collaborator must be the CUSTOMER session, not %s: the customer identity is '
                . 'the whole of what this guard can scope by, and swapping which session it reads would '
                . 'silently change which identity change flushes the stores.',
                self::BACKEND_AUTH_SESSION
            )
        );
    }

    /**
     * Is this collaborator one of the two SESSION objects an identity could be read from?
     *
     * Narrower than "is an object" on purpose (LOQ-16978 review). The property being counted
     * is "how many identities can this guard see", and counting every object answered a
     * different question: a Logger - which assertEnrolled()'s own docblock names as the
     * standing alternative to the throw, and which would therefore be a perfectly ordinary
     * thing to add - would have failed a test whose message talks about session collaborators
     * and admin-user swaps, sending the next reader to look for a hole that is not there.
     *
     * Matched with is_a() against the class-name STRING rather than with `instanceof`, because
     * Magento\Backend\Model\Auth\Session need not be autoloadable in this harness (only a
     * handful of Magento classes are stubbed under Test/stubs). is_a() on an object never
     * triggers an autoload failure and simply answers false when the named class does not
     * exist - and, unlike comparing get_class(), it still recognises a subclass or a PHPUnit
     * mock of it, which is what a test double for it would be.
     *
     * @param mixed $value A property value held by the guard.
     */
    private function isSessionCollaborator($value): bool
    {
        return is_a($value, Session::class) || is_a($value, self::BACKEND_AUTH_SESSION);
    }

    /**
     * The guard's object-valued properties rendered as "name: Class", for a failure message
     * that says WHAT was found rather than only how many.
     *
     * Reports every object, not just the sessions, so that a count failure is readable
     * whichever collaborator caused it.
     *
     * @param object $guard
     */
    private function describeCollaborators(object $guard): string
    {
        $described = [];
        foreach ($this->propertyValues($guard) as $name => $value) {
            if (is_object($value)) {
                // A PHPUnit double's own class name is generated noise; the class it stands in
                // for is what the reader needs.
                $class = $value instanceof MockObject ? (get_parent_class($value) ?: get_class($value))
                    : get_class($value);
                $described[] = sprintf('$%s: %s', $name, $class);
            }
        }

        return $described === [] ? '(no object-valued properties at all)' : implode(', ', $described);
    }

    /**
     * The structural half of the same property, and the one that stops the whole scheme being
     * quietly bypassed: neither helper may keep a reference to the raw customer session.
     *
     * Both are handed a Magento\Customer\Model\Session by DI and wrap it in a
     * ShopperScopedAddressStores. If either also retained the raw object, a future edit could read
     * or write any of the three attributes directly - no flush, no marker, and every
     * behavioural test in this file still green, because they only exercise the paths that DO
     * go through the guard.
     */
    public function testNeitherHelperKeepsAReferenceToTheRawCustomerSession(): void
    {
        foreach ($this->helpersHoldingShopperScopedStores() as $label => $helper) {
            $held = $this->propertyValues($helper);

            foreach ($held as $name => $value) {
                $this->assertNotInstanceOf(
                    Session::class,
                    $value,
                    sprintf(
                        '%s::$%s holds the raw customer session. The captured-address store and both verdict '
                        . 'caches must be reachable only through ShopperScopedAddressStores, or one direct '
                        . 'getData() re-opens LOQ-16978 with the whole suite green.',
                        $label,
                        $name
                    )
                );
            }

            $guards = array_filter($held, static fn ($value): bool => $value instanceof ShopperScopedAddressStores);
            $this->assertCount(
                1,
                $guards,
                sprintf('%s must hold exactly one ShopperScopedAddressStores to reach its session stores.', $label)
            );
        }
    }

    /**
     * One constructed instance of each class that reaches a shopper-scoped store, keyed by a
     * readable label for the failure message.
     *
     * @return array<string, object>
     */
    private function helpersHoldingShopperScopedStores(): array
    {
        $sessionMock = $this->createSessionDouble(new ArrayObject(), new ArrayObject(['customerId' => null]));
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '9.9.9']);
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturn(self::API_KEY);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(Logger::class);

        return [
            'Helper\Controller' => new Controller(
                $this->createMock(ResultFactory::class),
                $this->createMock(RequestInterface::class),
                $logger,
                $sessionMock,
                $moduleList,
                $helper,
                $serializer
            ),
            'Helper\Validator' => new Validator(
                $logger,
                $sessionMock,
                $this->createMock(RegionFactory::class),
                $moduleList,
                $helper,
                $serializer
            ),
        ];
    }

    /**
     * Build a Validator over a session double whose logged-in identity can be changed
     * mid-test, with its billable connector mock intercepted.
     *
     * @param array<string, mixed> $data Session attributes present before the first request.
     * @return array{validator: Validator, session: ArrayObject, identity: ArrayObject,
     *     requests: ArrayObject}
     */
    private function createShopper(array $data = []): array
    {
        $sessionStore = new ArrayObject($data);
        $identity = new ArrayObject(['customerId' => null]);
        $requests = new ArrayObject();

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '9.9.9']);

        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static function ($configPath) {
                switch ($configPath) {
                    case 'loqate_settings/settings/api_key':
                        // Required twice over: both constructors only build their connector
                        // when it is set, and every verify method short-circuits with
                        // noKeyFound when it is not.
                        return self::API_KEY;
                    case 'loqate_settings/address_settings/address_quality_index':
                        // The BATCH path judges an address against this, not against the
                        // AVC thresholds. Left at '' the comparison 'A' <= '' is a STRING
                        // comparison and fails, so every batch address would be rejected and
                        // nothing would ever be cached - the batch tests would then pass for
                        // the wrong reason.
                        return self::PASSING_AQI;
                    default:
                        // What an untouched admin field leaves behind - in particular
                        // show_advanced_avc_settings != 1, so checkAVCStatus() uses the
                        // baked-in default thresholds.
                        return '';
                }
            }
        );
        $helper->method('getCurrentStore')->willReturn(0);

        // Fails the way the PRODUCTION serializer fails - see the trait. It matters here because
        // the flush this class exists to perform writes null over the three stores, and null is
        // one of the values the production serializer REJECTS outright rather than decoding: a
        // lenient double would let a reader that lost its guard look safe.
        $serializer = $this->createSerializerDouble();

        $validator = new Validator(
            $this->createMock(Logger::class),
            $this->createSessionDouble($sessionStore, $identity),
            $this->createMock(RegionFactory::class),
            $moduleList,
            $helper,
            $serializer
        );

        // The connector is built inside the constructor (new Verify($apiKey)), so the only
        // way to intercept the billable call is to swap the private property afterwards.
        $connector = $this->createMock(Verify::class);
        // One response record per address sent, which is what verifyMultipleAddresses()'
        // row-count guard requires; each record carries both an AVC (read by the
        // single-address path) and an AQI (read by the batch path).
        $connector->method('verifyAddress')->willReturnCallback(
            static function ($params) use ($requests) {
                $requests[] = $params;

                return array_map(
                    static fn () => [['AVC' => self::PASSING_AVC, 'AQI' => self::PASSING_AQI]],
                    array_values((array)($params['Addresses'] ?? [[]]))
                );
            }
        );
        $apiConnector = new ReflectionProperty(Validator::class, 'apiConnector');
        $apiConnector->setAccessible(true);
        $apiConnector->setValue($validator, $connector);

        return [
            'validator' => $validator,
            'session' => $sessionStore,
            'identity' => $identity,
            'requests' => $requests,
        ];
    }

    /** Number of billable Loqate Cleansing requests issued so far. */
    private function apiCallCount(array $shopper): int
    {
        return count($shopper['requests']);
    }

    /**
     * One recognisable value per shopper-scoped store, so a flush that misses one is reported
     * as which store survived rather than as a bare count.
     *
     * @return array<string, mixed>
     */
    private function seededStores(): array
    {
        return [
            self::CAPTURED_ADDRESSES_SESSION_KEY => ['captured'],
            self::VERIFY_CACHE_SESSION_KEY => ['cached' => 'verdict'],
            self::BATCH_VERIFY_CACHE_SESSION_KEY => ['cached' => 'batch verdict'],
        ];
    }

    /**
     * The current value of each shopper-scoped store, in the fixed order seededStores() uses,
     * so the two can be compared directly.
     *
     * @param array $harness Harness from createGuard().
     * @return array<string, mixed>
     */
    private function managedAttributes(array $harness): array
    {
        $values = [];
        foreach (array_keys($this->seededStores()) as $key) {
            $values[$key] = $harness['session'][$key] ?? null;
        }

        return $values;
    }

    /**
     * The production list of attributes the guard flushes together.
     *
     * @return string[]
     */
    private function managedKeys(): array
    {
        $keys = self::readManagedKeys();
        if ($keys === []) {
            $this->fail(
                'ShopperScopedAddressStores::SHOPPER_SCOPED_SESSION_KEYS is missing or empty: that list IS the '
                . 'flush, so an empty one means no store is ever cleared when the shopper changes.'
            );
        }

        return $keys;
    }

    /**
     * managedKeys() for the static data providers, which cannot call assertions.
     *
     * @return string[]
     */
    private static function readManagedKeys(): array
    {
        $reflection = new ReflectionClass(ShopperScopedAddressStores::class);
        if (!$reflection->hasConstant('SHOPPER_SCOPED_SESSION_KEYS')) {
            return [];
        }

        return array_values((array)$reflection->getConstant('SHOPPER_SCOPED_SESSION_KEYS'));
    }

    /**
     * The session attribute the owning identity is recorded in, read from the production
     * constant so these tests describe the real marker rather than a guess at it.
     */
    private function ownerKey(): string
    {
        $key = self::readOwnerKey();
        if ($key === '') {
            $this->fail(
                'ShopperScopedAddressStores::SESSION_OWNER_KEY is not defined: the identity the shopper-scoped '
                . 'stores belong to has to be recorded somewhere, or no identity change can be detected.'
            );
        }

        return $key;
    }

    /**
     * ownerKey() for the static data providers, which cannot call assertions.
     */
    private static function readOwnerKey(): string
    {
        $reflection = new ReflectionClass(ShopperScopedAddressStores::class);

        return $reflection->hasConstant('SESSION_OWNER_KEY')
            ? (string)$reflection->getConstant('SESSION_OWNER_KEY')
            : '';
    }

    /**
     * One of the reserved owner ids, read from the production constant.
     *
     * Read rather than hard-coded so these tests pin WHICH SENTINEL is recorded - the guest
     * or the unreadable one - rather than which integer it currently happens to be; the
     * integers themselves are pinned once, in
     * testTheUnreadableOwnerSentinelCannotCollideWithAGuestOrACustomer(), where the disjointness
     * they are chosen for is the actual subject.
     *
     * @param string $name 'GUEST_OWNER_ID' or 'UNREADABLE_OWNER_ID'.
     */
    private function ownerConstant(string $name): int
    {
        $reflection = new ReflectionClass(ShopperScopedAddressStores::class);
        if (!$reflection->hasConstant($name)) {
            $this->fail(sprintf(
                'ShopperScopedAddressStores::%s is not defined. The guard needs three DISJOINT owner classes - a '
                . 'positive customer id, the guest, and "the session answered an id we cannot read" - or two '
                . 'different identities compare equal and the stores are not flushed between them.',
                $name
            ));
        }

        return (int)$reflection->getConstant($name);
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
