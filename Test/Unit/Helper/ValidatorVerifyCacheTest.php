<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for the verify-request de-duplication cache in Validator.
 *
 * Regression cover for LOQ-16969: Validator::verifyAddress() calls the *billable*
 * Loqate Cleansing API on every invocation, and it is wired in from SIX call
 * statements across five classes: Plugin\Frontend\CheckoutShippingInformation.php:32,
 * Plugin\Frontend\CheckoutBillingAddress.php:34 (which savePaymentInformation replays
 * at place order, so that one statement runs twice in a checkout),
 * Observer\QuoteSubmitBefore.php:85 and :109,
 * Plugin\Frontend\CustomerAccountAddress.php:37 and
 * Plugin\Admin\ValidateAddress.php:42. A single checkout of a single address therefore
 * invokes it 3-5 times depending on Magento version and checkout front-end, and
 * account saves and admin re-tests replay the same address on top of that. Customers
 * are charged for every one of those. The only existing guard,
 * "captured_addresses", matches solely addresses picked from the Loqate Capture
 * lookup, so a typed address is re-verified - and re-billed - on every one of those
 * paths. (The two verifyMultipleAddresses() sites, Plugin\Admin\OrderSave.php:49 and
 * Plugin\Admin\ValidateImportAddress.php:53, are deliberately NOT covered by this
 * cache - LOQ-16976 - and so are not covered here either.)
 *
 * The contract asserted here: identical addresses are verified once per session
 * and the verdict is replayed from a bounded, session-scoped cache keyed by the
 * canonical address signature. The two keys are deliberately ASYMMETRIC:
 *  - a SUCCESS is keyed WITHOUT the region/county, because capture.js and
 *    parseAddress() both rewrite it ("Meath" becomes "Co. Meath", a region_id is
 *    re-resolved to a name) and that is exactly the re-billing the customer
 *    reported - see testMutatedCountyNameDoesNotTriggerASecondBillableApiCall();
 *  - a REJECTION is keyed WITH it, because a shopper who corrects a wrong county
 *    must be re-verified rather than locked out of checkout by a replayed
 *    rejection - see testCorrectingOnlyTheCountyAfterARejectionIsVerifiedAgain().
 * The rejection (strict) key is also the one READ FIRST, so reverting to a county
 * Loqate explicitly rejected replays that rejection instead of the county-agnostic
 * success - see testRevertingToTheRejectedCountyReplaysTheCachedRejection() and, for
 * the residual bypass that is deliberately kept,
 * testACountyVariantLoqateNeverRejectedStillPassesFromTheCachedSuccess().
 * Both verify keys also cover the FULL joined street Loqate is actually sent, not
 * just the two lines the captured-address signature carries - see
 * testEditingAStreetLineBeyondTheSecondIsVerifiedAgain(). More generally, the strict
 * key must project EVERY field parseAddress() sends to Loqate and the lossy key
 * exactly that list minus the county; that invariant is pinned structurally by
 * testStrictCacheKeyProjectsEveryFieldSentToLoqate() and, load-bearingly, per field
 * by testEditingAnyFieldSentToLoqateAfterARejectionIsVerifiedAgain() and
 * testEditingAnyFieldSentToLoqateExceptTheCountyIsVerifiedAgainAfterASuccess().
 *
 * Entries are additionally namespaced per STORE VIEW and by a fingerprint of the
 * RESOLVED AVC thresholds, since the thresholds a verdict depends on are read at
 * SCOPE_STORE and a merchant can change them mid-session - see
 * testChangingAnAppliedAvcThresholdInvalidatesTheCachedVerdict() and, for the
 * over-invalidation that must NOT happen,
 * testEditingIgnoredThresholdFieldsDoesNotInvalidateAnyVerdict(). Genuine edits must
 * still be verified; transport failures and responses with no readable AVC must never
 * be cached, so the next attempt retries. The debug instrumentation that makes the
 * saving auditable must never write an address or a signature to the log - see
 * testCacheOutcomeLoggingNeverLeaksTheAddressOrTheSignature().
 *
 * "Session-scoped" is itself part of the contract and is asserted here: a verdict
 * is customer data, so it must be readable by the same shopper on a later request
 * (the place-order re-verification this ticket fixes) yet invisible to any other
 * shopper and gone once the session ends. Each shopper in these tests therefore
 * gets its own session double AND its own connector mock (see createShopper()),
 * so a store moved into a static property, Registry or CacheInterface shows up as
 * a missing billable call on the second shopper's own connector.
 */
class ValidatorVerifyCacheTest extends TestCase
{
    /** Any non-empty key makes verifyAddress() reach the billable call. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /**
     * AVC strictly better than the baked-in default threshold "P40-U00-P0-95"
     * that checkAVCStatus() falls back to when advanced settings are off, so
     * checkAVCStatus() returns true => address accepted.
     */
    private const PASSING_AVC = 'V55-I22-P9-99';

    /** AVC poorer than the default threshold in every field => address rejected. */
    private const FAILING_AVC = 'U00-U00-P0-10';

    /** Session data key the verify verdict cache must live under. */
    private const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /** A Magento-shaped address as it arrives from checkout. */
    private const ADDRESS = [
        'street' => ['1 High St', 'Flat 2'],
        'city' => 'London',
        'region' => 'Greater London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
    ];

    /**
     * Base address for the per-mapped-field tests: carries every Magento key
     * Validator::ADDRESS_MAPPING reads except street_1/street_2.
     */
    private const FIELD_EDIT_BASE = [
        'street' => ['1 High St', 'Flat 2'],
        'city' => 'London',
        'region' => 'Greater London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
    ];

    /**
     * Base address for the street_1/street_2 mappings, which is the same address WITHOUT
     * the 'street' key.
     *
     * It has to omit 'street': parseAddress() applies ADDRESS_MAPPING first and then
     * overwrites Address1/Address2 from extractStreetLines(), so while a 'street' value is
     * present an edit to street_1 changes nothing at all - not the key and not the request
     * either. The fields are only observable in the shape where Magento supplies them
     * alone, which is the shape this base models.
     */
    private const FIELD_EDIT_BASE_STREET_PARTS = [
        'street_1' => '1 High St',
        'street_2' => 'Flat 2',
        'city' => 'London',
        'region' => 'Greater London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
    ];

    /**
     * One "base address + edit to exactly this field" fixture per Magento key in
     * Validator::ADDRESS_MAPPING.
     *
     * This map is the coverage gate of the invariant behind LOQ-16969: adding a mapping
     * without adding a fixture makes the two per-field tests FAIL, so a field can never
     * be sent to Loqate without something pinning that it also reaches the cache key.
     */
    private const FIELD_EDIT_FIXTURES = [
        'street' => [
            'base' => self::FIELD_EDIT_BASE,
            'edit' => ['street' => ['9 Different Road', 'Flat 2']],
        ],
        'street_1' => [
            'base' => self::FIELD_EDIT_BASE_STREET_PARTS,
            'edit' => ['street_1' => '11 High St'],
        ],
        'street_2' => [
            'base' => self::FIELD_EDIT_BASE_STREET_PARTS,
            'edit' => ['street_2' => 'Flat 3'],
        ],
        'city' => [
            'base' => self::FIELD_EDIT_BASE,
            'edit' => ['city' => 'Manchester'],
        ],
        'region' => [
            'base' => self::FIELD_EDIT_BASE,
            'edit' => ['region' => 'Co. Meath'],
        ],
        'postcode' => [
            'base' => self::FIELD_EDIT_BASE,
            'edit' => ['postcode' => 'M1 1AA'],
        ],
        'country_id' => [
            'base' => self::FIELD_EDIT_BASE,
            'edit' => ['country_id' => 'IE'],
        ],
    ];

    /** @var Validator The Validator under test (the "primary" shopper). */
    private $validator;

    /** @var Verify|MockObject The billable API client of the primary shopper. */
    private $apiConnector;

    /** @var ArrayObject Payloads of every primary apiConnector->verifyAddress() call, in order. */
    private $apiRequests;

    /** @var ArrayObject Backing store for the primary customer session, so data actually persists. */
    private $sessionStore;

    /** @var array The primary shopper harness, as returned by createShopper(). */
    private $shopper;

    /** @var array region_id => region name, used by the RegionFactory mock. */
    private $regionNames = [];

    protected function setUp(): void
    {
        $this->regionNames = [];

        // Every test starts with one shopper; the cross-session isolation tests
        // add further, fully independent ones through createShopper().
        $this->shopper = $this->createShopper();
        $this->validator = $this->shopper['validator'];
        $this->apiConnector = $this->shopper['connector'];
        $this->apiRequests = $this->shopper['requests'];
        $this->sessionStore = $this->shopper['session'];
    }

    /**
     * Build one independent "shopper": a Validator wired to its own customer
     * session double and its own billable connector mock.
     *
     * Everything that must not be shared between shoppers is created per call, so
     * a leak of one shopper's verdict into another's Validator shows up as an
     * unexpected call count on that shopper's own connector. Only $this->regionNames
     * is shared, because the directory region table is install-wide, not per shopper.
     *
     * Pass an existing $session to model the SAME shopper on a later request
     * (a new Validator instance, same browser session) - which is exactly the
     * place-order re-verification path LOQ-16969 is about. Pass a $storeId to
     * model a request handled by a different store view off the same session, and an
     * $unserialize callback to model a serializer that rejects what it is given.
     *
     * Pass a $config ArrayObject to model store configuration: it is read LIVE on every
     * getConfigValue() call, so a test can change a value between two verifications the
     * way a merchant saving the admin form does, and the same Validator instance sees the
     * new value (see the AVC threshold fingerprint tests).
     *
     * @param ArrayObject|null $session Session backing store to reuse, or null for a new session.
     * @param int $storeId Store view the request is being handled by (Data::getCurrentStore()).
     * @param callable|null $unserialize Replacement SerializerInterface::unserialize() behaviour.
     * @param ArrayObject|null $config Live store configuration, config path => value.
     * @return array{validator: Validator, connector: Verify&MockObject, requests: ArrayObject,
     *     session: ArrayObject, config: ArrayObject, events: ArrayObject}
     */
    private function createShopper(
        ?ArrayObject $session = null,
        int $storeId = 0,
        ?callable $unserialize = null,
        ?ArrayObject $config = null
    ): array {
        $sessionStore = $session ?? new ArrayObject();
        $requests = new ArrayObject();
        $configStore = $config ?? new ArrayObject();

        // Single ordered timeline of everything the Validator emitted: every log record
        // AND every billable request, so the tests can assert both WHAT was logged (it
        // must never contain the address) and WHEN (a miss must be logged before the
        // request it accounts for).
        $events = new ArrayObject();

        $logger = $this->createMock(Logger::class);
        $recordLog = static function (string $level) use ($events): callable {
            return static function ($message, array $context = []) use ($events, $level) {
                $events[] = ['type' => $level, 'message' => (string)$message, 'context' => $context];
            };
        };
        $logger->method('debug')->willReturnCallback($recordLog('debug'));
        $logger->method('info')->willReturnCallback($recordLog('info'));

        // The shared Test/stubs Session is a no-op (getData() returns null,
        // setData() stores nothing), so the dedup cache could never survive
        // between calls. This mock retains data in $sessionStore, which also lets
        // the tests assert exactly what was written - and, because the store is
        // per shopper, that nothing crosses between them.
        //
        // getData()/setData() have to be *added* to the double when the real
        // Magento\Customer\Model\Session is present, because it does not declare
        // them: SessionManager __call-forwards them to Session\Storage, so
        // createMock() could not configure them. The Test/stubs Session used when
        // Magento is absent does declare them, and PHPUnit refuses to "add" a
        // method that already exists - hence the method_exists() filter, which
        // keeps this double working on both sides.
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

        // parseAddress() resolves region_id through RegionFactory; return a
        // minimal region whose name comes from $this->regionNames.
        $regionFactory = $this->createMock(RegionFactory::class);
        $regionFactory->method('create')->willReturnCallback(
            function () {
                return new class ($this->regionNames) {
                    /** @var array */
                    private $names;

                    /** @var string */
                    private $name = '';

                    public function __construct(array $names)
                    {
                        $this->names = $names;
                    }

                    public function load($regionId)
                    {
                        $this->name = $this->names[$regionId] ?? '';

                        return $this;
                    }

                    public function getName()
                    {
                        return $this->name;
                    }
                };
            }
        );

        // The constructor reads the module's setup_version to build the source string.
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '9.9.9']);

        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static function ($configPath) use ($configStore) {
                // A non-empty API key is required twice: the constructor only
                // builds the connector when it is set, and verifyAddress()
                // short-circuits with noKeyFound when it is not.
                if ($configPath === 'loqate_settings/settings/api_key') {
                    return self::API_KEY;
                }

                // Anything not explicitly configured reads as empty, which is what the
                // admin form leaves behind for an untouched field: in particular
                // show_advanced_avc_settings != 1 makes checkAVCStatus() use the
                // baked-in default thresholds.
                return $configStore[$configPath] ?? '';
            }
        );
        // Verdicts are namespaced per STORE VIEW, because the AVC thresholds they
        // depend on are read at SCOPE_STORE (Data::getConfigValue()).
        //
        // This has to be stubbed explicitly per shopper: getCurrentStore() is
        // declared int, so an unstubbed createMock(Data::class) would auto-return 0
        // for EVERY shopper, collapsing all of them into one cache namespace and
        // making the scoping tests below pass (or fail) for the wrong reason.
        $helper->method('getCurrentStore')->willReturn($storeId);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn ($value) => json_encode($value)
        );
        $serializer->method('unserialize')->willReturnCallback(
            $unserialize ?? static fn ($value) => json_decode($value, true)
        );

        $validator = new Validator(
            $logger,
            $sessionMock,
            $regionFactory,
            $moduleList,
            $helper,
            $serializer
        );

        // The connector is built inside the constructor (new Verify($apiKey)),
        // so the only way to intercept the billable call is to swap the private
        // property afterwards.
        $connector = $this->createMock(Verify::class);
        $reflection = new ReflectionProperty(Validator::class, 'apiConnector');
        $reflection->setAccessible(true);
        $reflection->setValue($validator, $connector);

        return [
            'validator' => $validator,
            'connector' => $connector,
            'requests' => $requests,
            'session' => $sessionStore,
            'config' => $configStore,
            'events' => $events,
        ];
    }

    /**
     * THE billing guarantee. One address verified twice (as happens on every
     * checkout: shipping-information POST then billing save) must cost exactly
     * one billable Cleansing API request, and the second call must still return
     * the same verdict.
     */
    public function testSameAddressVerifiedTwiceIssuesOneBillableApiCall(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $first = $this->validator->verifyAddress(self::ADDRESS);
        $second = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'An address already verified in this session must not be sent to the billable Loqate API again.'
        );
        $this->assertSame(['error' => false], $first);
        $this->assertSame(['error' => false], $second, 'The cached verdict must match the live verdict.');
    }

    /**
     * The verdict must be cached where the rest of the module expects session
     * state to live: an assoc array under "loqate_verified_addresses", holding
     * serialised verdicts (mirroring the captured_addresses precedent).
     */
    public function testSuccessfulVerdictIsStoredSerialisedUnderTheSessionKey(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);

        $store = $this->verdictStore();
        $this->assertCount(
            1,
            $store,
            'A verified address must leave exactly one entry in the "'
            . self::VERIFY_CACHE_SESSION_KEY . '" session store.'
        );
        $this->assertNotSame(
            '',
            (string)array_key_first($store),
            'The cache must be keyed, by the address signature namespaced to the store view.'
        );
        $this->assertSame(
            ['error' => false],
            json_decode((string)reset($store), true),
            'The verdict must be stored serialised, so it can be replayed verbatim.'
        );
    }

    /**
     * The verdict cache is customer data, so it must be scoped to one shopper's
     * session and nothing wider. Two shoppers verifying the same address must each
     * pay for their own verification: if the store were ever "optimised" into a
     * static property, Registry or CacheInterface, the second shopper would be
     * served the first's verdict - suppressing a verification that never happened
     * for them, and leaking one shopper's address state into another's checkout.
     */
    public function testTwoSeparateSessionsVerifyingTheSameAddressAreEachBilledOnce(): void
    {
        $shopperA = $this->createShopper();
        $shopperB = $this->createShopper();
        $this->stubShopperResponses($shopperA, [self::acceptedResponse()]);
        $this->stubShopperResponses($shopperB, [self::acceptedResponse()]);

        $resultA = $shopperA['validator']->verifyAddress(self::ADDRESS);
        $resultB = $shopperB['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->shopperCallCount($shopperA),
            'The first shopper must verify the address against the API exactly once.'
        );
        $this->assertSame(
            1,
            $this->shopperCallCount($shopperB),
            'A second shopper in a different session must NOT be served the first shopper\'s cached verdict: '
            . 'the verdict cache must be per customer session, never static/Registry/CacheInterface state.'
        );
        $this->assertSame(['error' => false], $resultA);
        $this->assertSame(['error' => false], $resultB);
        $this->assertCount(
            1,
            $this->shopperStore($shopperA),
            'Each session must hold its own verdict store.'
        );
        $this->assertCount(1, $this->shopperStore($shopperB), 'Each session must hold its own verdict store.');
    }

    /**
     * The mirror image, and the case this ticket actually fixes: the SAME shopper
     * on a later request. Checkout builds a new Validator instance per request, so
     * the verdict may not live in instance state - it has to be read back out of
     * the shared customer session. The place-order re-verification path
     * (savePaymentInformation replaying the billing save, plus both
     * QuoteSubmitBefore observer calls) is exactly this.
     */
    public function testALaterRequestInTheSameSessionReplaysTheCachedVerdictWithoutANewApiCall(): void
    {
        $firstRequest = $this->createShopper();
        $this->stubShopperResponses($firstRequest, [self::acceptedResponse()]);
        $firstRequest['validator']->verifyAddress(self::ADDRESS);

        // Same browser session, brand new Validator (as on the next HTTP request).
        // Its connector is stubbed to REJECT, so a verdict of "valid" can only have
        // come out of the session store and not off the wire.
        $laterRequest = $this->createShopper($firstRequest['session']);
        $this->stubShopperResponses($laterRequest, [self::rejectedResponse()]);

        $result = $laterRequest['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            0,
            $this->shopperCallCount($laterRequest),
            'A new Validator instance in the same session must replay the cached verdict, not re-bill the API.'
        );
        $this->assertSame(
            ['error' => false],
            $result,
            'The replayed verdict must be the one written to the session by the earlier request.'
        );
        $this->assertSame(
            $this->shopperStore($firstRequest),
            $this->shopperStore($laterRequest),
            'Both instances must be reading and writing the one store held on the session object.'
        );
    }

    /**
     * A verdict may not outlive the session that earned it. In a BRAND-NEW browser session
     * - a different visitor, a cleared cookie, a session garbage-collected between visits -
     * there is no backing data to read, so the address has to be verified against the API
     * again. That proves the cache is session state and is not hidden anywhere with a
     * longer lifetime (a static, the registry, a cache backend, the customer entity).
     *
     * IT DOES NOT MODEL A LOGOUT OR A SESSION-ID REGENERATION, and must not be read as
     * doing so - that is the belief LOQ-16978 exists to correct. Magento calls
     * session_regenerate_id() on login and on logout, which changes the session ID while
     * PRESERVING every value in $_SESSION, so the cache emphatically DOES survive both.
     * What clears it there is Helper\ShopperScopedSession, not PHP; the logout and login
     * cases are covered by ShopperScopedSessionTest::testACachedVerdictDoesNotSurviveALogin()
     * and its siblings.
     */
    public function testCachedVerdictDoesNotSurviveASessionReset(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->assertCount(1, $this->verdictStore(), 'The verdict must be written to the session.');

        $this->startBrandNewSession($this->shopper);

        $this->assertSame(
            [],
            $this->verdictStore(),
            'A brand-new session must start with an empty verdict cache: it may not be held anywhere else.'
        );

        $result = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'In a brand-new session the address must be verified against the API again.'
        );
        $this->assertSame(['error' => false], $result);
        $this->assertCount(
            1,
            $this->verdictStore(),
            'The re-verified verdict must be written to the new session.'
        );
    }

    /**
     * Within a single session, entries are isolated by signature alone: two
     * genuinely different addresses get their own key and their own verdict, and
     * neither may be answered with the other's. Asserted with opposite verdicts,
     * so a mis-keyed cache shows up as the wrong answer and not just a wrong count.
     */
    public function testTwoDifferentAddressesInOneSessionAreCachedUnderDistinctKeys(): void
    {
        $this->stubApiResponses([self::acceptedResponse(), self::rejectedResponse()]);

        $valid = self::ADDRESS;
        $invalid = [
            'street' => ['999 Nowhere Lane'],
            'city' => 'Manchester',
            'region' => 'Greater Manchester',
            'postcode' => 'M1 1AA',
            'country_id' => 'GB',
        ];

        $this->validator->verifyAddress($valid);
        $this->validator->verifyAddress($invalid);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'Two different addresses must each be verified once.'
        );
        $store = $this->verdictStore();
        $this->assertCount(2, $store, 'Each address must get its own entry in the verdict cache.');
        $this->assertCount(
            2,
            array_unique(array_keys($store)),
            'Different addresses must never share a cache key.'
        );

        $replayedValid = $this->validator->verifyAddress($valid);
        $replayedInvalid = $this->validator->verifyAddress($invalid);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'Both addresses are now cached, so replaying them must not cost further API calls.'
        );
        $this->assertSame(
            ['error' => false],
            $replayedValid,
            'The valid address must not be served the other address\'s rejection.'
        );
        $this->assertTrue(
            $replayedInvalid['error'],
            'The invalid address must not be served the other address\'s acceptance.'
        );
    }

    /**
     * The regression the customer reported: capture.js mutates the county
     * ("Meath" -> "Co. Meath") between the shipping and billing saves. The
     * county is not part of the canonical signature, so this is still the same
     * address and must not be re-billed.
     *
     * This is the mirror image of
     * testCorrectingOnlyTheCountyAfterARejectionIsVerifiedAgain() and the reason the
     * two cache keys are deliberately ASYMMETRIC: a SUCCESS is keyed without the
     * county (lossy) so a rewritten county cannot re-bill it - the whole point of
     * LOQ-16969 - while a REJECTION is keyed WITH the county (strict) so correcting a
     * wrong county is re-verified instead of replaying a rejection forever. Making
     * both keys the same, in either direction, breaks one of these two tests: do not
     * "simplify" the asymmetry away.
     */
    public function testMutatedCountyNameDoesNotTriggerASecondBillableApiCall(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $address = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'region' => 'Meath',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];

        $this->validator->verifyAddress($address);
        $this->validator->verifyAddress(array_merge($address, ['region' => 'Co. Meath']));

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'A county/province rewritten by capture.js must not make the same address billable twice.'
        );
    }

    /**
     * The checkout dead-end that the strict rejection key exists to prevent, and the
     * most expensive failure mode of a cached rejection: a lost order.
     *
     * A shopper submits an address with the WRONG county, Loqate rejects it, and the
     * rejection is cached. Every later checkout call path replays that rejection for
     * free (good - a rejection blocks checkout, so re-billing it is waste). But the
     * moment the shopper fixes ONLY the county - the single field a rejection is most
     * likely to be about, and the field the SUCCESS key deliberately ignores - the
     * address must be sent to Loqate again. Were the rejection keyed on the lossy
     * signature, the corrected address would hit the same key, be served the stale
     * rejection with no API call, and the shopper could never get out of checkout for
     * the rest of the session however often they corrected the county.
     *
     * The sequence continues in testRevertingToTheRejectedCountyReplaysTheCachedRejection()
     * (revert to the rejected county) and
     * testACountyVariantLoqateNeverRejectedStillPassesFromTheCachedSuccess() (the
     * residual bypass that is deliberately kept), which together pin the ORDER the two
     * keys are read in.
     */
    public function testCorrectingOnlyTheCountyAfterARejectionIsVerifiedAgain(): void
    {
        // Rejected first; accepted once the county is right.
        $this->stubApiResponses([self::rejectedResponse(), self::acceptedResponse()]);

        $wrongCounty = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'region' => 'Meath',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];

        // (1) First submission: rejected off the wire.
        $rejected = $this->validator->verifyAddress($wrongCounty);

        $this->assertSame(1, $this->apiCallCount(), 'The first submission must reach the API.');
        $this->assertTrue($rejected['error'], 'The address must be rejected.');

        // (2) The identical address, county included, replayed by a later checkout
        // call path: served from the cache, still one billable request.
        $replayed = $this->validator->verifyAddress($wrongCounty);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'An unchanged rejected address must be replayed from the cache, not re-billed on every call path.'
        );
        $this->assertTrue($replayed['error'], 'The cached rejection must still reject the unchanged address.');
        $this->assertSame('The provided address is invalid.', (string)$replayed['message']);

        // (3) ONLY the county corrected: this must be verified again and is free to
        // succeed, or the shopper is locked out of checkout for the whole session.
        $corrected = $this->validator->verifyAddress(array_merge($wrongCounty, ['region' => 'Co. Meath']));

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'Correcting the county of a rejected address must trigger a fresh verification: '
            . 'a rejection cached without the county is a permanent checkout dead-end.'
        );
        $this->assertSame(
            ['error' => false],
            $corrected,
            'The corrected address must get the live verdict, not the cached rejection.'
        );
    }

    /**
     * The same dead-end guard for the other address shape checkout uses: the county
     * arrives as a region_id that parseAddress() resolves through RegionFactory
     * (Quote\Address and the admin grid both take this path), so the strict rejection
     * key has to see the resolved name, not the raw id.
     */
    public function testCorrectingOnlyTheRegionIdAfterARejectionIsVerifiedAgain(): void
    {
        $this->stubApiResponses([self::rejectedResponse(), self::acceptedResponse()]);
        $this->regionNames = [100 => 'Meath', 101 => 'Louth'];

        $address = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];

        $rejected = $this->validator->verifyAddress(array_merge($address, ['region_id' => 100]));
        $this->assertSame(1, $this->apiCallCount(), 'The first submission must reach the API.');
        $this->assertTrue($rejected['error']);

        $replayed = $this->validator->verifyAddress(array_merge($address, ['region_id' => 100]));
        $this->assertSame(
            1,
            $this->apiCallCount(),
            'An unchanged rejected address must be replayed from the cache.'
        );
        $this->assertTrue($replayed['error']);

        $corrected = $this->validator->verifyAddress(array_merge($address, ['region_id' => 101]));

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'Picking a different region_id after a rejection must trigger a fresh verification.'
        );
        $this->assertSame(['error' => false], $corrected);
    }

    /**
     * The read ORDER of the two keys, which nothing else in this file pins.
     *
     * verifyAddress() reads the strict (rejection) key BEFORE the lossy (success) one.
     * Both are plain cache reads, so the order costs nothing, and exactly one case
     * changes: a shopper who is rejected, corrects the county, is accepted, and then
     * REVERTS to the county Loqate explicitly rejected. Strict-read-first hands back
     * that recorded rejection; lossy-read-first would hand back the county-agnostic
     * success and let an address Loqate said no to through checkout.
     *
     * Flipping the two reads in verifyAddress() leaves every other test in this file
     * green, so this test is what makes the order load-bearing. Neither variant issues
     * a further billable request - the difference is purely which cached verdict wins.
     */
    public function testRevertingToTheRejectedCountyReplaysTheCachedRejection(): void
    {
        $this->stubApiResponses([self::rejectedResponse(), self::acceptedResponse()]);

        $wrongCounty = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'region' => 'Meath',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];
        $rightCounty = array_merge($wrongCounty, ['region' => 'Co. Meath']);

        // (1) Rejected with the wrong county, (2) accepted once it is corrected.
        $this->assertTrue($this->validator->verifyAddress($wrongCounty)['error']);
        $this->assertSame(['error' => false], $this->validator->verifyAddress($rightCounty));
        $this->assertSame(2, $this->apiCallCount(), 'Both submissions must have reached the API.');

        // (3) Back to the county Loqate explicitly rejected.
        $reverted = $this->validator->verifyAddress($wrongCounty);

        $this->assertTrue(
            $reverted['error'],
            'Reverting to a county Loqate explicitly REJECTED must replay that rejection: the strict key '
            . 'is read before the county-agnostic success key, so the recorded "no" wins.'
        );
        $this->assertSame('The provided address is invalid.', (string)$reverted['message']);
        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The rejection comes from the strict cache entry, so reverting must not cost a billable request.'
        );
    }

    /**
     * The deliberately-accepted residual bypass, and the reason the SUCCESS key stays
     * lossy (LOQ-16969): once ANY county variant of an address is accepted, every county
     * variant Loqate has NOT explicitly rejected also passes from the cache for the rest
     * of the session.
     *
     * This is a trade-off, not an oversight. Keying successes strictly would re-bill
     * exactly what this ticket fixes (capture.js rewrites "Meath" to "Co. Meath",
     * parseAddress() re-resolves a region_id to a name), and the pre-existing
     * captured_addresses guard has always behaved this way. It is pinned here so that
     * anyone tightening it does so knowingly, and so the scope of the bypass stays
     * exactly this: variants Loqate never rejected, since a rejected one is caught by
     * the strict read - see testRevertingToTheRejectedCountyReplaysTheCachedRejection().
     */
    public function testACountyVariantLoqateNeverRejectedStillPassesFromTheCachedSuccess(): void
    {
        $this->stubApiResponses([self::rejectedResponse(), self::acceptedResponse()]);

        $wrongCounty = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'region' => 'Meath',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];

        $this->assertTrue($this->validator->verifyAddress($wrongCounty)['error']);
        $this->assertSame(
            ['error' => false],
            $this->validator->verifyAddress(array_merge($wrongCounty, ['region' => 'Co. Meath']))
        );
        $this->assertSame(2, $this->apiCallCount(), 'Both submissions must have reached the API.');

        // A THIRD county spelling, which Loqate has never been asked about: it is served
        // the cached success, because the success key excludes the county.
        $neverJudged = $this->validator->verifyAddress(array_merge($wrongCounty, ['region' => 'County Meath']));

        $this->assertSame(
            ['error' => false],
            $neverJudged,
            'A county variant Loqate never rejected is deliberately served the cached success: the success '
            . 'key excludes the county so a rewritten county cannot re-bill the same address.'
        );
        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The accepted trade-off is that this costs no further billable request either.'
        );
    }

    /**
     * Same address, but Magento resolves a different region record ("Dublin" vs
     * "Dublin 1"). Region is resolved via RegionFactory into Address4, which the
     * signature deliberately excludes, so this must not re-bill either.
     */
    public function testDifferentRegionIdDoesNotTriggerASecondBillableApiCall(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);
        $this->regionNames = [100 => 'Dublin', 101 => 'Dublin 1'];

        $address = [
            'street' => ['4 O\'Connell Street'],
            'city' => 'Dublin',
            'postcode' => 'D01 XXXX',
            'country_id' => 'IE',
        ];

        $this->validator->verifyAddress(array_merge($address, ['region_id' => 100]));
        $this->validator->verifyAddress(array_merge($address, ['region_id' => 101]));

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'A different region_id on an otherwise identical address must not make it billable twice.'
        );
    }

    /**
     * The fix must not suppress legitimate verification: editing any field that
     * forms part of the signature has to be verified against the API again,
     * otherwise a shopper could smuggle an unverified address past checkout.
     */
    #[DataProvider('meaningfulEditProvider')]
    public function testMeaningfulEditIsVerifiedAgain(array $edit): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress(array_merge(self::ADDRESS, $edit));

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'A genuinely edited address must be verified again, not served from the cache.'
        );
    }

    public static function meaningfulEditProvider(): array
    {
        return [
            'street replaced' => [['street' => ['9 Different Road']]],
            'house number changed' => [['street' => ['11 High St', 'Flat 2']]],
            'second street line dropped' => [['street' => ['1 High St']]],
            // Street lines 3 and 4 exist whenever customer/address/street_lines is
            // raised above 2, and are sent to Loqate inside the joined 'Address'.
            // buildAddressSignature() cannot see them (it keys Address1/Address2
            // only), so the verify keys have to carry the joined street as well or
            // these edits would be answered from the cache for an address Loqate was
            // never asked about.
            'third street line added' => [['street' => ['1 High St', 'Flat 2', 'Block C']]],
            'fourth street line added' => [['street' => ['1 High St', 'Flat 2', 'Block C', 'Floor 9']]],
            'city changed' => [['city' => 'Manchester']],
            'postcode changed' => [['postcode' => 'M1 1AA']],
            'country changed' => [['country_id' => 'IE']],
        ];
    }

    /**
     * The same guarantee where it is hardest to get right, and the regression M-A fixed:
     * two addresses that are identical in every field the captured-address signature
     * covers and differ ONLY beyond street line 2.
     *
     * Magento supports up to four street lines (customer/address/street_lines), and
     * parseAddress() sends all of them joined as 'Address' - but buildAddressSignature()
     * projects Address1/Address2/Address3/PostalCode/Country only, because it is also
     * compared against the captured-address store, whose entries have no 'Address' key.
     * So unless the verify keys extend it with the joined street, editing line 3 or 4
     * leaves the key untouched and the shopper is served a verdict for a different
     * address: an unverified address smuggled through checkout.
     *
     * @param mixed $first Magento street value as first submitted (array or newline string).
     * @param mixed $second Magento street value after the edit.
     */
    #[DataProvider('streetLineBeyondTheSecondProvider')]
    public function testEditingAStreetLineBeyondTheSecondIsVerifiedAgain($first, $second): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => $first]));
        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => $second]));

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The two addresses differ only beyond street line 2, but that is still a different address: '
            . 'it must be verified against the API rather than served the first verdict.'
        );
        $this->assertCount(2, $this->verdictStore(), 'Each of the two addresses must get its own cache entry.');

        // Both really were sent as different addresses, so the difference is a genuine
        // one and not an artefact of the harness.
        $this->assertNotSame(
            $this->apiRequests[0]['Addresses'][0]['Address'] ?? null,
            $this->apiRequests[1]['Addresses'][0]['Address'] ?? null,
            'The joined street sent to Loqate must differ between the two submissions.'
        );
        $this->assertSame(
            $this->apiRequests[0]['Addresses'][0]['Address1'] ?? null,
            $this->apiRequests[1]['Addresses'][0]['Address1'] ?? null,
            'Street line 1 must be identical: this test is only about lines beyond the second.'
        );
        $this->assertSame(
            $this->apiRequests[0]['Addresses'][0]['Address2'] ?? null,
            $this->apiRequests[1]['Addresses'][0]['Address2'] ?? null,
            'Street line 2 must be identical: this test is only about lines beyond the second.'
        );
    }

    public static function streetLineBeyondTheSecondProvider(): array
    {
        return [
            'third line edited' => [
                ['1 High St', 'Flat 2', 'Block C'],
                ['1 High St', 'Flat 2', 'Block D'],
            ],
            'third line added' => [
                ['1 High St', 'Flat 2'],
                ['1 High St', 'Flat 2', 'Block C'],
            ],
            'third line removed' => [
                ['1 High St', 'Flat 2', 'Block C'],
                ['1 High St', 'Flat 2'],
            ],
            'fourth line edited' => [
                ['1 High St', 'Flat 2', 'Block C', 'Floor 9'],
                ['1 High St', 'Flat 2', 'Block C', 'Floor 10'],
            ],
            'fourth line added' => [
                ['1 High St', 'Flat 2', 'Block C'],
                ['1 High St', 'Flat 2', 'Block C', 'Floor 9'],
            ],
            // Lines 3 and 4 swapped: every individual line is unchanged, only their
            // order differs, so nothing but the joined street can tell these apart.
            'third and fourth lines swapped' => [
                ['1 High St', 'Flat 2', 'Block C', 'Floor 9'],
                ['1 High St', 'Flat 2', 'Floor 9', 'Block C'],
            ],
            // Same in the newline-string street shape the quote call paths deliver.
            'third line edited in the quote street shape' => [
                "1 High St\nFlat 2\nBlock C\n",
                "1 High St\nFlat 2\nBlock D\n",
            ],
        ];
    }

    /**
     * The STRUCTURAL half of the invariant the whole scheme rests on: the strict
     * (rejection) key must project EVERY field parseAddress() sends to Loqate - every
     * value of Validator::ADDRESS_MAPPING - and the lossy (success) key must be exactly
     * that list minus the county.
     *
     * READ THIS BEFORE TRUSTING IT. This test cannot catch a field being ADDED to
     * ADDRESS_MAPPING, and it is not claimed to: strictSignatureFields() and
     * verifySignatureFields() DERIVE their lists from ADDRESS_MAPPING, so a new mapping
     * extends both keys by construction and the sets stay equal. Adding
     * 'company' => 'Company' to ADDRESS_MAPPING leaves this test green (verified with
     * that exact mutant). What it does pin is the derivation itself: hand-writing either
     * list again, dropping a field from one of them, or naming a COUNTY_FIELD that is not
     * in ADDRESS_MAPPING at all, all fail here. The BEHAVIOURAL defence - that a mapped
     * field genuinely reaches the key and genuinely costs a second billable request when
     * it changes - is
     * testEditingAnyFieldSentToLoqateAfterARejectionIsVerifiedAgain() and
     * testEditingAnyFieldSentToLoqateExceptTheCountyIsVerifiedAgainAfterASuccess(),
     * which iterate ADDRESS_MAPPING and fail on an unpinned new field.
     */
    public function testStrictCacheKeyProjectsEveryFieldSentToLoqate(): void
    {
        $sentToLoqate = array_values(Validator::ADDRESS_MAPPING);
        $county = (string)$this->privateConstant('COUNTY_FIELD');
        $strict = $this->invokePrivate('strictSignatureFields', []);
        $lossy = $this->invokePrivate('verifySignatureFields', []);

        $this->assertContains(
            $county,
            $sentToLoqate,
            'COUNTY_FIELD must be one of the fields parseAddress() actually sends, or the strict key '
            . 'appends a segment Loqate never sees and the lossy key drops nothing real.'
        );
        $this->assertEqualsCanonicalizing(
            $sentToLoqate,
            $strict,
            'The STRICT (rejection) key must project every field sent to Loqate: a rejection may only be '
            . 'replayed for the exact address Loqate judged. A field that reaches the request but not this '
            . 'list would make editing it replay a stale verdict - a wrong "invalid" the shopper cannot '
            . 'clear (the checkout dead-end) or a wrong "valid" that lets an unverified address through.'
        );
        $this->assertEqualsCanonicalizing(
            array_values(array_diff($sentToLoqate, [$county])),
            $lossy,
            'The LOSSY (success) key must be exactly the strict key minus the county: it may ignore the '
            . 'churning county (that is the LOQ-16969 fix) and nothing else.'
        );
        $this->assertSame(
            [$county],
            array_values(array_diff($strict, $lossy)),
            'The county must be the ONLY field the two key families differ by.'
        );
        $this->assertSame(
            [],
            array_values(array_diff($lossy, $strict)),
            'The lossy key must not project anything the strict key does not.'
        );
        $this->assertSame(
            count($strict),
            count(array_unique($strict)),
            'No field may appear twice in the key: a duplicated segment is dead weight in the session payload.'
        );
    }

    /**
     * The BEHAVIOURAL, load-bearing half of the same invariant, and the no-dead-end
     * property itself: for EVERY field parseAddress() sends to Loqate, a shopper whose
     * address was REJECTED must be re-verified once they edit that field.
     *
     * Driven from Validator::ADDRESS_MAPPING itself and through the public
     * verifyAddress(), so it pins the two things that actually matter and that the
     * structural test above cannot see: that the field reaches the strict cache KEY, and
     * that a change to it costs a second BILLABLE call rather than replaying the
     * rejection. A field added to ADDRESS_MAPPING with no fixture here fails outright
     * (that is the point: adding 'company' => 'Company' must not be able to leave the
     * suite green), and a field left out of the key fails on the call count.
     *
     * @param string $magentoField Magento address key, e.g. 'postcode'.
     * @param string $loqateField Loqate request field it is mapped onto, e.g. 'PostalCode'.
     */
    #[DataProvider('fieldSentToLoqateProvider')]
    public function testEditingAnyFieldSentToLoqateAfterARejectionIsVerifiedAgain(
        string $magentoField,
        string $loqateField
    ): void {
        [$base, $edited] = $this->mappedFieldFixture($magentoField, $loqateField);

        // Precondition, established on throwaway shoppers so it holds even if the cache
        // is broken: the edit really does change the request Loqate is asked.
        $this->assertEditChangesTheLoqateRequest($base, $edited, $loqateField);

        $this->stubApiResponses([self::rejectedResponse(), self::acceptedResponse()]);

        $rejected = $this->validator->verifyAddress($base);
        $this->assertTrue($rejected['error'], 'The first submission must be rejected off the wire.');
        $this->assertSame(1, $this->apiCallCount(), 'The first submission must reach the API.');

        $corrected = $this->validator->verifyAddress($edited);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            sprintf(
                'Editing "%s" (sent to Loqate as %s) after a rejection must trigger a fresh verification: '
                . 'the STRICT key must project every field Loqate judged, or the shopper is served the stale '
                . 'rejection and can never get out of checkout however often they correct that field.',
                $magentoField,
                $loqateField
            )
        );
        $this->assertSame(
            ['error' => false],
            $corrected,
            'The edited address must get the live verdict, not the cached rejection.'
        );
    }

    /**
     * The mirror image on the success path: for every field EXCEPT the county, editing it
     * after an acceptance must be verified again, or an unverified address is smuggled
     * past checkout on another address's "valid".
     *
     * The county is the one deliberate exception (the LOQ-16969 trade-off) and is asserted
     * as such here rather than skipped, so the exception stays exactly one field wide -
     * anything else falling out of the lossy key fails.
     *
     * @param string $magentoField Magento address key, e.g. 'postcode'.
     * @param string $loqateField Loqate request field it is mapped onto, e.g. 'PostalCode'.
     */
    #[DataProvider('fieldSentToLoqateProvider')]
    public function testEditingAnyFieldSentToLoqateExceptTheCountyIsVerifiedAgainAfterASuccess(
        string $magentoField,
        string $loqateField
    ): void {
        [$base, $edited] = $this->mappedFieldFixture($magentoField, $loqateField);
        $isCounty = $loqateField === (string)$this->privateConstant('COUNTY_FIELD');

        $this->assertEditChangesTheLoqateRequest($base, $edited, $loqateField);

        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress($base);
        $this->assertSame(1, $this->apiCallCount(), 'The first submission must reach the API.');

        $this->validator->verifyAddress($edited);

        if ($isCounty) {
            $this->assertSame(
                1,
                $this->apiCallCount(),
                sprintf(
                    'The county (%s) is the ONE field the success key deliberately ignores, because '
                    . 'capture.js and parseAddress() both rewrite it - re-billing that churn is the defect '
                    . 'LOQ-16969 fixes. Tightening this is tracked as LOQ-16979.',
                    $loqateField
                )
            );

            return;
        }

        $this->assertSame(
            2,
            $this->apiCallCount(),
            sprintf(
                'Editing "%s" (sent to Loqate as %s) must be verified again: the LOSSY key must project '
                . 'every field sent to Loqate except the county, or a genuinely different address is served '
                . 'this address\'s "valid" and reaches checkout unverified.',
                $magentoField,
                $loqateField
            )
        );
    }

    /**
     * One case per entry of Validator::ADDRESS_MAPPING, so the two tests above cover
     * exactly the fields the production code sends - today's and tomorrow's.
     */
    public static function fieldSentToLoqateProvider(): array
    {
        $cases = [];
        foreach (Validator::ADDRESS_MAPPING as $magentoField => $loqateField) {
            $cases[$magentoField . ' => ' . $loqateField] = [(string)$magentoField, (string)$loqateField];
        }

        return $cases;
    }

    /**
     * The upper bound on how fine-grained the verify key needs to be: two street values
     * that produce the byte-identical Loqate REQUEST must share one verification.
     *
     * Street lines beyond the second reach Loqate only inside the joined 'Address'
     * (parseAddress() sends Address, Address1, Address2 and nothing else about the
     * street), so ['Block C', 'Floor 9'] as two lines and 'Block C, Floor 9' as one
     * produce exactly the same payload. Billing that twice would be billing the same
     * request twice - the defect this ticket is about - so the joined street is the
     * right granularity for the key: no coarser (see
     * testEditingAStreetLineBeyondTheSecondIsVerifiedAgain()) and no finer than what
     * Loqate is actually asked.
     */
    public function testStreetVariantsProducingTheIdenticalLoqateRequestShareOneVerification(): void
    {
        $fourLines = array_merge(self::ADDRESS, ['street' => ['1 High St', 'Flat 2', 'Block C', 'Floor 9']]);
        $threeLines = array_merge(self::ADDRESS, ['street' => ['1 High St', 'Flat 2', 'Block C, Floor 9']]);

        // First, prove the two really do put the identical payload on the wire, by
        // sending each as the first (and only) request of its own shopper.
        $asFourLines = $this->createShopper();
        $asThreeLines = $this->createShopper();
        $this->stubShopperResponses($asFourLines, [self::acceptedResponse()]);
        $this->stubShopperResponses($asThreeLines, [self::acceptedResponse()]);
        $asFourLines['validator']->verifyAddress($fourLines);
        $asThreeLines['validator']->verifyAddress($threeLines);

        $this->assertSame(
            $asFourLines['requests'][0],
            $asThreeLines['requests'][0],
            'The two street values must reach Loqate as the identical request, or this test is not about '
            . 'what it claims to be about.'
        );

        // ...so one verdict for both is correct, not a bypass.
        $this->stubApiResponses([self::acceptedResponse()]);
        $this->validator->verifyAddress($fourLines);
        $this->validator->verifyAddress($threeLines);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'Two street values that produce the identical Loqate request must not be billed twice.'
        );
    }

    /**
     * A transport failure is not a verdict. Caching it would strand the shopper
     * on an error for the rest of the session, so the next attempt must call the
     * API again and be free to succeed.
     *
     * The whole failure-then-recovery sequence is asserted, in order:
     *  (a) while the API is failing, nothing is written to the cache;
     *  (b) the next attempt really does issue a second billable request;
     *  (c) once that retry succeeds, its verdict IS cached, so a third attempt is
     *      replayed for free - the failure must not have poisoned the cache into
     *      re-billing forever either.
     * Note (a) has to be checked between the failure and the retry: the retry
     * legitimately caches its success, so the store is not empty afterwards.
     */
    public function testTransportErrorIsNotCachedSoTheNextAttemptRetries(): void
    {
        $this->stubApiResponses([
            ['error' => true, 'message' => 'cURL error 28: Operation timed out'],
            self::acceptedResponse(),
        ]);

        $first = $this->validator->verifyAddress(self::ADDRESS);

        // (a) Nothing cached while the API is down.
        $this->assertSame(
            1,
            $this->apiCallCount(),
            'The first attempt must reach the API.'
        );
        $this->assertSame(
            [],
            $this->verdictStore(),
            'Nothing may be written to the verdict cache while the API is failing.'
        );
        $this->assertTrue($first['error']);
        $this->assertSame(
            'An unexpected error occurred while trying to validate your address.',
            (string)$first['message']
        );

        // (b) The retry is not served from the cache: it hits the API again.
        $second = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'A failed request must never be cached - the next attempt has to retry the API.'
        );
        $this->assertSame(['error' => false], $second, 'The retry must return the live success verdict.');

        // (c) The recovered verdict is cached like any other success, so the rest
        // of the checkout replays it instead of re-billing.
        $store = $this->verdictStore();
        $this->assertCount(
            1,
            $store,
            'Once the retry succeeds, its verdict must be cached like any other success.'
        );
        $this->assertSame(
            ['error' => false],
            json_decode((string)reset($store), true),
            'The cached entry must be the successful verdict from the retry.'
        );

        $third = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'After a successful retry the address is verified: further attempts must be served from the cache.'
        );
        $this->assertSame(['error' => false], $third, 'The replayed verdict must match the recovered one.');
    }

    /**
     * An AVC rejection IS a definitive API verdict. It must be cached, or every
     * retry through the 3-5 call checkout stack re-bills the same bad address.
     */
    public function testAvcRejectedVerdictIsCachedAndReturnedWithoutASecondApiCall(): void
    {
        $this->stubApiResponses([self::rejectedResponse()]);

        $first = $this->validator->verifyAddress(self::ADDRESS);
        $second = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'A definitive "invalid address" verdict must be cached, not re-billed on every retry.'
        );
        $this->assertTrue($first['error']);
        $this->assertTrue($second['error'], 'The cached rejection must still reject.');
        $this->assertSame('The provided address is invalid.', (string)$first['message']);
        $this->assertSame(
            'The provided address is invalid.',
            (string)$second['message'],
            'The cached rejection must carry the same shopper-facing message.'
        );
    }

    /**
     * A response we cannot read an AVC out of is NOT a verdict, it is a failure, and
     * must be treated like a transport error: the shopper is rejected (fail closed),
     * but nothing is cached, so the next attempt retries the API.
     *
     * This is the realistic misconfigured-key / wrong-endpoint path:
     * Verify::verifyAddress() returns array_column($response, 'Matches'), so ANY
     * response shape the connector does not recognise - an error envelope, a changed
     * schema, an empty body - collapses to []. Caching that as "invalid address"
     * would brand every address the shopper tries as invalid for the whole session
     * off a single fault, with no API call left to recover from it.
     *
     * @param array $response Connector response with no readable AVC.
     */
    #[DataProvider('unreadableAvcResponseProvider')]
    public function testUnreadableAvcIsRejectedButNeverCachedSoTheNextAttemptRetries(array $response): void
    {
        $this->stubApiResponses([$response]);

        $first = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(1, $this->apiCallCount(), 'The first attempt must reach the API.');
        $this->assertTrue($first['error'], 'A response with no readable AVC must fail closed.');
        $this->assertSame(
            'The provided address is invalid.',
            (string)$first['message'],
            'The shopper-facing message must be the standard rejection.'
        );
        $this->assertSame(
            [],
            $this->verdictStore(),
            'A response we cannot read is not a verdict: nothing may be written to the cache.'
        );

        $second = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'An unreadable response must never be cached - the next attempt has to retry the API.'
        );
        $this->assertTrue($second['error']);
        $this->assertSame([], $this->verdictStore(), 'Still nothing cached after the retry also failed.');
    }

    public static function unreadableAvcResponseProvider(): array
    {
        return [
            // The realistic one: an unrecognised response shape (bad key, changed
            // schema) collapses to [] inside Verify::verifyAddress().
            'empty response' => [[]],
            'address present but no matches' => [[[]]],
            'AVC key absent' => [[[['AQI' => 'A']]]],
            'AVC empty string' => [[[['AVC' => '', 'AQI' => 'A']]]],
            'AVC not a string (int)' => [[[['AVC' => 0, 'AQI' => 'A']]]],
            'AVC not a string (array)' => [[[['AVC' => ['V55-I22-P9-99'], 'AQI' => 'A']]]],
            'AVC null' => [[[['AVC' => null, 'AQI' => 'A']]]],
        ];
    }

    /**
     * The recovery half of the previous test: once the API answers with a readable
     * AVC again, that verdict IS cached, so a single unreadable response must not have
     * poisoned the session into re-billing every subsequent call path either.
     */
    public function testAVerdictIsCachedAgainOnceAReadableAvcIsReceived(): void
    {
        $this->stubApiResponses([[], self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $recovered = $this->validator->verifyAddress(self::ADDRESS);
        $replayed = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The retry must reach the API and its readable verdict must then be replayed from the cache.'
        );
        $this->assertSame(['error' => false], $recovered, 'The retry must return the live verdict.');
        $this->assertSame(['error' => false], $replayed, 'The recovered verdict must be cached like any other.');
        $this->assertCount(1, $this->verdictStore(), 'Exactly the recovered verdict must be cached.');
    }

    /**
     * Re-keying, case and stray whitespace are not edits (Magento and capture.js
     * both reformat freely), so they must not cost a second billable request.
     */
    public function testCaseAndWhitespaceOnlyDifferencesAreDeduplicated(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress([
            'street' => ['  1 High   St ', 'FLAT 2'],
            'city' => 'LONDON',
            'region' => 'Greater London',
            'postcode' => 'sw1a 1aa',
            'country_id' => 'gb',
        ]);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'Case- and whitespace-only differences describe the same address and must not re-bill.'
        );
    }

    /**
     * The load-bearing cross-path assertion: the SAME address must project to the
     * SAME signature no matter which checkout call path presents it, because the whole
     * fix is that the 3-5 paths of one checkout share one verdict.
     *
     * The shapes really do differ, and no other test in this file exercises the last
     * two:
     *  - the plain POST shape: street is a real ARRAY and the county is a 'region'
     *    NAME (self::ADDRESS, used by most tests here);
     *  - the quote paths (CheckoutShippingInformation.php:32,
     *    CheckoutBillingAddress.php:34, QuoteSubmitBefore.php:85/:109) pass
     *    Quote\Address::getData(), and AbstractAddress::setData() has already run the
     *    multiline street attribute through trim(implode("\n", $value)), so street is
     *    a NEWLINE-SEPARATED STRING and the county is a 'region_id' that
     *    parseAddress() re-resolves through RegionFactory;
     *  - the shape the POST plugins actually ship: CustomerAccountAddress.php:30-35
     *    and Admin\ValidateAddress.php:35-40 do NOT pass getPostValue() verbatim, they
     *    INJECT 'street_1'/'street_2' copied from street[0]/street[1] first, and
     *    ADDRESS_MAPPING maps those onto Address1/Address2 - the same two fields
     *    extractStreetLines() then fills from the street array. That double write is
     *    why this shape has to be exercised: if the two ever disagreed (ordering,
     *    trimming), a customer-account save and a checkout save of one address would
     *    be billed separately. The real payload also carries the rest of the address
     *    form (firstname, telephone, form_key...), none of which may enter the key.
     *
     * If those projected differently, a checkout would be billed twice however well the
     * cache worked - so this is asserted in both orders, since either shape can be the
     * one that reaches Loqate first.
     */
    public function testBothCheckoutCallPathShapesOfOneAddressAreBilledOnce(): void
    {
        $this->regionNames = [100 => 'Greater London'];

        // Quote\Address::getData(): newline string street (note the trailing
        // newline Magento leaves behind) and a region_id instead of a region name.
        $quoteShape = [
            'street' => "1 High St\nFlat 2\n",
            'city' => 'London',
            'region_id' => 100,
            'postcode' => 'SW1A 1AA',
            'country_id' => 'GB',
        ];

        // What CustomerAccountAddress::aroundExecute() / Admin\ValidateAddress
        // ::aroundExecute() actually hand to verifyAddress(): the full address-form
        // POST plus the injected street_1/street_2.
        $pluginShape = [
            'form_key' => 'FaKeFoRmKeY01',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'company' => '',
            'telephone' => '02079460000',
            'street' => ['1 High St', 'Flat 2'],
            'street_1' => '1 High St',
            'street_2' => 'Flat 2',
            'city' => 'London',
            'region' => 'Greater London',
            'region_id' => '',
            'postcode' => 'SW1A 1AA',
            'country_id' => 'GB',
            'default_billing' => '1',
        ];

        // POST shape first (customer-account save), quote shape second (checkout),
        // then the real plugin payload.
        $this->stubApiResponses([self::acceptedResponse()]);
        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress($quoteShape);
        $this->validator->verifyAddress($pluginShape);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'The array-street/region-name POST shape, the newline-street/region_id quote shape and the '
            . 'street_1/street_2 payload the POST plugins really build all describe one address and must '
            . 'share one billable verification.'
        );

        // ...and the other way round: the quote path usually runs first in a real
        // checkout, so the verdict it writes must satisfy the POST shapes too.
        $reversed = $this->createShopper();
        $this->stubShopperResponses($reversed, [self::acceptedResponse()]);
        $reversed['validator']->verifyAddress($quoteShape);
        $reversed['validator']->verifyAddress(self::ADDRESS);
        $reversed['validator']->verifyAddress($pluginShape);

        $this->assertSame(
            1,
            $this->shopperCallCount($reversed),
            'The projection must be symmetric: whichever shape is verified first must satisfy the others.'
        );

        // ...and the plugin payload first of all, since a customer-account save can
        // precede the checkout it is reused in.
        $pluginFirst = $this->createShopper();
        $this->stubShopperResponses($pluginFirst, [self::acceptedResponse()]);
        $pluginFirst['validator']->verifyAddress($pluginShape);
        $pluginFirst['validator']->verifyAddress(self::ADDRESS);
        $pluginFirst['validator']->verifyAddress($quoteShape);

        $this->assertSame(
            1,
            $this->shopperCallCount($pluginFirst),
            'The street_1/street_2 payload the plugins inject must not project differently from the same '
            . 'address arriving without them.'
        );

        // The injected street_1/street_2 must not reach Loqate as anything other than
        // the normal street lines either: ADDRESS_MAPPING writes them to
        // Address1/Address2 and extractStreetLines() must end up with the same values.
        $sent = $pluginFirst['requests'][0]['Addresses'][0] ?? [];
        $this->assertSame('1 High St, Flat 2', $sent['Address'] ?? null);
        $this->assertSame('1 High St', $sent['Address1'] ?? null);
        $this->assertSame('Flat 2', $sent['Address2'] ?? null);
        $this->assertArrayNotHasKey(
            'street_1',
            $sent,
            'Only mapped Loqate fields may be sent; the raw POST keys must not leak into the request.'
        );
    }

    /**
     * The same cross-path equivalence for the empty street lines Magento leaves in
     * both shapes: an unfilled "Street Address 2" input reaches the POST paths as an
     * empty array element and the quote paths as a blank line inside the newline
     * string. Neither is a different address.
     */
    public function testEmptyStreetLinesProjectIdenticallyInBothStreetShapes(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => ['1 High St', '', 'Flat 2']]));
        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => ['1 High St', 'Flat 2', '']]));
        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => "1 High St\n\nFlat 2"]));
        $this->validator->verifyAddress(array_merge(self::ADDRESS, ['street' => "  1 High St \r\n Flat 2 \r\n "]));

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'Interior and trailing empty street lines must not make the same address billable again, '
            . 'in either the array or the newline-string shape.'
        );
    }

    /**
     * The pre-existing captured-address bypass must be untouched by the verify cache,
     * including for the case that motivated folding the joined street into the verify
     * keys: a store configured with more than two street lines.
     *
     * The captured-address store holds Line1/Line2 only (ADDRESS_CAPTURE_MAPPING has no
     * field for a third line and no 'Address' key at all), and it is compared using the
     * SAME buildAddressSignature() the verify keys are derived from. So this is the
     * regression that a naive "just add Address to the signature" would cause: every
     * lookup-captured address would stop matching and be re-verified - and possibly
     * rejected - which is exactly the defect the previous ticket fixed. The connector is
     * stubbed to REJECT here, so a pass can only have come from the captured bypass.
     */
    public function testACapturedLookupAddressWithThreeStreetLinesIsNotVerifiedAtAll(): void
    {
        $this->stubApiResponses([self::rejectedResponse()]);
        $this->sessionStore['captured_addresses'] = [json_encode([
            'Address1' => '1 High St',
            'Address2' => 'Flat 2',
            'Country' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'Address3' => 'London',
            'Address4' => 'Greater London',
        ])];

        $result = $this->validator->verifyAddress(
            array_merge(self::ADDRESS, ['street' => ['1 High St', 'Flat 2', 'Block C']])
        );

        $this->assertSame(
            ['error' => false],
            $result,
            'An address picked from the Loqate lookup must still bypass verification when Magento supplies '
            . 'a third street line the lookup never stored.'
        );
        $this->assertSame(
            0,
            $this->apiCallCount(),
            'A captured address must not be sent to the billable API at all.'
        );
        $this->assertSame(
            [],
            $this->verdictStore(),
            'The captured bypass returns before the verify cache, so it must write nothing to it.'
        );
    }

    /**
     * The region is excluded from the dedup KEY, not from the REQUEST: Loqate needs
     * the county to verify the address, and an "optimisation" that dropped it from the
     * payload along with the key would silently degrade every verification.
     */
    public function testRegionIsStillSentToLoqateAlthoughItIsExcludedFromTheDedupKey(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);
        $this->regionNames = [100 => 'Dublin'];

        $this->validator->verifyAddress(self::ADDRESS);

        $sent = $this->lastApiRequest()['Addresses'][0] ?? [];
        $this->assertSame(
            'Greater London',
            $sent['Address4'] ?? null,
            'The region/county must still be sent to Loqate as Address4, even though the dedup key ignores it.'
        );
        $this->assertSame('1 High St, Flat 2', $sent['Address'] ?? null, 'The full street must be sent.');
        $this->assertSame('1 High St', $sent['Address1'] ?? null);
        $this->assertSame('Flat 2', $sent['Address2'] ?? null);
        $this->assertSame('London', $sent['Address3'] ?? null);
        $this->assertSame('SW1A 1AA', $sent['PostalCode'] ?? null);
        $this->assertSame('GB', $sent['Country'] ?? null);

        // A county that arrived as a region_id must be resolved and sent too.
        $this->validator->verifyAddress([
            'street' => ['4 O\'Connell Street'],
            'city' => 'Dublin',
            'region_id' => 100,
            'postcode' => 'D01 XXXX',
            'country_id' => 'IE',
        ]);

        $this->assertSame(2, $this->apiCallCount(), 'A different address must be verified in its own right.');
        $this->assertSame(
            'Dublin',
            $this->lastApiRequest()['Addresses'][0]['Address4'] ?? null,
            'A region resolved from region_id must be sent as Address4.'
        );
    }

    /**
     * An address with nothing identifiable in it has an empty signature. It must
     * neither be written to the cache (poisoning it) nor be served another
     * address's verdict.
     */
    public function testUnidentifiableAddressIsNeverCachedAndNeverServesACachedVerdict(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress([]);
        $this->validator->verifyAddress([]);
        $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            3,
            $this->apiCallCount(),
            'An unidentifiable address must always be sent to the API and must never satisfy another address.'
        );
        $this->assertArrayNotHasKey(
            '',
            $this->verdictStore(),
            'An empty signature must never be used as a cache key.'
        );
    }

    /**
     * The cache must be bounded. The existing captured_addresses store grows
     * without limit for the whole session - a defect we must not replicate in
     * session storage.
     */
    public function testVerdictCacheIsBoundedToVerifyCacheLimit(): void
    {
        $limit = $this->verifyCacheLimit();
        $this->stubApiResponses([self::acceptedResponse()]);

        for ($i = 1; $i <= $limit + 5; $i++) {
            $this->validator->verifyAddress($this->distinctAddress($i));
        }

        $store = $this->verdictStore();
        $this->assertNotSame([], $store, 'Verified addresses must actually be cached.');
        $this->assertCount(
            $limit,
            $store,
            'Once more than VERIFY_CACHE_LIMIT addresses have been verified the cache must hold exactly '
            . 'VERIFY_CACHE_LIMIT entries: no more (the session payload must stay bounded) and no fewer '
            . '(a cache that under-fills re-bills addresses it had room to keep).'
        );
    }

    /**
     * The bound must be a bound, not a cliff: every one of the VERIFY_CACHE_LIMIT
     * verdicts the cache claims to hold has to be genuinely replayable.
     *
     * Asserted by filling the cache to exactly the limit and then replaying all of it
     * with no further billable call, which is what distinguishes a real LIFO/FIFO
     * cache of LIMIT entries from one that keeps only the newest verdict (or one whose
     * limit is far smaller than advertised) - both of which satisfy a bare
     * "count <= limit" assertion.
     */
    public function testEveryVerdictUpToTheCacheLimitIsRetainedAndReplayable(): void
    {
        $limit = $this->verifyCacheLimit();
        $this->stubApiResponses([self::acceptedResponse()]);

        for ($i = 1; $i <= $limit; $i++) {
            $this->validator->verifyAddress($this->distinctAddress($i));
        }

        $this->assertSame(
            $limit,
            $this->apiCallCount(),
            'Each of the ' . $limit . ' distinct addresses must be verified exactly once.'
        );
        $this->assertCount(
            $limit,
            $this->verdictStore(),
            'A cache bounded to ' . $limit . ' entries must actually be able to hold ' . $limit . ' verdicts.'
        );

        // Replay every single one of them, oldest first.
        for ($i = 1; $i <= $limit; $i++) {
            $result = $this->validator->verifyAddress($this->distinctAddress($i));
            $this->assertSame(['error' => false], $result, 'Cached verdict ' . $i . ' must be replayed intact.');
        }

        $this->assertSame(
            $limit,
            $this->apiCallCount(),
            'Every verdict up to the limit must still be cached: replaying all ' . $limit
            . ' addresses must not cost a single further billable request.'
        );
        $this->assertCount($limit, $this->verdictStore(), 'Replaying cached verdicts must not grow the cache.');
    }

    /**
     * The bound also has to be big enough to be useful, which no count assertion can
     * express - so it is pinned behaviourally, on the commonest real checkout: a
     * DIFFERENT billing and shipping address, each replayed across the call paths
     * (shipping-information POST, billing save, the billing save replayed by
     * savePaymentInformation at place-order, then both QuoteSubmitBefore calls).
     *
     * Two addresses, five verifications, exactly two billable requests. A cache too
     * small to hold both addresses at once would thrash - each address evicting the
     * other and being re-billed - and largely undo the fix for every shopper who does
     * not tick "same as shipping".
     */
    public function testAWholeCheckoutWithDifferentShippingAndBillingAddressesCostsTwoCalls(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $shipping = self::ADDRESS;
        $billing = [
            'street' => ['77 Office Park', 'Unit 4'],
            'city' => 'Reading',
            'region' => 'Berkshire',
            'postcode' => 'RG1 1AA',
            'country_id' => 'GB',
        ];

        $this->validator->verifyAddress($shipping); // shipping-information POST
        $this->validator->verifyAddress($billing);  // billing save
        $this->validator->verifyAddress($billing);  // place-order billing replay
        $this->validator->verifyAddress($shipping); // QuoteSubmitBefore, shipping
        $this->validator->verifyAddress($billing);  // QuoteSubmitBefore, billing

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'A checkout with separate shipping and billing addresses must cost exactly two billable '
            . 'requests: the cache has to hold both addresses at once, not evict one for the other.'
        );
        $this->assertCount(2, $this->verdictStore(), 'Both addresses must be cached simultaneously.');
    }

    /**
     * Eviction order matters: dropping the newest entry would defeat the fix,
     * because the address currently being checked out is the one replayed 3-5
     * times. The oldest entry must go first.
     */
    public function testOldestCachedVerdictIsEvictedFirst(): void
    {
        $limit = $this->verifyCacheLimit();
        $this->stubApiResponses([self::acceptedResponse()]);

        for ($i = 1; $i <= $limit + 1; $i++) {
            $this->validator->verifyAddress($this->distinctAddress($i));
        }
        $callsAfterFill = $this->apiCallCount();

        // The newest address must still be cached...
        $this->validator->verifyAddress($this->distinctAddress($limit + 1));
        $this->assertSame(
            $callsAfterFill,
            $this->apiCallCount(),
            'The most recently verified address must survive eviction.'
        );

        // ...while the oldest one has been evicted and needs verifying again.
        $this->validator->verifyAddress($this->distinctAddress(1));
        $this->assertSame(
            $callsAfterFill + 1,
            $this->apiCallCount(),
            'The oldest entry must be the one evicted once the cache is full.'
        );
    }

    /**
     * The one observable effect of the "unset($store[$key]) before re-inserting" line in
     * storeVerifyResult(): refreshing an entry that is ALREADY in a FULL cache must not
     * evict an unrelated verdict, which would then have to be re-billed.
     *
     * A write only ever follows a cache MISS, so this is reachable in exactly one way: the
     * key is present but unreadable (a truncated session payload, another module writing
     * to the key, a serializer that rejects it), so the read missed and the verdict is
     * re-fetched under a key that is already there.
     *
     * The corrupted entry has to be the SECOND oldest, not the oldest. array_shift()
     * evicts from the front, so refreshing the FRONT entry evicts that same entry - which
     * is then immediately re-inserted, harming nothing and making the unset
     * indistinguishable. It is only when the refreshed key sits behind the front that the
     * unset saves a different address's verdict, so that is the case pinned here: without
     * the unset, refreshing #2 silently drops #1.
     */
    public function testRefreshingAnUnreadableEntryWhileFullDoesNotEvictAnUnrelatedVerdict(): void
    {
        $limit = $this->verifyCacheLimit();
        $this->stubApiResponses([self::acceptedResponse()]);

        // Fill the cache to exactly the limit: entries are in insertion order, #1 oldest.
        for ($i = 1; $i <= $limit; $i++) {
            $this->validator->verifyAddress($this->distinctAddress($i));
        }
        $this->assertSame($limit, $this->apiCallCount(), 'Each distinct address must be verified once.');
        $this->assertCount($limit, $this->verdictStore(), 'The cache must be exactly full before the refresh.');

        // Corrupt the SECOND oldest entry so its read misses while its key stays present.
        $store = $this->verdictStore();
        $keys = array_keys($store);
        $secondOldestKey = (string)$keys[1];
        $store[$secondOldestKey] = '{not json';
        $this->sessionStore[self::VERIFY_CACHE_SESSION_KEY] = $store;

        // Re-verify address #2: unreadable, so it is re-fetched and rewritten in place.
        $refreshed = $this->validator->verifyAddress($this->distinctAddress(2));

        $this->assertSame(
            $limit + 1,
            $this->apiCallCount(),
            'An unreadable entry must be re-verified against the API.'
        );
        $this->assertSame(['error' => false], $refreshed, 'The live verdict must be returned.');
        $this->assertCount(
            $limit,
            $this->verdictStore(),
            'Refreshing an entry that is already present must leave the cache exactly full: it replaces one '
            . 'entry, so it must not also evict another.'
        );
        $this->assertArrayHasKey(
            $secondOldestKey,
            $this->verdictStore(),
            'The refreshed verdict must be readable again under its own key.'
        );

        // Address #1 is entirely unrelated to the refresh and was the OLDEST entry, so it
        // is precisely what a stray eviction would have taken.
        $unrelated = $this->validator->verifyAddress($this->distinctAddress(1));

        $this->assertSame(
            $limit + 1,
            $this->apiCallCount(),
            'Refreshing an unreadable entry in a full cache must not evict an UNRELATED verdict: that other '
            . 'address would then be re-billed for no reason. This is what dropping the entry before '
            . 're-inserting it prevents - without it, the eviction loop takes the front entry even though '
            . 'the write replaces an entry that is already counted.'
        );
        $this->assertSame(['error' => false], $unrelated, 'The unrelated verdict must be replayed from cache.');

        // The refreshed entry is also treated as the NEWEST, so it survives the next
        // eviction and the entry it replaced does not keep its old age.
        $this->validator->verifyAddress($this->distinctAddress($limit + 1));
        $this->assertSame($limit + 2, $this->apiCallCount(), 'A brand new address must be verified.');

        $stillCached = $this->validator->verifyAddress($this->distinctAddress(2));
        $this->assertSame(
            $limit + 2,
            $this->apiCallCount(),
            'The refreshed verdict must be the newest, not inherit the age of the entry it replaced, so the '
            . 'next eviction does not take it.'
        );
        $this->assertSame(['error' => false], $stillCached);
    }

    /**
     * The signature is the dedup key, so its projection is load-bearing: the
     * region/county (Address4) must be excluded because capture.js rewrites it,
     * while the city (Address3) must be included because two different towns can
     * share a street name and postcode format.
     */
    public function testBuildAddressSignatureExcludesRegionAndIncludesCity(): void
    {
        $base = [
            'Address1' => '1 High St',
            'Address2' => 'Flat 2',
            'Address3' => 'London',
            'Address4' => 'Greater London',
            'PostalCode' => 'SW1A 1AA',
            'Country' => 'GB',
        ];

        $signature = $this->buildAddressSignature($base);

        $this->assertSame(
            $signature,
            $this->buildAddressSignature(array_merge($base, ['Address4' => 'Co. Meath'])),
            'Region/county (Address4) must not affect the signature.'
        );
        $this->assertNotSame(
            $signature,
            $this->buildAddressSignature(array_merge($base, ['Address3' => 'Manchester'])),
            'City (Address3) must affect the signature.'
        );
    }

    /**
     * An address with no usable parts must yield an empty signature, which is
     * the sentinel that keeps it out of the cache entirely.
     */
    public function testBuildAddressSignatureReturnsEmptyStringForAnEmptyAddress(): void
    {
        $this->assertSame(
            '',
            $this->buildAddressSignature(['Address' => '', 'Address4' => 'Greater London']),
            'An address with no street, city, postcode or country is not identifiable.'
        );
    }

    /**
     * Normalisation contract: trim, collapse whitespace, upper-case, join with
     * "|". Loose enough to survive reformatting, strict enough that different
     * addresses keep different keys.
     */
    public function testBuildAddressSignatureNormalisesCaseAndWhitespace(): void
    {
        $signature = $this->buildAddressSignature([
            'Address1' => '  1 High   St ',
            'Address2' => 'flat 2',
            'Address3' => 'london',
            'PostalCode' => 'sw1a 1aa',
            'Country' => 'gb',
        ]);

        $this->assertSame('1 HIGH ST|FLAT 2|LONDON|SW1A 1AA|GB', $signature);
    }

    /**
     * Array values (Magento's street) and missing keys must not break the
     * projection - it has to stay a plain, comparable string.
     */
    public function testBuildAddressSignatureIgnoresArrayAndMissingValues(): void
    {
        $signature = $this->buildAddressSignature([
            'Address1' => ['1 High St', 'Flat 2'],
            'Address3' => 'London',
            'Country' => 'GB',
        ]);

        $this->assertSame('||LONDON||GB', $signature);
    }

    /**
     * "|" joins the signature parts, so a "|" inside a field value must not be able
     * to imitate a part boundary: without neutralising it, an address whose street
     * line 1 is "1 High|St" and one whose street line 2 is "St|Flat 2" would render
     * the identical signature "1 HIGH|ST|FLAT 2|..." and share a verdict - one
     * address's rejection answering for a different address, or vice versa.
     *
     * Street values are shopper-controlled free text, so this is reachable from the
     * front end, not just in theory.
     */
    public function testAPipeInsideAFieldCannotForgeASignatureBoundary(): void
    {
        $left = [
            'Address1' => '1 High|St',
            'Address2' => 'Flat 2',
            'Address3' => 'London',
            'PostalCode' => 'SW1A 1AA',
            'Country' => 'GB',
        ];
        $right = array_merge($left, ['Address1' => '1 High', 'Address2' => 'St|Flat 2']);

        $this->assertNotSame(
            $this->buildAddressSignature($left),
            $this->buildAddressSignature($right),
            'Two different addresses must not collide because one of their field values contains "|".'
        );

        // ...and end to end, so the guarantee is about billing and verdicts and not
        // just about strings.
        $this->stubApiResponses([self::acceptedResponse(), self::rejectedResponse()]);

        $accepted = $this->validator->verifyAddress(
            array_merge(self::ADDRESS, ['street' => ['1 High|St', 'Flat 2']])
        );
        $rejected = $this->validator->verifyAddress(
            array_merge(self::ADDRESS, ['street' => ['1 High', 'St|Flat 2']])
        );

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'An address containing "|" must be verified in its own right, not answered by another address.'
        );
        $this->assertSame(['error' => false], $accepted);
        $this->assertTrue($rejected['error'], 'The second address must get its own verdict.');
    }

    /**
     * Post data is attacker-controlled and not type-checked anywhere upstream:
     * "street[0][]=x" arrives as a nested array and a crafted body can put an object
     * into a scalar field. Neither may throw out of verifyAddress() - a TypeError in a
     * checkout plugin is a 500 on the place-order call - and neither may reach the
     * signature as anything other than a plain string.
     */
    public function testNestedArrayAndObjectFieldsDegradeInsteadOfThrowing(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        // street[0][]=x : the first line is an array, so it is dropped and "Flat 2"
        // becomes line 1.
        $nestedStreet = array_merge(self::ADDRESS, ['street' => [['x'], 'Flat 2']]);
        $objectCity = array_merge(self::ADDRESS, ['city' => new \stdClass()]);

        $first = $this->validator->verifyAddress($nestedStreet);
        $second = $this->validator->verifyAddress($objectCity);

        $this->assertSame(['error' => false], $first, 'A nested-array street line must not break verification.');
        $this->assertSame(['error' => false], $second, 'An object in a scalar field must not break verification.');
        $this->assertSame(2, $this->apiCallCount(), 'Both are distinct addresses, so both are verified.');
        $this->assertSame(
            'Flat 2',
            $this->apiRequests[0]['Addresses'][0]['Address1'] ?? null,
            'The nested array must be dropped from the street lines, leaving plain strings on the wire.'
        );
        $this->assertCount(2, $this->verdictStore(), 'Both verdicts are still cacheable.');

        // At signature level: a non-scalar normalises to '' instead of throwing.
        $this->assertSame(
            '||LONDON||GB',
            $this->buildAddressSignature([
                'Address1' => new \stdClass(),
                'Address2' => ['x'],
                'Address3' => 'London',
                'Country' => 'GB',
            ]),
            'Objects and arrays must normalise to the empty part, never throw.'
        );
    }

    /**
     * A verdict is a function of the address AND of the AVC thresholds it was judged
     * against, and every one of those threshold fields is showInStore="1" and read at
     * SCOPE_STORE (Data::getConfigValue()) - while ONE customer session can span
     * several store views (?___store=, a language/currency switcher). Two store views
     * must therefore never answer for each other: the shopper is billed once per store
     * view, which is correct, because the two verdicts can legitimately differ.
     *
     * Store view, not website, is the scope that matters here: scoping per website
     * would still serve a verdict computed under one view's thresholds to a sibling
     * view that configures them differently.
     */
    public function testVerdictsAreNotReplayedAcrossStoreViewsInTheSameSession(): void
    {
        $sharedSession = new ArrayObject();
        $storeOne = $this->createShopper($sharedSession, 1);
        $storeTwo = $this->createShopper($sharedSession, 2);
        $this->stubShopperResponses($storeOne, [self::acceptedResponse()]);
        $this->stubShopperResponses($storeTwo, [self::rejectedResponse()]);

        $resultOne = $storeOne['validator']->verifyAddress(self::ADDRESS);
        $resultTwo = $storeTwo['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(1, $this->shopperCallCount($storeOne), 'Store view 1 must verify the address itself.');
        $this->assertSame(
            1,
            $this->shopperCallCount($storeTwo),
            'Store view 2 must not be served store view 1\'s verdict: the AVC thresholds behind it are '
            . 'resolved at SCOPE_STORE.'
        );
        $this->assertSame(['error' => false], $resultOne);
        $this->assertTrue($resultTwo['error'], 'Store view 2 must get its own, different verdict.');
        $this->assertCount(
            2,
            $this->shopperStore($storeOne),
            'Both verdicts must coexist in the one session, namespaced per store view.'
        );
        $this->assertCount(
            2,
            array_unique(array_keys($this->shopperStore($storeOne))),
            'The two store views\' entries must not share a cache key.'
        );
    }

    /**
     * The namespace is a NAMESPACE: two store views that accept the same address must
     * store the identical signature under two different prefixes, rather than the store
     * view leaking into the signature projection itself (which would, for instance,
     * make the county-agnostic success key store-view-dependent in ways no other test
     * would notice).
     */
    public function testTwoStoreViewsCacheTheSameSignatureUnderDifferentNamespaces(): void
    {
        $sharedSession = new ArrayObject();
        $storeOne = $this->createShopper($sharedSession, 1);
        $storeTwo = $this->createShopper($sharedSession, 2);
        $this->stubShopperResponses($storeOne, [self::acceptedResponse()]);
        $this->stubShopperResponses($storeTwo, [self::acceptedResponse()]);

        $storeOne['validator']->verifyAddress(self::ADDRESS);
        $storeTwo['validator']->verifyAddress(self::ADDRESS);

        $keys = array_keys($this->shopperStore($storeOne));
        $this->assertCount(2, $keys, 'Each store view must cache its own verdict for the same address.');

        // Only the two namespace segments (store view, AVC threshold fingerprint) may
        // differ; what follows them is the address signature itself.
        $signatures = array_map(
            static fn (string $key): string => self::signatureSegment($key),
            $keys
        );
        $this->assertCount(
            1,
            array_unique($signatures),
            'One address must project to one signature: only the store view namespace may differ.'
        );
    }

    /**
     * The other half of the scoping rule: within ONE store view the session cache must
     * still do its job across requests, otherwise the namespacing would have quietly
     * disabled the fix.
     *
     * Both requests are pinned to the SAME non-default store view (7), so this asserts
     * a genuine cache hit inside one namespace - not two shoppers accidentally
     * collapsing into the default store view because getCurrentStore() went unstubbed.
     */
    public function testTheSameStoreViewAndSessionStillReplaysTheCachedVerdict(): void
    {
        $sharedSession = new ArrayObject();
        $firstRequest = $this->createShopper($sharedSession, 7);
        $this->stubShopperResponses($firstRequest, [self::acceptedResponse()]);
        $firstRequest['validator']->verifyAddress(self::ADDRESS);

        $this->assertCount(
            1,
            $this->shopperStore($firstRequest),
            'The first request must have cached exactly one verdict for store view 7.'
        );

        // Same store view, same session, later request - stubbed to reject, so a
        // verdict of "valid" can only have come from the cache.
        $laterRequest = $this->createShopper($sharedSession, 7);
        $this->stubShopperResponses($laterRequest, [self::rejectedResponse()]);

        $result = $laterRequest['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            0,
            $this->shopperCallCount($laterRequest),
            'Requests on the same store view and session must replay the cached verdict.'
        );
        $this->assertSame(['error' => false], $result);
        $this->assertCount(
            1,
            $this->shopperStore($laterRequest),
            'The replay must reuse the existing entry, not add a second one under the same store view.'
        );
    }

    /**
     * The staleness the AVC-threshold fingerprint in the cache key exists to fix: a
     * verdict is a function of the address AND of the thresholds it was judged against, so
     * once a merchant changes an APPLIED threshold, verdicts computed under the old one
     * must not be replayed.
     *
     * The reported symptom is an admin re-testing an address
     * (Plugin\Admin\ValidateAddress.php:42) after tightening or loosening the thresholds
     * and being handed the verdict from before the change, with no way to clear it short
     * of ending the session.
     */
    public function testChangingAnAppliedAvcThresholdInvalidatesTheCachedVerdict(): void
    {
        // Advanced settings ON, so the configured thresholds are the ones actually applied.
        $config = new ArrayObject(self::thresholdConfig(true, ['avc_matchscore' => '90']));
        $shopper = $this->createShopper(null, 0, null, $config);
        $this->stubShopperResponses($shopper, [self::acceptedResponse()]);

        $first = $shopper['validator']->verifyAddress(self::ADDRESS);
        $this->assertSame(1, $this->shopperCallCount($shopper), 'The first submission must reach the API.');
        $this->assertSame(['error' => false], $first);
        $keyBefore = (string)array_key_first($this->shopperStore($shopper));

        // The merchant tightens the match score. The address is unchanged.
        $config['loqate_settings/verify_threshold_settings/avc_matchscore'] = '80';

        $second = $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->shopperCallCount($shopper),
            'Changing an AVC threshold that is actually applied must invalidate the cached verdict: it was '
            . 'judged against the old threshold, so replaying it reports a verdict the current '
            . 'configuration never produced.'
        );
        $this->assertSame(['error' => false], $second, 'The re-verified address must get the live verdict.');

        $store = $this->shopperStore($shopper);
        $this->assertCount(2, $store, 'The two verdicts must be cached under their own threshold namespaces.');
        $keys = array_keys($store);
        $keyAfter = (string)$keys[1];
        $this->assertNotSame(
            self::fingerprintSegment($keyBefore),
            self::fingerprintSegment($keyAfter),
            'The threshold fingerprint segment of the key must change with the applied thresholds.'
        );
        $this->assertSame(
            self::signatureSegment($keyBefore),
            self::signatureSegment($keyAfter),
            'The thresholds must NAMESPACE the key, not leak into the address signature: one address must '
            . 'still project to one signature.'
        );
    }

    /**
     * The other half of that contract, and the reason the fingerprint is taken over the
     * RESOLVED comparer string rather than the raw configuration: with
     * "show_advanced_avc_settings" OFF the eight threshold fields are ignored entirely, so
     * editing them changes nothing about how an address is judged and must invalidate
     * nothing.
     *
     * Hashing raw config instead would throw away every live shopper's verdicts - and
     * re-bill every address in every open checkout - on a change that had no effect at all.
     */
    public function testEditingIgnoredThresholdFieldsDoesNotInvalidateAnyVerdict(): void
    {
        // Advanced settings OFF, but the eight fields are populated (a merchant who
        // configured them and then turned the toggle off).
        $config = new ArrayObject(self::thresholdConfig(false, [
            'avc_verification_status' => 'V',
            'avc_post_match_level' => '5',
            'avc_pre_match_level' => '5',
            'avc_parsing_status' => 'I',
            'avc_lexicon_identification_match_level' => '2',
            'avc_context_identification_match_level' => '2',
            'avc_postcode_status' => 'P9',
            'avc_matchscore' => '99',
        ]));
        $shopper = $this->createShopper(null, 0, null, $config);
        $this->stubShopperResponses($shopper, [self::acceptedResponse()]);

        $shopper['validator']->verifyAddress(self::ADDRESS);
        $this->assertSame(1, $this->shopperCallCount($shopper), 'The first submission must reach the API.');

        // Every one of the eight ignored fields is edited.
        foreach ([
            'avc_verification_status' => 'U',
            'avc_post_match_level' => '0',
            'avc_pre_match_level' => '0',
            'avc_parsing_status' => 'U',
            'avc_lexicon_identification_match_level' => '0',
            'avc_context_identification_match_level' => '0',
            'avc_postcode_status' => 'P0',
            'avc_matchscore' => '10',
        ] as $field => $value) {
            $config['loqate_settings/verify_threshold_settings/' . $field] = $value;
        }

        $result = $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->shopperCallCount($shopper),
            'With advanced AVC settings OFF the eight threshold fields are not applied, so editing them must '
            . 'not invalidate a single cached verdict: the fingerprint has to be taken over the RESOLVED '
            . 'comparer values, never over the raw configuration.'
        );
        $this->assertSame(['error' => false], $result, 'The cached verdict must still be replayed.');
        $this->assertCount(1, $this->shopperStore($shopper), 'No second namespace may appear.');
    }

    /**
     * ...and the toggle itself. Flipping "show_advanced_avc_settings" is the change raw
     * config hashing would MISS while over-reacting to the ignored fields: it changes
     * every threshold at once without touching any of the eight values.
     *
     * Asserted in both directions, because it must invalidate when it changes the resolved
     * thresholds and must NOT when it does not (a merchant who never filled the fields in
     * resolves the same defaults either way).
     */
    public function testFlippingTheAdvancedAvcToggleInvalidatesOnlyWhenItChangesTheResolvedThresholds(): void
    {
        // (1) Thresholds configured away from the defaults, toggle OFF: the defaults apply.
        $config = new ArrayObject(self::thresholdConfig(false, ['avc_matchscore' => '50']));
        $shopper = $this->createShopper(null, 0, null, $config);
        $this->stubShopperResponses($shopper, [self::acceptedResponse()]);

        $shopper['validator']->verifyAddress(self::ADDRESS);
        $this->assertSame(1, $this->shopperCallCount($shopper), 'The first submission must reach the API.');

        // (2) Toggle ON: the configured match score now applies, so the resolved
        // thresholds - and therefore every verdict judged against them - have changed.
        $config['loqate_settings/verify_threshold_settings/show_advanced_avc_settings'] = '1';

        $afterFlip = $shopper['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->shopperCallCount($shopper),
            'Turning advanced AVC settings on changes the thresholds every cached verdict was judged '
            . 'against, so it must invalidate them.'
        );
        $this->assertSame(['error' => false], $afterFlip);

        // (3) A merchant who never configured the fields: the toggle resolves to the same
        // defaults either way, so flipping it must invalidate nothing.
        $emptyConfig = new ArrayObject(self::thresholdConfig(false, []));
        $unconfigured = $this->createShopper(null, 0, null, $emptyConfig);
        $this->stubShopperResponses($unconfigured, [self::acceptedResponse()]);

        $unconfigured['validator']->verifyAddress(self::ADDRESS);
        $emptyConfig['loqate_settings/verify_threshold_settings/show_advanced_avc_settings'] = '1';
        $unchanged = $unconfigured['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            1,
            $this->shopperCallCount($unconfigured),
            'With no threshold values configured, the toggle resolves to the same baked-in defaults, so '
            . 'flipping it must not re-bill anything: the fingerprint tracks what was APPLIED.'
        );
        $this->assertSame(['error' => false], $unchanged, 'The cached verdict must still be replayed.');
        $this->assertCount(1, $this->shopperStore($unconfigured), 'No second namespace may appear.');
    }

    /**
     * The fingerprint is a NAMESPACE, not a per-shopper salt: two shoppers whose resolved
     * thresholds are identical must land on the identical fingerprint (and the identical
     * signature), while a shopper on different applied thresholds must not.
     *
     * That is what keeps the segment doing exactly one job. A fingerprint that varied per
     * shopper, per request or per address would make every key unique, quietly disabling
     * the whole cache while every count-based test kept passing within one shopper.
     */
    public function testShoppersWithIdenticalThresholdsShareTheFingerprintSegment(): void
    {
        $thresholds = self::thresholdConfig(true, ['avc_matchscore' => '90']);
        $shopperA = $this->createShopper(null, 0, null, new ArrayObject($thresholds));
        $shopperB = $this->createShopper(null, 0, null, new ArrayObject($thresholds));
        $stricter = $this->createShopper(
            null,
            0,
            null,
            new ArrayObject(self::thresholdConfig(true, ['avc_matchscore' => '80']))
        );
        foreach ([$shopperA, $shopperB, $stricter] as $shopper) {
            $this->stubShopperResponses($shopper, [self::acceptedResponse()]);
            $shopper['validator']->verifyAddress(self::ADDRESS);
        }

        $keyA = (string)array_key_first($this->shopperStore($shopperA));
        $keyB = (string)array_key_first($this->shopperStore($shopperB));
        $keyStricter = (string)array_key_first($this->shopperStore($stricter));

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{12}$/',
            self::fingerprintSegment($keyA),
            'The fingerprint must be 12 hex characters: hex so it can never contain the "|" the signature '
            . 'parts are joined with, and truncated so the session payload does not carry 64 characters '
            . 'per entry.'
        );
        $this->assertSame(
            self::fingerprintSegment($keyA),
            self::fingerprintSegment($keyB),
            'Two shoppers judged against the same thresholds must share the fingerprint: it namespaces by '
            . 'configuration, nothing else.'
        );
        $this->assertNotSame(
            self::fingerprintSegment($keyA),
            self::fingerprintSegment($keyStricter),
            'Different applied thresholds must produce a different namespace.'
        );
        $this->assertSame(
            self::signatureSegment($keyA),
            self::signatureSegment($keyStricter),
            'One address must project to one signature whatever the thresholds are: the fingerprint must '
            . 'not leak into the signature.'
        );
    }

    /**
     * A PRIVACY assertion, not a formality: the cache instrumentation must never write a
     * customer address to the log.
     *
     * A log file is not the customer session. It is readable by anyone with server access,
     * it outlives the session, it is rotated into backups and shipped to aggregators, and
     * under UK/EU data protection law a log full of shoppers' home addresses is a
     * reportable problem. So the only things permitted on these lines are the outcome
     * (hit/miss), the key family, and a truncated hash - enough to reconcile the drop in
     * billable requests, and nothing that identifies anybody.
     *
     * Asserted as a WHITELIST (every debug record must match one exact shape) rather than
     * as a blacklist of forbidden strings, because a blacklist only catches the leaks
     * someone thought of. The explicit field-value checks are kept on top of it so a
     * failure names the risk.
     */
    public function testCacheOutcomeLoggingNeverLeaksTheAddressOrTheSignature(): void
    {
        $this->stubApiResponses([self::acceptedResponse(), self::rejectedResponse()]);

        $rejectedAddress = [
            'street' => ['12 Main Street'],
            'city' => 'Navan',
            'region' => 'Meath',
            'postcode' => 'C15 XXXX',
            'country_id' => 'IE',
        ];

        // A full realistic sequence: miss, lossy hit, miss, strict hit, and the unkeyed
        // case (an address with nothing identifiable in it).
        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress($rejectedAddress);
        $this->validator->verifyAddress($rejectedAddress);
        $this->validator->verifyAddress([]);

        $records = $this->cacheLogRecords($this->shopper);
        $this->assertSame(
            [['miss', 'none'], ['hit', 'lossy'], ['miss', 'none'], ['hit', 'strict'], ['miss', 'none']],
            array_map(static fn (array $r): array => [$r['outcome'], $r['family']], $records),
            'Every cache lookup outcome must be logged exactly once, in order.'
        );

        // Nothing identifying may appear in ANY captured record, in any casing - the
        // signature upper-cases its parts, so both forms are checked.
        $forbidden = [
            '1 High St', 'Flat 2', 'London', 'Greater London', 'SW1A 1AA', 'GB',
            '12 Main Street', 'Navan', 'Meath', 'C15 XXXX', 'IE',
        ];
        foreach (array_keys($this->verdictStore()) as $key) {
            // The namespaced key and the address signature inside it are PII too.
            $forbidden[] = (string)$key;
            $forbidden[] = self::signatureSegment((string)$key);
        }

        foreach ($this->logRecords($this->shopper) as $index => $record) {
            $haystack = $record['message'] . ' ' . json_encode($record['context']);
            foreach ($forbidden as $secret) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $secret,
                    $haystack,
                    sprintf(
                        'Log record #%d ("%s") contains "%s". The verify cache instrumentation must never '
                        . 'write the address, any address field or the raw signature to the log: a log file '
                        . 'outlives the session and is not the place for customer data.',
                        $index,
                        $record['message'],
                        $secret
                    )
                );
            }
        }

        // The hash is still useful: all events of ONE address share a token, and different
        // addresses get different ones - which is the whole reconciliation the log is for.
        $this->assertSame(
            $records[0]['token'],
            $records[1]['token'],
            'The miss and the following hit for one address must share a token, or the log cannot be used '
            . 'to reconcile hits against billable requests.'
        );
        $this->assertNotSame(
            $records[0]['token'],
            $records[2]['token'],
            'Two different addresses must not share a token.'
        );
        $this->assertSame(
            'unkeyed',
            $records[4]['token'],
            'An address with nothing identifiable in it has no cache key, and must be reported as unkeyed '
            . 'rather than as a hash of an empty key.'
        );
    }

    /**
     * What makes the instrumentation meaningful rather than decorative:
     *  - a MISS is logged before the billable request it accounts for, so misses and
     *    invoice lines can be counted one-to-one;
     *  - a HIT logs the family the verdict actually came from, which is only knowable
     *    because the two cache reads are separate and the strict one runs first;
     *  - a hit issues no request at all.
     */
    public function testCacheOutcomeLogsAreOrderedAndReportTheKeyFamilyTheVerdictCameFrom(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            ['log:miss', 'api', 'log:hit'],
            $this->eventTimeline($this->shopper),
            'A miss must be logged BEFORE the billable request it accounts for (so counting misses counts '
            . 'the Loqate invoice), and a hit must issue no request at all.'
        );

        $records = $this->cacheLogRecords($this->shopper);
        $this->assertSame(
            [['miss', 'none'], ['hit', 'lossy']],
            array_map(static fn (array $r): array => [$r['outcome'], $r['family']], $records),
            'A miss has no key family yet; a success is cached under the LOSSY (county-blind) key, so the '
            . 'hit must be reported as lossy.'
        );

        // A rejection is cached under the STRICT key, so its replay must say so - that is
        // the distinction the split cache reads exist to make visible.
        $rejecting = $this->createShopper();
        $this->stubShopperResponses($rejecting, [self::rejectedResponse()]);
        $rejecting['validator']->verifyAddress(self::ADDRESS);
        $rejecting['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            [['miss', 'none'], ['hit', 'strict']],
            array_map(
                static fn (array $r): array => [$r['outcome'], $r['family']],
                $this->cacheLogRecords($rejecting)
            ),
            'A replayed rejection must be reported as a STRICT hit: the family is what tells an operator '
            . 'whether a hit came from the rejection key or the county-blind success key.'
        );
    }

    /**
     * Defensive-read contract, as promised by getCachedVerifyResult()'s docblock: a
     * session store that is not an array at all (another module writing to the key, a
     * half-migrated session payload) must degrade to "not cached" - one extra API call
     * - and never throw inside a checkout plugin.
     */
    public function testANonArrayVerdictStoreDegradesToOneExtraApiCall(): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $this->assertCount(1, $this->verdictStore(), 'The verdict must be cached normally first.');

        $this->sessionStore[self::VERIFY_CACHE_SESSION_KEY] = 'not-an-array';

        $result = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'An unreadable verdict store must fall back to verifying the address, not throw.'
        );
        $this->assertSame(['error' => false], $result, 'The live verdict must still be returned.');
        $this->assertCount(
            1,
            $this->verdictStore(),
            'The corrupted store must be replaced by a fresh, usable one.'
        );
    }

    /**
     * Same contract for a single corrupted ENTRY: whatever shape it has, the address
     * is re-verified rather than the shopper meeting an exception. Each case models a
     * plausible corruption of the serialised payload.
     *
     * @param mixed $entry Corrupted value written over the cached entry.
     */
    #[DataProvider('corruptCacheEntryProvider')]
    public function testACorruptedCacheEntryDegradesToOneExtraApiCall($entry): void
    {
        $this->stubApiResponses([self::acceptedResponse()]);

        $this->validator->verifyAddress(self::ADDRESS);
        $store = $this->verdictStore();
        $key = (string)array_key_first($store);
        $store[$key] = $entry;
        $this->sessionStore[self::VERIFY_CACHE_SESSION_KEY] = $store;

        $result = $this->validator->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'A cached entry that cannot be read as a verdict must be re-verified, not throw.'
        );
        $this->assertSame(['error' => false], $result, 'The live verdict must still be returned.');
        $this->assertIsString(
            $this->verdictStore()[$key] ?? null,
            'The re-verified verdict must overwrite the corrupted entry with a usable one.'
        );
    }

    public static function corruptCacheEntryProvider(): array
    {
        return [
            'entry is not a string' => [['error' => false]],
            'entry is not serialised at all' => [true],
            'payload does not deserialise' => ['{not json'],
            'payload deserialises to a scalar' => ['"not-a-verdict"'],
            'payload deserialises to null' => ['null'],
            'payload has no verdict flag' => ['{"message":"The provided address is invalid."}'],
        ];
    }

    /**
     * And the same for a serializer that REJECTS the payload by throwing, which is
     * what Magento's Json serializer does on malformed input
     * (\InvalidArgumentException). The read must swallow it exactly as
     * checkForCapturedAddress() already does, so a poisoned session cannot 500 the
     * place-order request.
     */
    public function testASerializerThatRejectsThePayloadDegradesToOneExtraApiCall(): void
    {
        $throwing = $this->createShopper(null, 0, static function ($value): array {
            throw new \InvalidArgumentException('Unable to unserialize value. Error: Syntax error');
        });
        $this->stubShopperResponses($throwing, [self::acceptedResponse()]);

        $throwing['validator']->verifyAddress(self::ADDRESS);
        $result = $throwing['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->shopperCallCount($throwing),
            'A serializer that cannot read the cached payload must cost one extra API call, not an exception.'
        );
        $this->assertSame(['error' => false], $result, 'The live verdict must still be returned.');
    }

    /**
     * A response the Loqate Cleansing API accepts (AVC better than the default
     * threshold), shaped as verifyAddress() reads it: $response[0][0]['AVC'].
     */
    private static function acceptedResponse(): array
    {
        return [[['AVC' => self::PASSING_AVC, 'AQI' => 'A']]];
    }

    /** A response the API returns for an address it cannot verify. */
    private static function rejectedResponse(): array
    {
        return [[['AVC' => self::FAILING_AVC, 'AQI' => 'E']]];
    }

    /**
     * Queue the connector's responses. Each call consumes the next entry; the
     * last entry is reused for any further calls, so an over-calling
     * implementation fails on the call-count assertion rather than erroring.
     */
    private function stubApiResponses(array $responses): void
    {
        $this->stubShopperResponses($this->shopper, $responses);
    }

    /** stubApiResponses() for a specific shopper built by createShopper(). */
    private function stubShopperResponses(array $shopper, array $responses): void
    {
        $requests = $shopper['requests'];
        $events = $shopper['events'];
        $shopper['connector']->method('verifyAddress')->willReturnCallback(
            static function ($params) use ($requests, $responses, $events) {
                $requests[] = $params;
                // Recorded on the same timeline as the log records, so their ORDER is
                // assertable: a miss must be logged before the request it accounts for.
                $events[] = ['type' => 'api', 'message' => '', 'context' => []];

                return $responses[count($requests) - 1] ?? $responses[count($responses) - 1];
            }
        );
    }

    /** Number of billable Loqate Cleansing requests issued so far. */
    private function apiCallCount(): int
    {
        return count($this->apiRequests);
    }

    /** apiCallCount() for a specific shopper built by createShopper(). */
    private function shopperCallCount(array $shopper): int
    {
        return count($shopper['requests']);
    }

    /** The payload of the most recent billable request, as sent to the connector. */
    private function lastApiRequest(): array
    {
        $this->assertNotSame(0, $this->apiCallCount(), 'No request was issued, so there is none to inspect.');

        return (array)$this->apiRequests[$this->apiCallCount() - 1];
    }

    /** A unique, fully-formed address per index. */
    private function distinctAddress(int $index): array
    {
        return [
            'street' => [$index . ' Test Street'],
            'city' => 'London',
            'region' => 'Greater London',
            'postcode' => 'SW1A ' . $index . 'AA',
            'country_id' => 'GB',
        ];
    }

    /** The verify verdict cache as currently held in the session. */
    private function verdictStore(): array
    {
        return $this->shopperStore($this->shopper);
    }

    /** verdictStore() for a specific shopper built by createShopper(). */
    private function shopperStore(array $shopper): array
    {
        $store = $shopper['session'][self::VERIFY_CACHE_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    /**
     * Model a BRAND-NEW browser session: a different visitor, a cleared cookie, or a
     * session PHP has garbage-collected between visits. The backing data is empty, which is
     * precisely how this harness represents session state, so nothing an earlier session
     * cached can be read back.
     *
     * NOT a logout and NOT a session-id regeneration. Magento regenerates the session id on
     * both login and logout and the DATA survives that (see Helper\ShopperScopedSession);
     * emptying the store here would be the wrong model of either. The identity-change cases
     * are ShopperScopedSessionTest's subject - testACachedVerdictDoesNotSurviveALogin()
     * covers the logout/login hand-off, where the guard does the clearing that PHP does not.
     */
    private function startBrandNewSession(array $shopper): void
    {
        $shopper['session']->exchangeArray([]);
    }

    private function verifyCacheLimit(): int
    {
        if (!defined(Validator::class . '::VERIFY_CACHE_LIMIT')) {
            $this->fail(
                'Validator::VERIFY_CACHE_LIMIT is not defined: the verify verdict cache must be bounded.'
            );
        }

        return (int)constant(Validator::class . '::VERIFY_CACHE_LIMIT');
    }

    private function buildAddressSignature(array $address): string
    {
        return (string)$this->invokePrivate('buildAddressSignature', [$address]);
    }

    /**
     * The [base address, edited address] pair pinning one entry of
     * Validator::ADDRESS_MAPPING, failing loudly when a mapped field has no fixture.
     *
     * @param string $magentoField Magento address key from ADDRESS_MAPPING.
     * @param string $loqateField Loqate request field it is mapped onto.
     * @return array{0: array, 1: array}
     */
    private function mappedFieldFixture(string $magentoField, string $loqateField): array
    {
        $fixture = self::FIELD_EDIT_FIXTURES[$magentoField] ?? null;
        if ($fixture === null) {
            $this->fail(sprintf(
                'Validator::ADDRESS_MAPPING maps "%s" onto the Loqate field %s, but no fixture in '
                . 'self::FIELD_EDIT_FIXTURES pins it. Every field parseAddress() sends to Loqate must also '
                . 'be projected into the verify cache keys: a field that reaches the request but not the '
                . 'keys re-opens BOTH defects LOQ-16969 closed - the same address billed several times per '
                . 'checkout, and a cached rejection the shopper can never clear by editing that field. Add '
                . 'a base address plus an edit to "%s" here, then make sure both per-field tests pass.',
                $magentoField,
                $loqateField,
                $magentoField
            ));
        }

        return [$fixture['base'], array_merge($fixture['base'], $fixture['edit'])];
    }

    /**
     * Precondition for the per-field tests: the edit must genuinely change the request
     * Loqate is asked, otherwise "it must be verified again" would be asserting nothing.
     *
     * Established on two throwaway shoppers, one per address, so each issues its one
     * billable call regardless of how the cache behaves - the assertion is about the
     * payload only and cannot be satisfied or broken by the cache under test.
     *
     * @param array $base Address as first submitted.
     * @param array $edited Same address with exactly one mapped field changed.
     * @param string $loqateField Loqate field that change must show up in.
     */
    private function assertEditChangesTheLoqateRequest(array $base, array $edited, string $loqateField): void
    {
        $asBase = $this->createShopper();
        $asEdited = $this->createShopper();
        $this->stubShopperResponses($asBase, [self::acceptedResponse()]);
        $this->stubShopperResponses($asEdited, [self::acceptedResponse()]);

        $asBase['validator']->verifyAddress($base);
        $asEdited['validator']->verifyAddress($edited);

        $sentBase = (array)($asBase['requests'][0]['Addresses'][0] ?? []);
        $sentEdited = (array)($asEdited['requests'][0]['Addresses'][0] ?? []);

        $this->assertArrayHasKey(
            $loqateField,
            $sentBase,
            sprintf('The fixture must actually populate %s in the Loqate request.', $loqateField)
        );
        $this->assertNotSame(
            $sentBase[$loqateField] ?? null,
            $sentEdited[$loqateField] ?? null,
            sprintf(
                'The fixture edit must change %s in the request sent to Loqate, or this test would pass '
                . 'trivially for a field the cache legitimately cannot see.',
                $loqateField
            )
        );
    }

    /**
     * Read one of Validator's private constants, failing with an explanation when the
     * production code no longer defines it.
     *
     * @param string $name Constant name, e.g. 'COUNTY_FIELD'.
     * @return mixed
     */
    private function privateConstant(string $name)
    {
        $reflection = new ReflectionClass(Validator::class);
        if (!$reflection->hasConstant($name)) {
            $this->fail(sprintf(
                'Validator::%s is not defined: the asymmetric cache keys must name the field they differ '
                . 'by in exactly one place, so both key builders and these tests agree on it.',
                $name
            ));
        }

        return $reflection->getConstant($name);
    }

    /**
     * Store configuration for the AVC threshold tests: the advanced-settings toggle plus
     * whichever of the eight threshold fields the test cares about, under the real config
     * paths the production code reads.
     *
     * @param bool $advanced Value of "show_advanced_avc_settings".
     * @param array<string, string> $thresholds Threshold field name => value.
     * @return array<string, string>
     */
    private static function thresholdConfig(bool $advanced, array $thresholds): array
    {
        $base = 'loqate_settings/verify_threshold_settings/';
        $config = [$base . 'show_advanced_avc_settings' => $advanced ? '1' : '0'];
        foreach ($thresholds as $field => $value) {
            $config[$base . $field] = $value;
        }

        return $config;
    }

    /** The AVC-threshold fingerprint segment of a namespaced cache key. */
    private static function fingerprintSegment(string $key): string
    {
        return explode('|', $key)[1] ?? '';
    }

    /** The address signature part of a namespaced cache key (store view and fingerprint stripped). */
    private static function signatureSegment(string $key): string
    {
        return implode('|', array_slice(explode('|', $key), 2));
    }

    /**
     * The ONE line shape the verify cache instrumentation may write: an outcome, a key
     * family and a 12-hex hash (or "unkeyed"). Anything else - an address, a signature, a
     * store id - fails to match, which is what makes this a whitelist rather than a
     * guess at what a leak would look like.
     */
    private const CACHE_LOG_PATTERN =
        '/^Loqate verify cache (hit|miss) \[family=(strict|lossy|none), key=([0-9a-f]{12}|unkeyed)\]$/';

    /**
     * The cache-outcome debug records of a shopper, parsed, asserting on the way that each
     * one matches the whitelisted shape and carries no log context.
     *
     * @param array $shopper Shopper harness from createShopper().
     * @return array<int, array{outcome: string, family: string, token: string}>
     */
    private function cacheLogRecords(array $shopper): array
    {
        $parsed = [];
        foreach ($this->logRecords($shopper, 'debug') as $index => $record) {
            $this->assertMatchesRegularExpression(
                self::CACHE_LOG_PATTERN,
                $record['message'],
                sprintf(
                    'Debug record #%d ("%s") is not one of the permitted cache-outcome lines. These records '
                    . 'may contain the outcome, the key family and a truncated hash only - never the '
                    . 'address and never the signature.',
                    $index,
                    $record['message']
                )
            );
            $this->assertSame(
                [],
                $record['context'],
                sprintf(
                    'Debug record #%d carries a log context. The context is serialised into the log file '
                    . 'like the message, so it must not become a side channel for customer data.',
                    $index
                )
            );

            preg_match(self::CACHE_LOG_PATTERN, $record['message'], $matches);
            $parsed[] = ['outcome' => $matches[1], 'family' => $matches[2], 'token' => $matches[3]];
        }

        return $parsed;
    }

    /**
     * Every log record the Validator emitted for a shopper, in order.
     *
     * @param array $shopper Shopper harness from createShopper().
     * @param string|null $level Only records of this level ('debug', 'info'), or null for all.
     * @return array<int, array{type: string, message: string, context: array}>
     */
    private function logRecords(array $shopper, ?string $level = null): array
    {
        $records = array_values(array_filter(
            iterator_to_array($shopper['events']),
            static fn (array $event): bool => $event['type'] !== 'api'
                && ($level === null || $event['type'] === $level)
        ));

        return $records;
    }

    /**
     * The ordered timeline of what the Validator emitted, as compact tokens: 'log:hit',
     * 'log:miss' and 'api' for a billable request. Lets the tests assert that a miss is
     * logged BEFORE the request it accounts for, which is what makes the log usable to
     * reconcile the Loqate invoice.
     *
     * @param array $shopper Shopper harness from createShopper().
     * @return string[]
     */
    private function eventTimeline(array $shopper): array
    {
        $timeline = [];
        foreach ($shopper['events'] as $event) {
            if ($event['type'] === 'api') {
                $timeline[] = 'api';
                continue;
            }

            if ($event['type'] !== 'debug') {
                continue;
            }

            $timeline[] = preg_match('/cache (hit|miss) /', $event['message'], $matches)
                ? 'log:' . $matches[1]
                : 'log:' . $event['message'];
        }

        return $timeline;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function invokePrivate(string $method, array $args)
    {
        if (!method_exists(Validator::class, $method)) {
            $this->fail(sprintf(
                'Validator::%s() does not exist: the verify dedup key must be built by %s().',
                $method,
                $method
            ));
        }

        $reflection = new ReflectionMethod(Validator::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->validator, $args);
    }
}
