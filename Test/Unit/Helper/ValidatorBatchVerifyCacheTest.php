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
use ReflectionProperty;

/**
 * Unit tests for the BATCH verify path: Validator::verifyMultipleAddresses().
 *
 * Two tickets meet in this one method, and both are covered here because neither can be
 * asserted without the other:
 *
 * LOQ-16977 - INDEX MAPPING. The method must return one verdict per input address, under
 * the input's OWN KEY and in the input's OWN ORDER. Both halves were broken. The keys were
 * recovered afterwards with array_search(false, $addressesToCheck) over an array whose
 * values were all truthy, so the search always returned false, which coerces to the array
 * key 0: every response row overwrote $result[0], the captured addresses' own verdicts were
 * never merged in at all, and a mixed batch came back as a single entry. That matters to
 * two callers - Plugin\Admin\OrderSave.php:51-57 reports the key to the admin
 * ("The provided address is invalid: #billing_address"), and
 * Plugin\Admin\ValidateImportAddress.php:94 array_merge()s the per-chunk arrays and derives
 * the import row number from the merged $index + 1. The ORDER half is what the import
 * depends on: array_merge() renumbers integer keys BY INSERTION ORDER, not by key value, so
 * a chunk returned as [1, 3, 0, 2, 4] - precisely what filling cache hits during the first
 * pass and API verdicts afterwards produces - silently mis-attributes EVERY reported row
 * number. The result array is therefore pre-seeded with one slot per address before any
 * verdict is filled in; see testCacheHitsAndMissesStillReturnRowsInAscendingInputOrder().
 *
 * LOQ-16976 - BILLING. The Cleansing API is billable PER ADDRESS, not per request, so the
 * verdict cache is consulted per address BEFORE the address is added to the payload: only
 * misses are sent, and the tests here count "addresses billed" as
 * count($payload['Addresses']) summed over every invocation, not merely the number of
 * requests. Its design deliberately differs from verifyAddress()' cache in three ways, each
 * pinned below:
 *  - a PHYSICALLY SEPARATE session attribute (BATCH_VERIFY_CACHE_SESSION_KEY) with a
 *    different stored shape ({"valid":true} versus {"error":false}), because these verdicts
 *    come from the address quality index (checkQualityIndex()) and the other cache's from
 *    the AVC thresholds - one must never answer the other's lookup, see
 *    testTheTwoVerdictCachesAreInvisibleToEachOtherInBothDirections();
 *  - only PASSING verdicts are stored, see testAFailingVerdictIsNeverCached();
 *  - a single STRICT key with the county INCLUDED, not the asymmetric strict/lossy pair,
 *    which is safe precisely because no failure is ever cached, see
 *    testChangingOnlyTheCountyCostsASecondBillableAddressOnTheBatchPathOnly().
 * Entries are namespaced per store view and per resolved AQI threshold - NOT per resolved
 * AVC comparer string - see testChangingTheQualityIndexThresholdInvalidatesCachedVerdicts()
 * and testEditingTheAvcThresholdsInvalidatesNoBatchVerdict().
 *
 * POSITIONAL ATTRIBUTION is the precondition both tickets rest on, and it is the third thing
 * pinned here, because the connector makes it unverifiable per row:
 * Verify::verifyAddress() (vendor/lqt/api-connector/src/Client/Verify.php:50-52) ends in
 * array_column($response, 'Matches'), which SILENTLY DROPS every record with no 'Matches' key
 * and REINDEXES the survivors into a clean 0..N-1 list. A three-address batch whose middle
 * record came back as an error envelope therefore arrives as a TWO-element list in which
 * position 1 holds ADDRESS 3's verdict, indistinguishable from a truncated response. So the
 * row count is checked BEFORE any verdict is read or cached, and a mismatch fails the whole
 * batch with a log line rather than mis-numbering it:
 *  - the mid-list gap, and the wrong PASS that must never reach the cache, see
 *    testAMidListGapIsRejectedAndCachesNoVerdictAgainstTheWrongAddress();
 *  - the whole-response faults array_column() flattens to the same empty list, which used to
 *    produce zero verdicts, zero log lines and a fully unverified import, see
 *    testAnUnattributableWholeResponseIsRejectedWithADiagnostic();
 *  - and the accepted cost, a truncated response failing an import that could have been
 *    partly reported, see testATruncatedResponseIsRejectedRatherThanReportingTheRowsItHolds().
 *
 * READABLE VERDICTS is the fourth property pinned here, on the AQI side of the same method.
 * checkQualityIndex() (Helper/Validator.php:841-843) FAILS CLOSED on an AQI it cannot read,
 * mirroring verifyAddress()'s AVC guard (Helper/Validator.php:377). Under PHP 8's <= rules null,
 * '', false and 0 all compare as BETTER than any letter threshold, so an unreadable AQI used to
 * be answered VALID - including Loqate's own "no match for this address" ("Matches":[]), which
 * the row-count guard above cannot see, because array_column() preserves the row count for it.
 * Three tests hold this line, and the third is as load-bearing as the first two:
 *  - a row with no readable AQI in it at all, in every shape that survives the connector, see
 *    testARowWithNoReadableQualityIndexFailsClosedAndIsNeverCached();
 *  - an AQI that is PRESENT but unreadable ('', false, 0), see
 *    testAPresentButUnreadableQualityIndexFailsClosedAndIsNeverCached();
 *  - the OVER-REJECTION guard: a legitimate grade must still PASS and still be CACHED, so
 *    "fail closed" can never be widened into "reject everything" with the suite still green, see
 *    testALegitimateQualityIndexStillPassesAndIsStillCached().
 *
 * Each shopper gets its own session double AND its own connector mock (see createShopper()),
 * so a store moved into a static property, Registry or CacheInterface shows up as a missing
 * billable call on the second shopper's own connector.
 */
class ValidatorBatchVerifyCacheTest extends TestCase
{
    /** Any non-empty key makes the batch path reach the billable call. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /**
     * Configured address quality index threshold. checkQualityIndex() compares the
     * response's AQI against it with <=, as plain strings, so 'A' and 'B' pass a 'C'
     * threshold and 'E' does not.
     */
    private const AQI_THRESHOLD = 'C';

    /** AQI better than self::AQI_THRESHOLD => the address passes. */
    private const PASSING_AQI = 'A';

    /** AQI poorer than self::AQI_THRESHOLD => the address fails. */
    private const FAILING_AQI = 'E';

    /**
     * AVC strictly better than the baked-in default threshold "P40-U00-P0-95", so
     * verifyAddress() accepts. Only the cross-cache tests need it: the batch path reads
     * the AQI and ignores the AVC entirely.
     */
    private const PASSING_AVC = 'V55-I22-P9-99';

    /** Session data key the SINGLE-address verdict cache must live under. */
    private const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /** Session data key the BATCH verdict cache must live under. */
    private const BATCH_VERIFY_CACHE_SESSION_KEY = 'loqate_verified_batch_addresses';

    /** Config path of the threshold batch verdicts are judged against. */
    private const AQI_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /** A Magento-shaped address as it arrives from admin order create or an import row. */
    private const ADDRESS = [
        'street' => ['1 High St', 'Flat 2'],
        'city' => 'London',
        'region' => 'Greater London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
    ];

    /** @var Validator The Validator under test (the "primary" admin/import session). */
    private $validator;

    /** @var Verify|MockObject The billable API client of the primary session. */
    private $apiConnector;

    /** @var ArrayObject Payloads of every primary apiConnector->verifyAddress() call, in order. */
    private $apiRequests;

    /** @var ArrayObject Backing store for the primary customer session, so data actually persists. */
    private $sessionStore;

    /** @var array The primary harness, as returned by createShopper(). */
    private $shopper;

    /**
     * What the API answers per address, keyed by the address's first street line:
     * 'pass' (AQI better than the threshold), 'fail' (poorer), one of the
     * self::UNREADABLE_ROW_SHAPES tokens (a row with no readable AQI in it at all), or one of
     * the self::UNREADABLE_AQI_VALUES tokens (a row whose AQI is PRESENT but unreadable).
     * Anything absent from this map answers 'pass'.
     *
     * Shared by every shopper, because it models the API's opinion of an address, which
     * is not per session.
     *
     * @var array<string, string>
     */
    private $apiVerdicts = [];

    /**
     * When set, EVERY connector call returns the transport-failure envelope with this
     * message instead of verdicts, which is what makes verifyMultipleAddresses() return
     * false.
     *
     * @var string|null
     */
    private $apiFailureMessage = null;

    protected function setUp(): void
    {
        $this->apiVerdicts = [];
        $this->apiFailureMessage = null;

        $this->shopper = $this->createShopper();
        $this->validator = $this->shopper['validator'];
        $this->apiConnector = $this->shopper['connector'];
        $this->apiRequests = $this->shopper['requests'];
        $this->sessionStore = $this->shopper['session'];
    }

    /**
     * Build one independent "shopper": a Validator wired to its own customer session
     * double, its own billable connector mock and its own live store configuration.
     *
     * Modelled on ValidatorVerifyCacheTest::createShopper() and kept deliberately
     * similar, since the two caches have to be compared against each other here. The
     * connector is stubbed once, at construction: it answers from $this->apiVerdicts,
     * one response row per address in the payload IN PAYLOAD ORDER, so a test never has
     * to know how many addresses a batch will actually send - which is the very thing
     * most of these tests are asserting.
     *
     * Pass an existing $session to model the same admin/import session on a later
     * request, a $storeId to model a request handled by a different store view off the
     * same session, and a $config ArrayObject (built with self::configWith()) to model
     * store configuration read LIVE, so a test can change a value between two batches
     * the way a merchant saving the admin form does.
     *
     * Pass a $respond callback to answer a payload with something other than one row per
     * address - the malformed responses the positional attribution has to survive.
     *
     * @param ArrayObject|null $session Session backing store to reuse, or null for a new session.
     * @param int $storeId Store view the request is being handled by (Data::getCurrentStore()).
     * @param ArrayObject|null $config Live store configuration, config path => value.
     * @param callable|null $respond Replacement connector response builder, given the payload.
     * @return array{validator: Validator, connector: Verify&MockObject, requests: ArrayObject,
     *     session: ArrayObject, config: ArrayObject, events: ArrayObject}
     */
    private function createShopper(
        ?ArrayObject $session = null,
        int $storeId = 0,
        ?ArrayObject $config = null,
        ?callable $respond = null
    ): array {
        $sessionStore = $session ?? new ArrayObject();
        $requests = new ArrayObject();
        $configStore = $config ?? new ArrayObject(self::configWith([]));

        // One ordered timeline of everything the Validator emitted: every log record AND
        // every billable request, so a test can assert both WHAT was logged (never an
        // address) and WHEN (a miss must be logged before the request it accounts for).
        $events = new ArrayObject();

        $logger = $this->createMock(Logger::class);
        $recordLog = static function (string $level) use ($events): callable {
            return static function ($message, array $context = []) use ($events, $level) {
                $events[] = ['type' => $level, 'message' => (string)$message, 'context' => $context];
            };
        };
        $logger->method('debug')->willReturnCallback($recordLog('debug'));
        $logger->method('info')->willReturnCallback($recordLog('info'));

        // The shared Test/stubs Session is a no-op (getData() returns null, setData()
        // stores nothing), so no cache could ever survive between calls. This mock retains
        // data in $sessionStore, which also lets the tests read the two cache attributes
        // directly - and, because the store is per shopper, assert nothing crosses between
        // shoppers. getData()/setData() have to be *added* when the real
        // Magento\Customer\Model\Session is present, because it does not declare them
        // (SessionManager __call-forwards them to Session\Storage); the Test/stubs Session
        // does declare them, and PHPUnit refuses to "add" an existing method - hence the
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

        // No test here uses region_id, but parseAddress() resolves one through
        // RegionFactory, so the factory must not return null if a fixture ever grows one.
        $regionFactory = $this->createMock(RegionFactory::class);
        $regionFactory->method('create')->willReturnCallback(
            static function () {
                return new class {
                    public function load($regionId)
                    {
                        return $this;
                    }

                    public function getName()
                    {
                        return '';
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
                // A non-empty API key is required twice: the constructor only builds the
                // connector when it is set, and verifyMultipleAddresses() short-circuits
                // with noKeyFound when it is not.
                if ($configPath === 'loqate_settings/settings/api_key') {
                    return self::API_KEY;
                }

                // Anything not explicitly configured reads as empty, which is what the
                // admin form leaves behind for an untouched field.
                return $configStore[$configPath] ?? '';
            }
        );
        // Verdicts are namespaced per STORE VIEW, because the AQI threshold behind them is
        // read at SCOPE_STORE. Stubbed explicitly per shopper: getCurrentStore() is
        // declared int, so an unstubbed mock would auto-return 0 for EVERY shopper,
        // collapsing them into one namespace and making the scoping test below pass for
        // the wrong reason.
        $helper->method('getCurrentStore')->willReturn($storeId);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn ($value) => json_encode($value)
        );
        $serializer->method('unserialize')->willReturnCallback(
            static fn ($value) => json_decode($value, true)
        );

        $validator = new Validator(
            $logger,
            $sessionMock,
            $regionFactory,
            $moduleList,
            $helper,
            $serializer
        );

        // The connector is built inside the constructor (new Verify($apiKey)), so the only
        // way to intercept the billable call is to swap the private property afterwards.
        $connector = $this->createMock(Verify::class);
        $connector->method('verifyAddress')->willReturnCallback(
            function ($payload) use ($requests, $events, $respond) {
                $requests[] = $payload;
                $events[] = ['type' => 'api', 'message' => '', 'context' => []];

                if ($this->apiFailureMessage !== null) {
                    return ['error' => true, 'message' => $this->apiFailureMessage];
                }

                if ($respond !== null) {
                    return $respond($payload);
                }

                // One row per address SENT, in the order they were sent: that positional
                // correspondence is what the production code attributes verdicts by, and
                // it is exactly how the Cleansing API answers a batch.
                $rows = [];
                foreach ((array)($payload['Addresses'] ?? []) as $address) {
                    $rows[] = $this->responseRowFor((array)$address);
                }

                return $rows;
            }
        );
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
     * THE index-mapping guarantee (LOQ-16977), on the batch shape that broke it: some
     * addresses answered from the captured-address store, some sent to the API, and a
     * string-keyed input - exactly what Plugin\Admin\OrderSave.php hands in.
     *
     * Every input key must come back carrying ITS OWN verdict, in the input's order, and
     * the API must have been asked about the two uncaptured addresses only, in input
     * order. The pre-fix code returned a single entry keyed 0 (array_search(false, ...)
     * over all-truthy values returns false, which coerces to key 0), so both the admin
     * message and any row attribution named the wrong address.
     */
    public function testAMixedCapturedAndVerifiedBatchReturnsEveryVerdictUnderItsOwnInputKey(): void
    {
        $captured = $this->distinctAddress(1);
        $typed = $this->distinctAddress(2);
        $capturedToo = $this->distinctAddress(3);
        $typedAndFailing = $this->distinctAddress(4);
        $this->apiVerdicts[$typedAndFailing['street'][0]] = 'fail';

        $this->sessionStore['captured_addresses'] = [
            self::capturedEntry($captured),
            self::capturedEntry($capturedToo),
        ];

        $result = $this->validator->verifyMultipleAddresses([
            'a' => $captured,
            'b' => $typed,
            'c' => $capturedToo,
            'd' => $typedAndFailing,
        ]);

        $this->assertSame(
            ['a', 'b', 'c', 'd'],
            array_keys($result),
            'Every input address must be answered under its OWN key, and in the input\'s order: '
            . 'Plugin\Admin\OrderSave reports that key straight to the admin, and '
            . 'Plugin\Admin\ValidateImportAddress derives the import row number from it.'
        );
        $this->assertSame(
            ['a' => true, 'b' => true, 'c' => true, 'd' => false],
            $result,
            'Each key must carry the verdict of the address that came in under it - including the '
            . 'captured addresses, whose verdicts were never merged into the result at all before.'
        );

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'The two uncaptured addresses must be verified in a single batch request.'
        );
        $this->assertSame(
            [$typed['street'][0], $typedAndFailing['street'][0]],
            array_column($this->apiRequests[0]['Addresses'], 'Address1'),
            'Only the addresses that were NOT already captured may be sent, and they must be sent in '
            . 'input order: the response rows are attributed back positionally.'
        );
        $this->assertSame(
            2,
            $this->addressesBilled(),
            'The Cleansing API is billable per ADDRESS, so a four-address batch with two captured '
            . 'addresses must bill exactly two.'
        );
    }

    /**
     * The half of the mapping that is easiest to lose silently: a FAILING verdict.
     *
     * false is what every caller acts on, and the result array is filtered on the way out
     * (rows Loqate did not answer are dropped), so a filter written as array_filter($result)
     * would discard exactly the verdicts that matter and report a batch of invalid addresses
     * as entirely clean. Asserted with the failure in the LAST position of a mixed batch,
     * which is where the pre-fix single-slot bug hid it.
     */
    public function testAFailingVerdictSurvivesTheResultFilterUnderItsOwnKey(): void
    {
        $good = $this->distinctAddress(1);
        $bad = $this->distinctAddress(2);
        $this->apiVerdicts[$bad['street'][0]] = 'fail';

        $result = $this->validator->verifyMultipleAddresses([
            'shipping_address' => $good,
            'billing_address' => $bad,
        ], false);

        $this->assertSame(
            ['shipping_address' => true, 'billing_address' => false],
            $result,
            'A false verdict is the whole point of the call and must never be filtered out of the '
            . 'result: dropping it reports an invalid address as valid.'
        );
        $this->assertArrayHasKey(
            'billing_address',
            $result,
            'The failing address must keep its own key, or the admin is told the wrong address is invalid.'
        );
    }

    /**
     * The no-captured path, which admin import uses (verifyMultipleAddresses($batch, false)):
     * a plain list must still come back as keys 0..N-1 in input order, with each row's own
     * verdict. This is the shape ValidateImportAddress's row arithmetic is built on.
     */
    public function testAPlainListWithoutTheCapturedCheckIsAnsweredUnderKeysZeroToNMinusOne(): void
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = $this->distinctAddress($i);
        }
        $this->apiVerdicts[$rows[2]['street'][0]] = 'fail';

        $result = $this->validator->verifyMultipleAddresses($rows, false);

        $this->assertSame([0, 1, 2, 3], array_keys($result), 'A plain list must be answered 0..N-1.');
        $this->assertSame([true, true, false, true], array_values($result), 'Row 2 is the invalid one.');
        $this->assertSame(
            1,
            $this->apiCallCount(),
            'Nothing is cached or captured yet, so the whole list must go out in one request.'
        );
        $this->assertSame(4, $this->addressesBilled(), 'All four addresses are billed.');
    }

    /**
     * THE return-ORDER regression, and the property the customer import actually depends on.
     *
     * Plugin\Admin\ValidateImportAddress.php:94 array_merge()s the per-chunk result arrays
     * and reports ($index + 1) of the MERGED array as the import row number. array_merge()
     * renumbers integer keys BY INSERTION ORDER, not by key value, so a chunk that came back
     * as [1, 3, 0, 2, 4] - which is exactly what filling cache hits during the first pass
     * and API verdicts afterwards produces - would renumber to 0..4 in that order and
     * mis-attribute EVERY row number the merchant is shown. Pre-seeding one slot per address
     * before any verdict is filled in is what prevents it.
     *
     * Both halves are asserted: the per-chunk key order, and then the array_merge() of two
     * chunks - because the merge is the actual operation the import performs, and asserting
     * only the single-chunk keys would leave the property that breaks the import untested.
     */
    public function testCacheHitsAndMissesStillReturnRowsInAscendingInputOrder(): void
    {
        // Ten import rows; the two that will be reported invalid are rows 1 and 8 (import
        // rows #2 and #9), and both are deliberately CACHE MISSES, so their verdicts are
        // the ones filled in last and are therefore the ones a lost ordering misplaces.
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[$i] = $this->distinctAddress($i);
        }
        $this->apiVerdicts[$rows[1]['street'][0]] = 'fail';
        $this->apiVerdicts[$rows[8]['street'][0]] = 'fail';

        // Warm the cache with the rows that must come back as HITS: 0, 2 and 4 in the first
        // chunk and 5, 7 and 9 in the second, leaving 1, 3, 6 and 8 to be verified live.
        $this->validator->verifyMultipleAddresses(
            [$rows[0], $rows[2], $rows[4], $rows[5], $rows[7], $rows[9]],
            false
        );
        $this->assertSame(1, $this->apiCallCount(), 'The warm-up must be one request.');
        $this->assertSame(6, $this->addressesBilled(), 'The warm-up bills its six addresses.');

        $firstChunk = $this->validator->verifyMultipleAddresses(array_slice($rows, 0, 5), false);
        $secondChunk = $this->validator->verifyMultipleAddresses(array_slice($rows, 5, 5), false);

        $this->assertSame(
            [0, 1, 2, 3, 4],
            array_keys($firstChunk),
            'A chunk whose rows 0, 2 and 4 are cache hits and rows 1 and 3 are misses must still be '
            . 'returned in ASCENDING INPUT ORDER. Without pre-seeded slots this came back as '
            . '[1, 3, 0, 2, 4] - hits filled during the first pass, API verdicts afterwards.'
        );
        $this->assertSame([0, 1, 2, 3, 4], array_keys($secondChunk), 'The same holds for every chunk.');

        // ...and the operation the import really performs.
        $merged = array_merge($firstChunk, $secondChunk);

        $this->assertSame(
            range(0, 9),
            array_keys($merged),
            'array_merge() of the per-chunk results must yield ascending 0..9: it renumbers by '
            . 'INSERTION order, so any chunk that is not itself in input order silently shifts '
            . 'every import row number that follows it.'
        );
        $this->assertSame(
            [true, false, true, true, true, true, true, true, false, true],
            array_values($merged),
            'Each merged position must still carry the verdict of the row that occupied it.'
        );

        // The arithmetic ValidateImportAddress.php:107-117 performs on that merged array.
        $reportedRows = [];
        foreach ($merged as $index => $validAddress) {
            if (!$validAddress) {
                $reportedRows[] = $index + 1;
            }
        }
        $this->assertSame(
            [2, 9],
            $reportedRows,
            'The merchant must be told rows #2 and #9 are invalid - the rows that really are. This is '
            . 'the property the whole ordering guarantee exists for: a mis-ordered chunk points them '
            . 'at rows they would then "fix" while the genuinely bad ones import.'
        );

        $this->assertSame(
            3,
            $this->apiCallCount(),
            'One warm-up request plus one per chunk: the cache hits must issue no request of their own.'
        );
        $this->assertSame(
            10,
            $this->addressesBilled(),
            'Six warm-up addresses plus the four genuine misses. The six hits must cost nothing: the '
            . 'API is billed per address, not per request.'
        );
    }

    /**
     * THE billing guarantee (LOQ-16976), on the case the merchant actually hits: an admin
     * re-submitting the same order, or an import re-run after fixing an unrelated column.
     *
     * A five-address batch submitted twice must bill five addresses in total, and the second
     * submission must issue NO request at all - not an empty 'Addresses' payload to a
     * billable endpoint - while returning the identical verdicts.
     */
    public function testAnIdenticalBatchSubmittedTwiceBillsItsAddressesOnce(): void
    {
        // Every address passes: a rejection is deliberately never cached (see
        // testAFailingVerdictIsNeverCached()), so a batch containing one could not make the
        // "no second request at all" claim this test is about.
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->distinctAddress($i);
        }

        $first = $this->validator->verifyMultipleAddresses($batch, false);
        $second = $this->validator->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            1,
            $this->apiCallCount(),
            'The second submission of an identical batch must short-circuit before the billable call: '
            . 'every address it needs is already verified in this session.'
        );
        $this->assertSame(
            5,
            $this->addressesBilled(),
            'Five addresses submitted twice must appear on the invoice five times, not ten.'
        );
        $this->assertSame(
            [0, 1, 2, 3, 4],
            array_keys($first),
            'All five rows must be answered, under their own keys and in input order.'
        );
        $this->assertSame(
            [true, true, true, true, true],
            array_values($first),
            'The live verdicts must be as the API answered them.'
        );
        $this->assertSame(
            $first,
            $second,
            'The replayed batch must return the identical verdicts, keys and order.'
        );
    }

    /**
     * The partial case, which is what a re-run import or a corrected admin order looks like:
     * three of the five addresses are already verified, so only the OTHER TWO may be sent -
     * and they must be sent in input order, because response rows are attributed positionally.
     */
    public function testOnlyTheAddressesNotAlreadyVerifiedAreSentToTheApi(): void
    {
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->distinctAddress($i);
        }

        // Rows 0, 1 and 4 verified by an earlier submission.
        $this->validator->verifyMultipleAddresses([$batch[0], $batch[1], $batch[4]], false);
        $this->assertSame(3, $this->addressesBilled(), 'The earlier submission bills its three addresses.');

        $result = $this->validator->verifyMultipleAddresses($batch, false);

        $this->assertSame(2, $this->apiCallCount(), 'The second submission must issue exactly one request.');
        $sent = $this->apiRequests[1]['Addresses'];
        $this->assertCount(
            2,
            $sent,
            'Only the two addresses with no cached verdict may be added to the payload: the request is '
            . 'billed per address it carries, so sending the cached three would bill them again.'
        );
        $this->assertSame(
            [$batch[2]['street'][0], $batch[3]['street'][0]],
            array_column($sent, 'Address1'),
            'The two misses must be exactly rows 2 and 3, in input order.'
        );
        $this->assertSame(
            5,
            $this->addressesBilled(),
            'Five distinct addresses across two submissions must cost five billed addresses.'
        );
        $this->assertSame([0, 1, 2, 3, 4], array_keys($result), 'All five rows must still be answered.');
        $this->assertSame([true, true, true, true, true], array_values($result));
    }

    /**
     * The two caches are SEPARATE STORES, asserted by reading the two session attributes.
     *
     * verifyAddress() judges an address against the AVC thresholds; verifyMultipleAddresses()
     * judges it against the address quality index. The thresholds are different settings, so
     * one verdict must never answer the other's lookup - a collision there would let an AVC
     * verdict satisfy an AQI check, silently bypassing a threshold the merchant configured.
     * The separation is therefore structural (two attributes, and two different stored value
     * shapes) rather than a matter of prefixing one array carefully.
     */
    public function testEachVerifyPathWritesOnlyItsOwnSessionCache(): void
    {
        $this->validator->verifyAddress(self::ADDRESS);

        $singleStore = $this->singleStore($this->shopper);
        $this->assertCount(
            1,
            $singleStore,
            'verifyAddress() must cache its verdict under "' . self::VERIFY_CACHE_SESSION_KEY . '".'
        );
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'verifyAddress() must write NOTHING to the batch cache: its verdict comes from the AVC '
            . 'thresholds and would be read there as an AQI verdict.'
        );
        $this->assertSame(
            ['error' => false],
            json_decode((string)reset($singleStore), true),
            'The single-address cache stores an "error" flag.'
        );

        $batchOnly = $this->createShopper();
        $batchOnly['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $batchStore = $this->batchStore($batchOnly);
        $this->assertCount(
            1,
            $batchStore,
            'verifyMultipleAddresses() must cache its verdict under "'
            . self::BATCH_VERIFY_CACHE_SESSION_KEY . '".'
        );
        $this->assertSame(
            [],
            $this->singleStore($batchOnly),
            'verifyMultipleAddresses() must write NOTHING to the single-address cache.'
        );
        $this->assertSame(
            ['valid' => true],
            json_decode((string)reset($batchStore), true),
            'The batch cache stores a "valid" flag, not an "error" one: the differing shape is a second, '
            . 'independent guard - an entry written by the other cache fails the shape check here and '
            . 'degrades to a miss instead of being read as a verdict.'
        );
    }

    /**
     * ...and the behavioural consequence, in BOTH directions: for a textually identical
     * address, a verdict earned by one method is invisible to the other, and costs a second
     * billable call.
     *
     * That is a deliberate cost, not an oversight. The two verdicts answer different
     * questions (AVC thresholds versus the AQI), so replaying one for the other would report
     * a verdict the configuration never produced. Asserted both ways round, because either
     * path can run first in a real Magento request.
     */
    public function testTheTwoVerdictCachesAreInvisibleToEachOtherInBothDirections(): void
    {
        // (1) Single first, then the batch path for the same address.
        $singleFirst = $this->createShopper();
        $singleFirst['validator']->verifyAddress(self::ADDRESS);
        $this->assertSame(1, $this->shopperCallCount($singleFirst), 'The single verification is billed.');

        $singleFirst['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            2,
            $this->shopperCallCount($singleFirst),
            'The batch path must NOT be served the single-address cache\'s verdict: that verdict was '
            . 'judged against the AVC thresholds, and this path judges the address quality index.'
        );
        $this->assertCount(1, $this->singleStore($singleFirst), 'One entry in the single-address cache...');
        $this->assertCount(1, $this->batchStore($singleFirst), '...and one in the batch cache.');

        // (2) The other way round.
        $batchFirst = $this->createShopper();
        $batchFirst['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $this->assertSame(1, $this->shopperCallCount($batchFirst), 'The batch verification is billed.');

        $result = $batchFirst['validator']->verifyAddress(self::ADDRESS);

        $this->assertSame(
            2,
            $this->shopperCallCount($batchFirst),
            'verifyAddress() must NOT be served the batch cache\'s verdict either: the separation has to '
            . 'hold in both directions, or the reader that gets it wrong decides which threshold applies.'
        );
        $this->assertSame(['error' => false], $result, 'The live AVC verdict must be returned.');
    }

    /**
     * A FAILING verdict is never cached, and that is load-bearing rather than tidiness.
     *
     * It is what makes the strict-only cache key safe: because no rejection is ever stored,
     * an admin or an import can never be stranded on a replayed "invalid" that they cannot
     * clear (the checkout dead-end the single-address cache needs its asymmetric key pair to
     * avoid). The cost is stated and asserted here: a failing address is re-billed on every
     * submission.
     */
    public function testAFailingVerdictIsNeverCached(): void
    {
        $good = $this->distinctAddress(1);
        $bad = $this->distinctAddress(2);
        $this->apiVerdicts[$bad['street'][0]] = 'fail';

        $first = $this->validator->verifyMultipleAddresses([$good, $bad], false);

        $this->assertSame([true, false], array_values($first), 'The second address must be rejected.');
        $this->assertCount(
            1,
            $this->batchStore($this->shopper),
            'Only the PASSING verdict may be cached: a stored rejection is what would force the cache '
            . 'key to drop the county, widening the bypass for every address.'
        );

        $second = $this->validator->verifyMultipleAddresses([$good, $bad], false);

        $this->assertSame(2, $this->apiCallCount(), 'The failing address must be sent again.');
        $this->assertSame(
            [$bad['street'][0]],
            array_column($this->apiRequests[1]['Addresses'], 'Address1'),
            'ONLY the failing address may be re-sent: the passing one is cached, so re-billing it too '
            . 'would defeat the fix.'
        );
        $this->assertSame(
            3,
            $this->addressesBilled(),
            'Two addresses submitted twice, one of them cacheable: three billed addresses.'
        );
        $this->assertSame([true, false], array_values($second), 'The verdicts must be unchanged.');
    }

    /**
     * A response row with no readable AQI in it FAILS CLOSED and is never cached.
     *
     * Such a row is not a verdict, it is a fault or a non-answer: the connector collapses any
     * per-record shape it does not recognise (a bad key, a changed schema, a PER-RECORD error
     * envelope) into a row nothing can be read out of, and Loqate's own "no match for this
     * address" arrives the same way, as "Matches":[]. Reporting either as "valid" is the
     * maximally wrong answer, so checkQualityIndex()'s shape guard
     * (Helper/Validator.php:841-843) rejects it, exactly as verifyAddress() already rejected an
     * unreadable AVC (Helper/Validator.php:377): both verify paths now draw the "readable
     * verdict" line in the same place. A TOP-LEVEL error envelope is NOT in this class - it makes
     * HttpClient::searchForError() throw, Verify::verifyAddress() catches it into
     * ['error' => true, ...], and the branch at Helper/Validator.php:616-623 answers it instead.
     *
     * EVERY shape asserted here survives the connector's array_column($response, 'Matches') with
     * the ROW COUNT PRESERVED (see self::UNREADABLE_ROW_SHAPES), so the row-count guard
     * (Helper/Validator.php:643-651) catches none of them and they all reach the attribution loop
     * (Helper/Validator.php:703-711) as an AQI of null. This guard is the only thing between them
     * and a PASS. That includes "Matches":null, which was missing from this taxonomy until the
     * guard was written and is a survivor of array_column() just like [] is (verified on 8.3).
     *
     * Nothing may be cached either, and that is now ONE rule seen once rather than twice: the row
     * is a FALSE verdict, and storeBatchVerifyResult() stores no failure - so the call site needs
     * no separate readability test of its own.
     *
     * The sibling case, an AQI that is PRESENT but unreadable ('', false, 0), reaches the same
     * guard by a different route and lives in
     * testAPresentButUnreadableQualityIndexFailsClosedAndIsNeverCached(). The guard must NOT be
     * widened past these shapes; testALegitimateQualityIndexStillPassesAndIsStillCached() is what
     * stops that.
     *
     * @param string $token self::UNREADABLE_ROW_SHAPES token naming the row shape to answer with.
     */
    #[DataProvider('unreadableRowShapeProvider')]
    public function testARowWithNoReadableQualityIndexFailsClosedAndIsNeverCached(string $token): void
    {
        $unreadable = $this->distinctAddress(1);
        $this->apiVerdicts[$unreadable['street'][0]] = $token;

        // Fixture-realism guard, ASSERTED rather than claimed in a comment: this shape really does
        // reach the attribution loop, because the connector's array_column($response, 'Matches')
        // keeps a record whose 'Matches' is [] or null instead of dropping it. Were that not so,
        // the row count would differ and this test would silently be exercising the count guard
        // (Helper/Validator.php:643-651) rather than the AQI shape guard it is written for.
        $this->assertSame(
            [self::UNREADABLE_ROW_SHAPES[$token]],
            array_column([['Matches' => self::UNREADABLE_ROW_SHAPES[$token]]], 'Matches'),
            'This row shape must survive the connector\'s array_column() with the count preserved, or the '
            . 'test is not reaching the guard it claims to test.'
        );

        $first = $this->validator->verifyMultipleAddresses([$unreadable], false);

        $this->assertSame(
            [0 => false],
            $first,
            'An AQI the module could not read is NOT a verdict, so it must fail CLOSED. Answering true - '
            . 'which is what null <= any letter threshold used to produce - let ONE malformed or '
            . 'match-less response row PASS an address: admin order create went straight through '
            . '(Plugin\Admin\OrderSave.php:50-58 sees true and raises no error) and the import row was '
            . 'accepted unverified. For the "Matches":[] shape that meant reporting Loqate\'s own "no '
            . 'match for this address" as VALID.'
        );
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'A row we cannot read an AQI out of is a fault, not a verdict, so nothing may be cached: '
            . 'otherwise a single connector fault decides that address for the rest of the session. The '
            . 'rejection is not cached either - storeBatchVerifyResult() stores no failure - so the next '
            . 'submission asks the API again instead of being stranded on an unreadable response.'
        );

        $second = $this->validator->verifyMultipleAddresses([$unreadable], false);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The next submission must retry the API rather than replay the unreadable response: the '
            . 'accepted cost of caching nothing is that the address is billed again.'
        );
        $this->assertSame(
            [0 => false],
            $second,
            'And it must fail closed again: a fault that repeats is still not a verdict.'
        );
        $this->assertSame([], $this->batchStore($this->shopper), 'Still nothing cached after the retry.');
    }

    /**
     * The response-row shapes that carry no readable AQI, one data set each.
     *
     * @return array<string, array{0: string}>
     */
    public static function unreadableRowShapeProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::UNREADABLE_ROW_SHAPES) as $token) {
            $cases['response row is a ' . $token] = [$token];
        }

        return $cases;
    }

    /**
     * The batch cache key is STRICT - county INCLUDED - and this is where that differs
     * observably from the single-address cache, whose SUCCESS key deliberately drops the
     * county so capture.js rewriting "Meath" to "Co. Meath" cannot re-bill an address.
     *
     * Both designs are asserted here, side by side and on the same address, because the
     * difference is intentional and each half is only defensible in the light of the other:
     * the batch path can afford a strict key precisely because it never caches a rejection,
     * so it has no dead-end to avoid, and an AQI plausibly depends on the county - a
     * county-blind success cache here would be a WIDER bypass than the one the single-address
     * cache already accepts. The stated cost: one extra billed address per county rewrite.
     */
    public function testChangingOnlyTheCountyCostsASecondBillableAddressOnTheBatchPathOnly(): void
    {
        $address = self::ADDRESS;
        $countyRewritten = array_merge($address, ['region' => 'Co. London']);

        $this->validator->verifyMultipleAddresses([$address], false);
        $this->validator->verifyMultipleAddresses([$countyRewritten], false);

        $this->assertSame(
            2,
            $this->addressesBilled(),
            'The batch cache key includes the county, so rewriting it is a MISS and the address is '
            . 'verified again. That is the accepted cost of a strict key on a path that never caches '
            . 'a rejection and therefore needs no county-blind escape hatch.'
        );
        $this->assertCount(
            2,
            $this->batchStore($this->shopper),
            'The two county spellings must occupy their own cache entries.'
        );

        // The single-address cache, same address, same rewrite: no second call, because its
        // SUCCESS key drops the county. Two designs, deliberately different.
        $single = $this->createShopper();
        $single['validator']->verifyAddress($address);
        $single['validator']->verifyAddress($countyRewritten);

        $this->assertSame(
            1,
            $this->shopperCallCount($single),
            'The single-address cache must still absorb a county rewrite: that is the LOQ-16969 fix, and '
            . 'the two caches really do key differently - they are not interchangeable.'
        );
    }

    /**
     * The batch cache must be bounded, and must evict the OLDEST entry first.
     *
     * Unbounded is not an option: the addresses go into the customer session, which is
     * serialised on every request, and an import can present thousands of rows. Bounded but
     * newest-first would be worse than useless, because the entry most likely to be needed
     * again is the one just written.
     */
    public function testTheBatchCacheIsBoundedAndEvictsTheOldestVerdictFirst(): void
    {
        $limit = $this->batchCacheLimit();

        // One batch of limit + 5 distinct addresses: they are cached in the order the
        // response rows are processed, which is the order they were sent.
        $batch = [];
        for ($i = 1; $i <= $limit + 5; $i++) {
            $batch[] = $this->distinctAddress($i);
        }
        $this->validator->verifyMultipleAddresses($batch, false);

        $store = $this->batchStore($this->shopper);
        $this->assertCount(
            $limit,
            $store,
            'Once more than BATCH_VERIFY_CACHE_LIMIT addresses have been verified the cache must hold '
            . 'exactly BATCH_VERIFY_CACHE_LIMIT entries: no more (the session payload must stay '
            . 'bounded) and no fewer (a cache that under-fills re-bills addresses it had room to keep).'
        );

        $callsAfterFill = $this->apiCallCount();
        $billedAfterFill = $this->addressesBilled();

        // The newest address must have survived...
        $this->validator->verifyMultipleAddresses([$batch[$limit + 4]], false);
        $this->assertSame(
            $billedAfterFill,
            $this->addressesBilled(),
            'The most recently verified address must survive eviction: it is the likeliest to be asked '
            . 'about again.'
        );
        $this->assertSame($callsAfterFill, $this->apiCallCount(), 'An all-hit batch must issue no request.');

        // ...while the oldest five have been evicted and must be verified again.
        $this->validator->verifyMultipleAddresses([$batch[0]], false);
        $this->assertSame(
            $billedAfterFill + 1,
            $this->addressesBilled(),
            'The oldest entries must be the ones evicted once the cache is full.'
        );
        $this->assertCount(
            $limit,
            $this->batchStore($this->shopper),
            'Re-verifying an evicted address must not push the cache past its limit.'
        );
    }

    /**
     * The bound must be a bound, not a cliff: every one of the BATCH_VERIFY_CACHE_LIMIT
     * verdicts the cache claims to hold has to be genuinely replayable.
     *
     * Asserted by filling the cache to exactly the limit and then re-submitting the whole
     * batch with no further BILLED ADDRESS, which is what distinguishes a real FIFO cache of
     * LIMIT entries from one that keeps only the newest verdict (or one whose effective limit
     * is far smaller than advertised) - both of which satisfy a bare "count <= limit"
     * assertion. It also pins the property the limit was chosen for: two full import chunks
     * of 100 rows must fit at once, or a re-run import re-bills rows the cache had room for.
     */
    public function testEveryVerdictUpToTheBatchCacheLimitIsRetainedAndReplayable(): void
    {
        $limit = $this->batchCacheLimit();

        $batch = [];
        for ($i = 1; $i <= $limit; $i++) {
            $batch[] = $this->distinctAddress($i);
        }
        $this->validator->verifyMultipleAddresses($batch, false);

        $this->assertSame(
            $limit,
            $this->addressesBilled(),
            'Each of the ' . $limit . ' distinct addresses must be verified exactly once.'
        );
        $this->assertCount(
            $limit,
            $this->batchStore($this->shopper),
            'A cache bounded to ' . $limit . ' entries must actually be able to hold ' . $limit . ' verdicts.'
        );

        // Re-submit the whole batch, and then every address individually, oldest first.
        $replayed = $this->validator->verifyMultipleAddresses($batch, false);
        for ($i = 1; $i <= $limit; $i++) {
            $this->validator->verifyMultipleAddresses([$this->distinctAddress($i)], false);
        }

        $this->assertSame(
            $limit,
            $this->addressesBilled(),
            'Every verdict up to the limit must still be cached: replaying all ' . $limit
            . ' addresses must not cost a single further billed address.'
        );
        $this->assertSame(1, $this->apiCallCount(), 'And not a single further request either.');
        $this->assertSame(
            array_fill(0, $limit, true),
            array_values($replayed),
            'Every replayed verdict must come back intact, under its own key.'
        );
        $this->assertCount(
            $limit,
            $this->batchStore($this->shopper),
            'Replaying cached verdicts must not grow the cache.'
        );
    }

    /**
     * The staleness the AQI fingerprint in the cache key exists to fix: a batch verdict is a
     * function of the address AND of the quality threshold it was judged against, so once a
     * merchant tightens or loosens that threshold, verdicts computed under the old one must
     * not be replayed.
     *
     * The threshold is showInStore="1" and read at SCOPE_STORE, so the store view has to be
     * part of the namespace too - one session can span store views (?___store=, a language
     * switcher) - and that half is asserted here as well.
     */
    public function testChangingTheQualityIndexThresholdInvalidatesCachedVerdicts(): void
    {
        $config = new ArrayObject(self::configWith([]));
        $shopper = $this->createShopper(null, 0, $config);

        $shopper['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $this->assertSame(1, $this->shopperCallCount($shopper), 'The first submission must reach the API.');
        $keyBefore = (string)array_key_first($this->batchStore($shopper));

        // The merchant tightens the accepted quality. The address is unchanged.
        $config[self::AQI_CONFIG_PATH] = 'B';

        $shopper['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            2,
            $this->shopperCallCount($shopper),
            'Changing the address quality index threshold must invalidate the cached verdicts: they were '
            . 'judged against the old threshold, so replaying them reports a verdict the current '
            . 'configuration never produced.'
        );

        $store = $this->batchStore($shopper);
        $this->assertCount(2, $store, 'The two verdicts must be cached under their own threshold namespaces.');
        $keyAfter = (string)array_keys($store)[1];
        $this->assertNotSame(
            self::fingerprintSegment($keyBefore),
            self::fingerprintSegment($keyAfter),
            'The threshold fingerprint segment of the key must change with the applied threshold.'
        );
        $this->assertSame(
            self::signatureSegment($keyBefore),
            self::signatureSegment($keyAfter),
            'The threshold must NAMESPACE the key, not leak into the address signature: one address must '
            . 'still project to one signature.'
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{12}$/',
            self::fingerprintSegment($keyBefore),
            'The fingerprint must be 12 hex characters: hex so it can never contain the "|" the signature '
            . 'parts are joined with, and truncated so the session payload does not carry 64 characters '
            . 'per entry.'
        );
    }

    /**
     * The other half of that contract, and the reason this cache fingerprints the AQI and NOT
     * resolveComparerAvcString(): the AVC thresholds have no bearing whatsoever on a batch
     * verdict, which comes from checkQualityIndex(). Editing them - all eight fields and the
     * advanced-settings toggle - must therefore invalidate nothing.
     *
     * Fingerprinting the wrong threshold would be wrong in both directions at once: it would
     * throw away every cached batch verdict on a change that could not affect one, and it
     * would NOT invalidate them when the AQI itself changed, which is the change that
     * actually matters (see the previous test).
     */
    public function testEditingTheAvcThresholdsInvalidatesNoBatchVerdict(): void
    {
        $base = 'loqate_settings/verify_threshold_settings/';
        $config = new ArrayObject(self::configWith([
            $base . 'show_advanced_avc_settings' => '1',
            $base . 'avc_matchscore' => '90',
        ]));
        $shopper = $this->createShopper(null, 0, $config);

        $shopper['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $this->assertSame(1, $this->shopperCallCount($shopper), 'The first submission must reach the API.');

        // Every AVC threshold field the module has, plus the toggle that decides whether they
        // apply at all.
        foreach ([
            'show_advanced_avc_settings' => '0',
            'avc_verification_status' => 'U',
            'avc_post_match_level' => '0',
            'avc_pre_match_level' => '0',
            'avc_parsing_status' => 'U',
            'avc_lexicon_identification_match_level' => '0',
            'avc_context_identification_match_level' => '0',
            'avc_postcode_status' => 'P0',
            'avc_matchscore' => '10',
        ] as $field => $value) {
            $config[$base . $field] = $value;
        }

        $result = $shopper['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            1,
            $this->shopperCallCount($shopper),
            'The AVC thresholds do not enter a batch verdict at all - this path judges the address '
            . 'quality index - so editing them must not invalidate a single cached verdict, let alone '
            . 're-bill every address in an open import.'
        );
        $this->assertSame([0 => true], $result, 'The cached verdict must still be replayed.');
        $this->assertCount(1, $this->batchStore($shopper), 'No second namespace may appear.');
    }

    /**
     * Store-view namespacing, asserted the way the single-address cache's tests assert it:
     * two store views in ONE session must never answer for each other, because the AQI
     * threshold behind their verdicts is resolved at SCOPE_STORE - yet the namespace must be
     * a NAMESPACE, so the same address still projects to the same signature under both.
     */
    public function testVerdictsAreNotReplayedAcrossStoreViewsInTheSameSession(): void
    {
        $sharedSession = new ArrayObject();
        $storeOne = $this->createShopper($sharedSession, 1);
        $storeTwo = $this->createShopper($sharedSession, 2);

        $storeOne['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $storeTwo['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(1, $this->shopperCallCount($storeOne), 'Store view 1 must verify the address itself.');
        $this->assertSame(
            1,
            $this->shopperCallCount($storeTwo),
            'Store view 2 must not be served store view 1\'s verdict: the quality threshold behind it is '
            . 'resolved at SCOPE_STORE and the two views can configure it differently.'
        );

        $keys = array_keys($this->batchStore($storeOne));
        $this->assertCount(2, $keys, 'Both verdicts must coexist in the one session, namespaced per store view.');
        $this->assertCount(
            1,
            array_unique(array_map(
                static fn (string $key): string => self::signatureSegment($key),
                $keys
            )),
            'One address must project to one signature: only the store view namespace may differ.'
        );
    }

    /**
     * The same session on a later request must still get the saving: the admin order-create
     * screen and the import both build a fresh Validator per request, so the verdicts have to
     * be read back out of the shared customer session rather than held in instance state.
     *
     * Pinned to the same NON-DEFAULT store view on both requests, so this asserts a genuine
     * hit inside one namespace and not two shoppers collapsing into store view 0.
     */
    public function testALaterRequestInTheSameSessionAndStoreViewReplaysTheCachedVerdicts(): void
    {
        $sharedSession = new ArrayObject();
        $firstRequest = $this->createShopper($sharedSession, 7);
        $firstRequest['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $this->assertSame(1, $this->shopperCallCount($firstRequest), 'The first request must bill the address.');

        // Same session and store view, brand new Validator, and the API now answers "fail"
        // for this address - so a verdict of "valid" can only have come out of the session.
        $laterRequest = $this->createShopper($sharedSession, 7);
        $this->apiVerdicts[self::ADDRESS['street'][0]] = 'fail';

        $result = $laterRequest['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            0,
            $this->shopperCallCount($laterRequest),
            'A new Validator instance in the same session must replay the cached verdict, not re-bill it.'
        );
        $this->assertSame([0 => true], $result, 'The replayed verdict must be the one the earlier request wrote.');
    }

    /**
     * A verdict is customer data, so it may live in the per-shopper customer session and
     * nowhere with a longer lifetime. Two independent sessions verifying the same address
     * must each be billed: a store "optimised" into a static property, Registry or
     * CacheInterface would serve the first session's verdict to the second - suppressing a
     * verification that never happened for it.
     */
    public function testTwoSeparateSessionsVerifyingTheSameBatchAreEachBilled(): void
    {
        $sessionA = $this->createShopper();
        $sessionB = $this->createShopper();

        $sessionA['validator']->verifyMultipleAddresses([self::ADDRESS], false);
        $sessionB['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(1, $this->shopperCallCount($sessionA), 'The first session verifies the address once.');
        $this->assertSame(
            1,
            $this->shopperCallCount($sessionB),
            'A second session must NOT be served the first one\'s cached verdict: the batch verdict cache '
            . 'must be per customer session, never static/Registry/CacheInterface state.'
        );
        $this->assertCount(1, $this->batchStore($sessionA), 'Each session must hold its own verdict store.');
        $this->assertCount(1, $this->batchStore($sessionB), 'Each session must hold its own verdict store.');
    }

    /**
     * A transport failure is not a verdict. verifyMultipleAddresses() returns false, nothing
     * is cached, the failure is reported AS ITSELF in the log, and the next attempt retries -
     * so an import re-run after the outage is not stranded on a failure it can never clear.
     *
     * BOTH BATCH SIZES ARE EXERCISED, because the two ways the isset($response['error'])
     * branch can be broken - deleting it, and demoting it below the row-count guard - are each
     * INVISIBLE to one of them. The connector reports a transport failure as
     * ['error' => true, 'message' => ...], a TWO-ELEMENT array, so its element count collides
     * with the address count of precisely the batch Plugin\Admin\OrderSave.php:29-36 sends:
     * billing plus shipping, TWO addresses.
     *  - TWO addresses pins that the branch EXISTS. count($response) === 2 === count($sentItems),
     *    so the row-count guard does NOT fire and cannot stand in for the branch. Delete the
     *    branch and the envelope reaches the attribution loop, which reads
     *    true[0]['AQI'] ?? null => null, and checkQualityIndex(null) FAIL-OPENS: both addresses
     *    are reported VALID and the admin order is placed unverified during any Loqate outage,
     *    with no diagnostic anywhere. A one-address batch hides that completely, because 2 !== 1
     *    makes the guard fire and return false for entirely the wrong reason.
     *  - ONE address pins its POSITION above the guard - which the note above the guard states
     *    as established fact ("the branch directly above already returns for them and always
     *    did"). Here the counts DO differ, so demoting the branch below the guard lets the
     *    guard answer first and the outage is reported as "answered 2 rows for 1 addresses":
     *    a false diagnostic that sends whoever reads it after a blocked import hunting a Loqate
     *    schema change instead of a network fault. The two-address arm cannot see that
     *    reordering at all - with the counts equal the guard is skipped either way.
     * Hence both arms assert the LOGGED MESSAGE and not merely the false return: the demotion
     * still returns false, and the log line is the only place it shows.
     */
    public function testATransportFailureIsReportedAsItselfOnAnySizedBatchNotCachedAndRetried(): void
    {
        $this->apiFailureMessage = 'cURL error 28: Operation timed out';
        $batch = [$this->distinctAddress(1), $this->distinctAddress(2)];

        $failed = $this->validator->verifyMultipleAddresses($batch, false);

        $this->assertFalse(
            $failed,
            'A failed billable call must be reported as false, which is the shape '
            . 'Plugin\Admin\ValidateImportAddress has to handle without merging it. Answering '
            . '[true, true] here - which is what the error envelope becomes if it ever reaches the '
            . 'attribution loop - places the admin order with both addresses unverified.'
        );
        $this->assertSame(
            ['cURL error 28: Operation timed out'],
            array_column($this->logRecords($this->shopper, 'info'), 'message'),
            'A transport failure must be reported as itself, not as a row-count mismatch: the error envelope '
            . 'is a 2-element array, so on a two-address batch the count guard would pass it through and '
            . 'report BOTH addresses valid.'
        );
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'Nothing may be written to the verdict cache while the API is failing.'
        );

        // A ONE-address batch during the same outage: the counts now differ (2 elements against
        // 1 address), so this is the arm that pins the error branch's POSITION above the
        // row-count guard. Its own shopper, so its log is read in isolation.
        $oneAddress = $this->createShopper();
        $this->assertFalse(
            $oneAddress['validator']->verifyMultipleAddresses([self::ADDRESS], false),
            'A transport failure fails the batch whatever its size.'
        );
        $this->assertSame(
            ['cURL error 28: Operation timed out'],
            array_column($this->logRecords($oneAddress, 'info'), 'message'),
            'The transport error must be reported ahead of the row-count guard. Demoting it below the guard '
            . 'leaves the outage logged as "answered 2 rows for 1 addresses" - the error envelope\'s own '
            . 'element count read as a row count - which is a false diagnostic, and the return value is '
            . 'false either way so nothing else here can catch it.'
        );

        $this->apiFailureMessage = null;
        $recovered = $this->validator->verifyMultipleAddresses($batch, false);

        $this->assertSame(2, $this->apiCallCount(), 'The retry must reach the API again.');
        $this->assertSame(
            [0 => true, 1 => true],
            $recovered,
            'The retry must return the live verdict, one per address, under the input\'s own keys.'
        );
        $this->assertCount(
            2,
            $this->batchStore($this->shopper),
            'Once the retry succeeds its verdicts must be cached like any other, so the failure has not '
            . 'poisoned the session into re-billing forever either.'
        );
    }

    /**
     * An empty batch must cost nothing at all: no request, and no empty 'Addresses' payload
     * sent to a billable endpoint just to discard the answer.
     */
    public function testAnEmptyBatchIssuesNoRequest(): void
    {
        $result = $this->validator->verifyMultipleAddresses([], false);

        $this->assertSame([], $result, 'There is nothing to report for an empty batch.');
        $this->assertSame(0, $this->apiCallCount(), 'An empty batch must not reach the billable endpoint.');
    }

    /**
     * The instrumentation that makes the saving auditable, and its PRIVACY rule.
     *
     * Misses map ONE-TO-ONE onto billed addresses, which is what lets the drop in the Loqate
     * invoice be reconciled from the log without waiting for the invoice. And nothing that
     * identifies anybody may appear on those lines: a log file outlives the session, is
     * readable by anyone with server access and is shipped to aggregators, so the address and
     * the raw signature are both forbidden. Asserted as a WHITELIST - every debug record must
     * match one exact shape - because a blacklist only catches the leaks someone thought of.
     */
    public function testBatchCacheLoggingAccountsForEveryBilledAddressWithoutLeakingOne(): void
    {
        $batch = [$this->distinctAddress(1), $this->distinctAddress(2)];

        $this->validator->verifyMultipleAddresses($batch, false);
        $this->validator->verifyMultipleAddresses($batch, false);

        $records = $this->batchCacheLogRecords($this->shopper);
        $this->assertSame(
            ['miss', 'miss', 'hit', 'hit'],
            array_column($records, 'outcome'),
            'Every cache lookup outcome must be logged exactly once, in order.'
        );
        $this->assertSame(
            $this->addressesBilled(),
            count(array_filter($records, static fn (array $r): bool => $r['outcome'] === 'miss')),
            'Each miss must correspond to exactly one BILLED ADDRESS, or the log cannot be used to '
            . 'reconcile the invoice.'
        );
        $this->assertSame(
            ['log:miss', 'log:miss', 'api', 'log:hit', 'log:hit'],
            $this->eventTimeline($this->shopper),
            'The misses must be logged BEFORE the request they account for, and a fully cached batch must '
            . 'issue no request at all.'
        );
        $this->assertSame(
            $records[0]['token'],
            $records[2]['token'],
            'The miss and the later hit for one address must share a token, or the hits cannot be matched '
            . 'against the requests they replaced.'
        );
        $this->assertNotSame($records[0]['token'], $records[1]['token'], 'Two addresses must not share a token.');

        $forbidden = ['1 Test Street', '2 Test Street', 'London', 'Greater London', 'SW1A', 'GB'];
        foreach (array_keys($this->batchStore($this->shopper)) as $key) {
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
                        'Log record #%d ("%s") contains "%s". The batch verify cache instrumentation must '
                        . 'never write the address, any address field or the raw signature to the log: a '
                        . 'log file outlives the session and is not the place for customer data.',
                        $index,
                        $record['message'],
                        $secret
                    )
                );
            }
        }
    }

    /**
     * Defensive-read contract: a batch cache store that is not an array at all (another
     * module writing to the key, a half-migrated session payload) or an entry that cannot be
     * read as a verdict must degrade to "not cached" - one extra billed address - and never
     * throw in the middle of an import.
     */
    public function testAnUnreadableBatchCacheDegradesToOneExtraBilledAddress(): void
    {
        $this->validator->verifyMultipleAddresses([self::ADDRESS], false);
        $this->assertCount(1, $this->batchStore($this->shopper), 'The verdict must be cached normally first.');

        $this->sessionStore[self::BATCH_VERIFY_CACHE_SESSION_KEY] = 'not-an-array';
        $this->validator->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(2, $this->apiCallCount(), 'An unreadable store must fall back to verifying, not throw.');
        $this->assertCount(
            1,
            $this->batchStore($this->shopper),
            'The corrupted store must be replaced by a fresh, usable one.'
        );

        // A single corrupted ENTRY, which is the reachable case: the key is present but its
        // payload cannot be read, so the read misses and the verdict is rewritten in place.
        $store = $this->batchStore($this->shopper);
        $key = (string)array_key_first($store);
        $store[$key] = '{not json';
        $this->sessionStore[self::BATCH_VERIFY_CACHE_SESSION_KEY] = $store;

        $this->validator->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(3, $this->apiCallCount(), 'A corrupted entry must be re-verified, not throw.');
        $this->assertSame(
            ['valid' => true],
            json_decode((string)($this->batchStore($this->shopper)[$key] ?? ''), true),
            'The re-verified verdict must overwrite the corrupted entry with a usable one.'
        );
    }

    /**
     * An entry written by the SINGLE-address cache must not be readable as a batch verdict
     * even if the two stores are ever conflated by a future change: the value shapes differ
     * ("error" versus "valid"), so the shape check degrades it to a miss.
     *
     * This is the second, independent guard behind the separate session attribute, and it is
     * the one that still holds after somebody "simplifies" the two stores into one.
     */
    public function testASingleAddressVerdictPlantedInTheBatchStoreIsNotReadAsAVerdict(): void
    {
        // Earn a real batch cache key, then overwrite its value with the other cache's shape.
        $this->validator->verifyMultipleAddresses([self::ADDRESS], false);
        $store = $this->batchStore($this->shopper);
        $key = (string)array_key_first($store);
        $store[$key] = json_encode(['error' => false]);
        $this->sessionStore[self::BATCH_VERIFY_CACHE_SESSION_KEY] = $store;

        $this->validator->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'A verdict in the single-address cache\'s shape must not satisfy a batch lookup: these '
            . 'verdicts answer different questions - the AVC thresholds versus the address quality '
            . 'index - so reading one as the other would apply a threshold the merchant did not choose.'
        );
    }

    /**
     * An address with nothing identifiable in it has an empty signature, which is the
     * sentinel that keeps it out of the cache entirely: it must neither be written (poisoning
     * the store under an empty key) nor be served another address's verdict.
     */
    public function testAnUnidentifiableAddressIsNeitherCachedNorServedACachedVerdict(): void
    {
        $result = $this->validator->verifyMultipleAddresses([[], [], self::ADDRESS], false);

        $this->assertSame([0 => true, 1 => true, 2 => true], $result, 'All three rows must be answered.');
        $this->assertSame(
            3,
            $this->addressesBilled(),
            'Two unidentifiable addresses must both be sent - neither may satisfy the other, nor the real '
            . 'address - so all three are billed.'
        );
        $this->assertCount(
            1,
            $this->batchStore($this->shopper),
            'Only the identifiable address may be cached.'
        );
        $this->assertArrayNotHasKey(
            '',
            $this->batchStore($this->shopper),
            'An empty signature must never be used as a cache key.'
        );

        // ...and the unidentifiable rows are still not cached on a second pass.
        $this->validator->verifyMultipleAddresses([[], []], false);
        $this->assertSame(5, $this->addressesBilled(), 'An unidentifiable address is billed every time.');
    }

    /**
     * The same address appearing TWICE in one batch: both copies miss the cache in the first
     * pass, so both are sent, and the second response row refreshes the entry the first one
     * wrote. Both keys must still be answered, and the entry must not be duplicated.
     *
     * A realistic import shape (the same address on two customer rows), and the one place
     * where storeBatchVerifyResult() writes to a key that is already present.
     *
     * The COST is asserted rather than glossed over: within ONE batch the cache cannot help,
     * because it is only written once the response comes back, so the same address is billed once
     * per OCCURRENCE. Collapsing in-batch duplicates into a single billable address is tracked as
     * LOQ-17015, "the same address repeated within one batch is billed once per occurrence"; the
     * assertion below is what will have to change when it is done, so the current cost is visible
     * rather than rediscovered from an invoice.
     */
    public function testADuplicatedAddressInOneBatchIsAnsweredUnderBothKeys(): void
    {
        $result = $this->validator->verifyMultipleAddresses([self::ADDRESS, self::ADDRESS], false);

        $this->assertSame([0 => true, 1 => true], $result, 'Both rows must be answered.');
        $this->assertSame(
            2,
            $this->addressesBilled(),
            'The deferred cost of LOQ-17015, pinned: both occurrences are sent in the one request, so the '
            . 'same address is billed twice. Only a LATER batch is answered from the cache.'
        );
        $this->assertCount(
            1,
            $this->batchStore($this->shopper),
            'The two identical rows describe one address, so they must share one cache entry.'
        );

        $replayed = $this->validator->verifyMultipleAddresses([self::ADDRESS, self::ADDRESS], false);

        $this->assertSame(1, $this->apiCallCount(), 'The second submission must be answered entirely from cache.');
        $this->assertSame([0 => true, 1 => true], $replayed, 'Both rows must be answered from the one entry.');
    }

    /**
     * THE defect the row-count guard exists for, in the exact shape it reaches us in: a MID-LIST
     * GAP, where the response is not merely short but SHIFTED.
     *
     * Verify::verifyAddress() ends in array_column($response, 'Matches'), which silently drops
     * every record with no 'Matches' key and reindexes the survivors into a clean 0..N-1 list
     * (verified on PHP 8.3). So a three-address batch whose MIDDLE record came back as an error
     * envelope arrives here as a two-element list in which position 1 holds ADDRESS 3's verdict.
     * Nothing in the response says so.
     *
     * Before the guard, address 2 was handed address 3's PASS and - far worse -
     * storeBatchVerifyResult() persisted that pass against ADDRESS 2's SIGNATURE, so a "valid"
     * that Loqate never gave for that address was replayed, without a request, for the rest of
     * the session. That is the "wrong valid replayed" failure the strict cache key exists to
     * prevent, arriving through the one door the single-address path does not have.
     *
     * All three halves are asserted: the batch is refused, the cache stays EMPTY, and address 2
     * is genuinely still billable afterwards - which is the property the merchant experiences.
     */
    public function testAMidListGapIsRejectedAndCachesNoVerdictAgainstTheWrongAddress(): void
    {
        // First call: three addresses sent, two rows back - exactly what array_column() makes of
        // a three-record response whose middle record has no 'Matches' key. Second call: the
        // connector behaves, and REJECTS whatever it is asked about, so a verdict of true on the
        // second pass could only have come out of the cache.
        $call = 0;
        $shopper = $this->createShopper(null, 0, null, function ($payload) use (&$call): array {
            $call++;
            if ($call === 1) {
                $this->assertCount(
                    3,
                    (array)$payload['Addresses'],
                    'Fixture guard: all three addresses must be sent, or this test is not exercising the '
                    . 'mid-list gap at all.'
                );

                return [
                    [['AQI' => self::PASSING_AQI]],
                    [['AQI' => self::PASSING_AQI]],
                ];
            }

            return array_map(
                static fn (): array => [['AQI' => self::FAILING_AQI]],
                (array)$payload['Addresses']
            );
        });

        $addresses = [
            $this->distinctAddress(1),
            $this->distinctAddress(2),
            $this->distinctAddress(3),
        ];

        $result = $shopper['validator']->verifyMultipleAddresses($addresses, false);

        $this->assertFalse(
            $result,
            'Two rows for three addresses cannot be attributed to the addresses sent, so the whole batch '
            . 'must be refused. Attributing them positionally hands address 2 address 3\'s verdict, and '
            . 'the response gives no way to detect that.'
        );
        $this->assertCount(
            0,
            $this->batchStore($shopper),
            'THIS IS THE DEFECT the guard exists for: with a mid-list gap, address 3\'s PASS was persisted '
            . 'against ADDRESS 2\'s signature and replayed - with no request and no log line - for the rest '
            . 'of the session. Nothing whatsoever may be cached from a response whose rows cannot be '
            . 'attributed: not the rows before the gap either, because which rows those are is precisely '
            . 'what is unknowable.'
        );
        $this->assertSame(
            ['Loqate batch verify answered 2 rows for 3 addresses; verdicts not attributed.'],
            array_column($this->logRecords($shopper, 'info'), 'message'),
            'The refusal must leave exactly one diagnostic naming both counts, at INFO so it is actually '
            . 'written (Logger/Handler.php pins the handler at INFO, so a DEBUG line would be dropped).'
        );

        // ...and the consequence for the merchant: address 2 is still unverified, so it must be
        // sent again and must be answered by the API rather than from the cache.
        $second = $shopper['validator']->verifyMultipleAddresses([$addresses[1]], false);

        $this->assertSame(
            2,
            $this->shopperCallCount($shopper),
            'Address 2 must be verified again: a rejected batch caches nothing, so nothing can be replayed.'
        );
        $this->assertSame(
            [0 => false],
            $second,
            'And it must get the verdict the API actually gives it. Before the guard this answered true '
            . 'from the cache, without a request - a rejection the merchant could never see.'
        );
    }

    /**
     * The whole-response faults that used to be COMPLETELY SILENT.
     *
     * array_column() flattens every body it cannot read as records to the same empty list, and
     * none of these shapes sets $response['error'], so before the count guard they produced
     * zero verdicts, zero log lines and an import that proceeded ENTIRELY UNVERIFIED with no
     * diagnostic at all: verifyMultipleAddresses() returned [], which
     * Plugin\Admin\ValidateImportAddress merges as "no rows to report" and
     * Plugin\Admin\OrderSave iterates as "no address is invalid".
     *
     * Each shape must now return false - the value both callers fail closed on - and leave
     * exactly one INFO record naming the two counts.
     *
     * @param mixed $response What the connector hands back for a two-address payload.
     * @param int $answeredRows Row count the diagnostic must report.
     */
    #[DataProvider('unattributableResponseProvider')]
    public function testAnUnattributableWholeResponseIsRejectedWithADiagnostic(
        $response,
        int $answeredRows
    ): void {
        $shopper = $this->createShopper(null, 0, null, static fn ($payload) => $response);

        $result = $shopper['validator']->verifyMultipleAddresses(
            [$this->distinctAddress(1), $this->distinctAddress(2)],
            false
        );

        $this->assertFalse(
            $result,
            'A response that cannot be attributed to the addresses sent must be refused. Returning an '
            . 'empty verdict array instead - which is what this used to do - is read by every caller as '
            . '"nothing to report": the import proceeds with every row unverified and nobody is told.'
        );
        $this->assertSame(
            [],
            $this->batchStore($shopper),
            'There is no verdict here to cache, so the cache must be untouched.'
        );
        $this->assertSame(
            [sprintf(
                'Loqate batch verify answered %d rows for 2 addresses; verdicts not attributed.',
                $answeredRows
            )],
            array_column($this->logRecords($shopper, 'info'), 'message'),
            'Exactly one diagnostic, naming what came back and what was asked, at INFO so it survives '
            . 'the handler\'s level (Logger/Handler.php pins it at INFO). The absence of this line is '
            . 'what made these faults undiagnosable: an unverified import looked exactly like a clean one.'
        );
    }

    /**
     * Response shapes that carry no attributable verdicts for a two-address batch.
     *
     * The first is what the connector's array_column() produces from ANY 200 body it cannot
     * read as records - a {"error": "..."} envelope, a {} body, a changed schema - and also
     * from a response every one of whose records lacks 'Matches'. The second is the raw
     * {"Items": ...} shape: array_column() collapses it to the empty list too, and it is
     * asserted in its own right because it is what the connector would hand straight through
     * if that array_column() were ever removed, and it must be refused either way. The third
     * pins the is_array() half of the guard, which count() alone would not cover.
     */
    public static function unattributableResponseProvider(): array
    {
        return [
            'an empty list, what array_column() makes of any unreadable body' => [[], 0],
            'a {"Items": ...} envelope' => [['Items' => [['AQI' => self::PASSING_AQI]]], 1],
            'not an array at all' => [null, 0],
        ];
    }

    /**
     * One response row per address SENT is the contract positional attribution rests on. A
     * response with MORE rows than addresses cannot be attributed either - there is no way to
     * tell WHICH rows are the surplus ones - so the whole batch is refused rather than the
     * surplus guessed at, and in particular no verdict is cached against a key a surplus row
     * was never about.
     */
    public function testSurplusResponseRowsAreRejectedRatherThanMisattributed(): void
    {
        // Two rows for a one-address payload.
        $shopper = $this->createShopper(null, 0, null, static fn ($payload): array => [
            [['AQI' => self::PASSING_AQI]],
            [['AQI' => self::FAILING_AQI]],
        ]);

        $result = $shopper['validator']->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertFalse(
            $result,
            'A row count that does not match the addresses sent must fail the batch, whichever direction '
            . 'it is wrong in: the first row looks like the answer to the one address sent, but nothing '
            . 'in a response with a spurious row establishes that it is.'
        );
        $this->assertCount(
            0,
            $this->batchStore($shopper),
            'NOTHING may be cached from an unattributable response - not even the first row. A verdict '
            . 'stored against the wrong signature is replayed for the whole session, and that is the one '
            . 'failure this cache must never produce.'
        );
        $this->assertSame(
            ['Loqate batch verify answered 2 rows for 1 addresses; verdicts not attributed.'],
            array_column($this->logRecords($shopper, 'info'), 'message'),
            'Exactly one diagnostic naming both counts, so an over-long response is as visible in the log '
            . 'as a short one.'
        );
    }

    /**
     * A genuinely TRUNCATED response - Loqate answering fewer rows than it was sent, with no
     * shift involved - fails the whole batch too, and that is the ACCEPTED COST of the count
     * guard rather than a happy accident.
     *
     * The rejected alternative, which this test used to pin, was to report the rows that did
     * come back. It is not available, because a truncated response is INDISTINGUISHABLE from a
     * shifted one: array_column() reindexes the survivors of a dropped record into the same
     * clean short list, so "the first N rows are the first N addresses" cannot be established
     * from the response. Reporting anyway meant later import chunks were silently renumbered by
     * ValidateImportAddress's array_merge(), and the trade is one-sided: a mis-numbered row
     * sends the merchant to edit a VALID row while the genuinely bad one imports unnoticed,
     * whereas a blocked import is loud, retryable and loses nothing.
     */
    public function testATruncatedResponseIsRejectedRatherThanReportingTheRowsItHolds(): void
    {
        // One row for a two-address payload.
        $shopper = $this->createShopper(null, 0, null, static fn ($payload): array => [
            [['AQI' => self::PASSING_AQI]],
        ]);

        $result = $shopper['validator']->verifyMultipleAddresses(
            [$this->distinctAddress(1), $this->distinctAddress(2)],
            false
        );

        $this->assertFalse(
            $result,
            'One row for two addresses must fail the whole batch. The alternative - answering row 0 and '
            . 'leaving row 1 unreported - reads a truncated response as if it were known not to be a '
            . 'shifted one, which the response does not say.'
        );
        $this->assertCount(
            0,
            $this->batchStore($shopper),
            'The one row that did come back may not be cached either: whether it belongs to address 1 is '
            . 'exactly what is unknown.'
        );
        $this->assertSame(
            ['Loqate batch verify answered 1 rows for 2 addresses; verdicts not attributed.'],
            array_column($this->logRecords($shopper, 'info'), 'message'),
            'The blocked import must be explained in the log, or the merchant is told to "try again" with '
            . 'nothing on the server saying why.'
        );
    }

    /**
     * An AQI that is PRESENT but not readable as a quality index FAILS CLOSED and is never
     * cached - the second route into the same guard.
     *
     * The guard is a READABILITY test - is this a non-empty string (Helper/Validator.php:841-843)
     * - and deliberately not a null check, and that difference is the whole point of this test:
     * under PHP 8's <= rules '' <= 'A', false <= 'A' and 0 <= 'A' are ALL true, so every value
     * here used to be answered VALID, and a guard written as "$qualityIndex !== null" would still
     * answer valid AND cache it - storing a genuine-looking verdict nobody computed and replaying
     * it, without a request, for the rest of the session.
     * testARowWithNoReadableQualityIndexFailsClosedAndIsNeverCached() cannot catch that: its rows
     * carry no 'AQI' at all, so they arrive as the null a null check already handles.
     *
     * The rejection is the correct answer, not a compromise: an unreadable AQI is not a verdict,
     * so the batch path answers it the way verifyAddress() answers an unreadable AVC
     * (Helper/Validator.php:377). Nothing being cached follows from the same rule - a false
     * verdict is never stored - so a fault answers this one row and dies with the request, and the
     * next identical batch asks the API again.
     *
     * Note the deliberate contrast with
     * testALegitimateQualityIndexStillPassesAndIsStillCached(): the INT 0 here is rejected because
     * it is not a string, while the STRING '0' passes there. The guard is on shape, not truthiness.
     *
     * @param string $token $apiVerdicts token naming the unreadable AQI value to answer with.
     */
    #[DataProvider('unreadableQualityIndexProvider')]
    public function testAPresentButUnreadableQualityIndexFailsClosedAndIsNeverCached(string $token): void
    {
        $address = $this->distinctAddress(1);
        $this->apiVerdicts[$address['street'][0]] = $token;

        $first = $this->validator->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            [0 => false],
            $first,
            'An AQI that is present but unreadable must fail CLOSED. It used to answer "valid" - '
            . '"" <= "C", false <= "C" and 0 <= "C" are all true under PHP 8 - so ONE malformed response '
            . 'row made that address PASS: admin order create went through '
            . '(Plugin\Admin\OrderSave.php:50-58 sees true and raises no error) and the import row was '
            . 'accepted unverified, while verifyAddress() already failed CLOSED on the equivalent '
            . 'unreadable AVC (Helper/Validator.php:377). The two paths must agree, and this is where the '
            . 'AQI side is held.'
        );
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'Nothing may be cached: a guard written as "$qualityIndex !== null" would answer TRUE for this '
            . 'value and store it as a real PASS, so ONE malformed response would pass that address for '
            . 'the whole session, with no request and no way to notice. The guard therefore asks whether '
            . 'the AQI is READABLE, not whether it is null - and because the answer is false, '
            . 'storeBatchVerifyResult()\'s never-cache-a-failure rule keeps it out of the store on its own.'
        );

        $second = $this->validator->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            2,
            $this->apiCallCount(),
            'The next submission must retry the API rather than replay a verdict read out of a value the '
            . 'module could not interpret.'
        );
        $this->assertSame(
            2,
            $this->addressesBilled(),
            'Re-billing the address is the accepted cost of caching no failure: an unreadable response '
            . 'must never strand an address on a rejection it cannot clear.'
        );
        $this->assertSame([0 => false], $second, 'The verdict is unchanged on the retry.');
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'Still nothing cached after the retry: a fault that repeats is still not a verdict.'
        );
    }

    /**
     * The AQI values a response can carry that are not usable quality indexes. Each is a real
     * JSON shape - "AQI":"" , "AQI":false and "AQI":0 - and each COMPARED as better than any
     * letter threshold before the shape guard, which is what made them dangerous rather than
     * merely odd.
     */
    public static function unreadableQualityIndexProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::UNREADABLE_AQI_VALUES) as $token) {
            $cases['AQI is ' . $token] = [$token];
        }

        return $cases;
    }

    /**
     * THE OVER-REJECTION GUARD: a LEGITIMATE quality index must still PASS, and must still be
     * CACHED.
     *
     * Fail-closed is only correct if it rejects EXACTLY the unreadable values. Without this test
     * the guard at Helper/Validator.php:841-843 could be strengthened all the way to "reject
     * everything" - a whitelist of today's grade letters, empty(), a falsy test, is_numeric() -
     * with every other test in this class still green: the two fail-closed tests above would keep
     * passing, and so would every cache test, because a rejection is never cached anyway. What
     * such a change would break is real verdicts, in production, silently. This is the assertion
     * that fails first instead.
     *
     * Three cases, deliberately not one:
     *  - 'A' against a threshold of 'A': the EQUALITY case, and the shipped default
     *    (etc/config.xml:20 sets address_quality_index to 'A' and the field is not exposed in
     *    etc/adminhtml/system.xml, so this is what most merchants actually run);
     *  - 'B' against a looser 'C' threshold, so this test does not accidentally prove only that
     *    equality survives - a guard narrowed to "$qualityIndex === $threshold" would pass the
     *    case above and fail this one;
     *  - the STRING '0' against 'C', because the guard tests the value's SHAPE and NOT its
     *    truthiness ON PURPOSE. '0' is a non-empty string, so it is readable and passes, while
     *    empty() or a falsy test would reject it. This case exists to stop a future tidy-up from
     *    replacing "!is_string($x) || $x === ''" with empty(): the two look equivalent, empty()
     *    catches nothing extra (the empty string is already rejected) and it would start rejecting
     *    a real value. Contrast the INT 0, which IS rejected, in
     *    testAPresentButUnreadableQualityIndexFailsClosedAndIsNeverCached().
     *
     * @param string $threshold Configured address_quality_index the verdict is judged against.
     * @param string $qualityIndex AQI the response carries for the address.
     * @param string $why What this case protects, quoted into the failure message.
     */
    #[DataProvider('legitimateQualityIndexProvider')]
    public function testALegitimateQualityIndexStillPassesAndIsStillCached(
        string $threshold,
        string $qualityIndex,
        string $why
    ): void {
        $shopper = $this->createShopper(
            null,
            0,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => $threshold])),
            static fn ($payload): array => array_map(
                static fn (): array => [['AQI' => $qualityIndex, 'AVC' => self::PASSING_AVC]],
                (array)$payload['Addresses']
            )
        );
        $address = $this->distinctAddress(1);

        $first = $shopper['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            [0 => true],
            $first,
            sprintf(
                'A READABLE AQI that meets the configured threshold MUST still pass; this case pins that '
                . '%s. The fail-closed guard (Helper/Validator.php:841-843) rejects only values whose '
                . 'SHAPE is unreadable - no string, or the empty string - and widening it to empty(), to '
                . 'a falsy test, to a whitelist of the grade letters Loqate uses today or to is_numeric() '
                . 'would over-reject genuine verdicts and block valid addresses at admin order create and '
                . 'on every import row. If this fails, the guard has stopped being fail-closed and started '
                . 'being fail-shut, and no other test in this class would have noticed.',
                $why
            )
        );
        $this->assertCount(
            1,
            $this->batchStore($shopper),
            'A readable verdict is a REAL verdict, so it must still be cached. Fail-closed must not spread '
            . 'into "cache nothing": that would re-bill every address on every submission and undo '
            . 'LOQ-16976.'
        );

        $replayed = $shopper['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            [0 => true],
            $replayed,
            'And the cached pass must be replayable, which is the property the billing fix is made of.'
        );
        $this->assertSame(
            1,
            $this->shopperCallCount($shopper),
            'One billable request in total: the second submission must be answered entirely from the '
            . 'cache.'
        );
    }

    /**
     * Legitimate (threshold, AQI) pairs that must keep passing the fail-closed guard, with what
     * each one protects.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legitimateQualityIndexProvider(): array
    {
        return [
            "AQI 'A' at the shipped default threshold 'A'" => [
                'A',
                'A',
                'the strongest grade, judged against the threshold etc/config.xml:20 ships - the equality '
                . 'case, and the configuration most merchants run',
            ],
            "AQI 'B' under a looser 'C' threshold" => [
                'C',
                'B',
                'a passing grade that is NOT equal to the threshold, so the guard cannot be narrowed to an '
                . 'equality test and hide behind the default-threshold case',
            ],
            "the string '0' under a 'C' threshold" => [
                'C',
                '0',
                'a non-empty string is READABLE whatever it spells: the guard is on shape, not truthiness, '
                . 'so empty() or a falsy test would reject this legitimate value',
            ],
        ];
    }

    /**
     * REFRESHING an entry while the cache is FULL must not cost an unrelated address its
     * verdict. That is the whole job of the unset-then-append in storeBatchVerifyResult(), and
     * nothing else in the suite can see it: with the cache below its limit the unset is
     * invisible, because writing to an existing key overwrites it in place either way.
     *
     * At the limit it stops being invisible. Without the unset, the eviction loop sees a store
     * that already contains the key it is about to write, counts it, and array_shift()s the
     * OLDEST entry to make room the write did not need - so refreshing the NEWEST verdict
     * silently destroys the oldest one, and the cache ends up one entry short of the bound it
     * advertises.
     *
     * The refresh is reachable: it happens whenever a key is present but its payload is
     * unreadable (a half-migrated session, another module writing to the attribute), and
     * whenever the same address appears twice in one batch. This test uses the unreadable
     * payload, because that is the case that arrives with the cache already full after an
     * import.
     */
    public function testRefreshingAnEntryWhileTheCacheIsFullEvictsNoUnrelatedVerdict(): void
    {
        $limit = $this->batchCacheLimit();

        // Fill the cache to EXACTLY the limit, in one batch, so the entries are in send order:
        // $batch[0] is the oldest entry and $batch[$limit - 1] the newest.
        $batch = [];
        for ($i = 1; $i <= $limit; $i++) {
            $batch[] = $this->distinctAddress($i);
        }
        $this->validator->verifyMultipleAddresses($batch, false);

        $store = $this->batchStore($this->shopper);
        $this->assertCount($limit, $store, 'Fixture guard: the cache must start exactly full.');
        $keys = array_keys($store);

        // Corrupt the payload of the NEWEST entry, so re-verifying that one address misses the
        // cache and writes to a key that is already present - while the cache is full.
        $store[$keys[$limit - 1]] = '{not json';
        $this->sessionStore[self::BATCH_VERIFY_CACHE_SESSION_KEY] = $store;

        $this->validator->verifyMultipleAddresses([$batch[$limit - 1]], false);

        $this->assertSame(
            $limit + 1,
            $this->addressesBilled(),
            'Fixture guard: the corrupted entry must genuinely have missed, or the refresh under test '
            . 'never happened.'
        );
        $this->assertCount(
            $limit,
            $this->batchStore($this->shopper),
            'Refreshing an existing entry needs no room, so the cache must still hold exactly ' . $limit
            . ' verdicts. One fewer means the write evicted something it did not have to.'
        );

        // THE assertion: the entry at the far end of the FIFO queue - the one with nothing to do
        // with the refresh - must still be replayable.
        $replayed = $this->validator->verifyMultipleAddresses([$batch[0]], false);

        $this->assertSame(
            $limit + 1,
            $this->addressesBilled(),
            'The OLDEST verdict must survive a refresh of the newest one. Without the unset-then-append, '
            . 'the eviction loop counts the key it is about to overwrite as if it needed room and shifts '
            . 'this entry out - so a corrupted or repeated address quietly costs an unrelated, perfectly '
            . 'good verdict, and every such write shrinks the cache by one.'
        );
        $this->assertSame([0 => true], $replayed, 'And it must replay as the verdict it was, not as a miss.');
    }

    /**
     * The pre-existing captured-address bypass is free and must stay free: an address Loqate
     * itself authored through the Capture lookup is not sent to the billable endpoint at all,
     * and it writes nothing to the verdict cache because it never earned a verdict.
     */
    public function testACapturedAddressIsNotSentAndNotCached(): void
    {
        $this->sessionStore['captured_addresses'] = [self::capturedEntry(self::ADDRESS)];
        // The API would REJECT it, so a verdict of true can only come from the bypass.
        $this->apiVerdicts[self::ADDRESS['street'][0]] = 'fail';

        $result = $this->validator->verifyMultipleAddresses([self::ADDRESS]);

        $this->assertSame([0 => true], $result, 'A captured address must be accepted without verification.');
        $this->assertSame(0, $this->apiCallCount(), 'A captured address must not reach the billable API at all.');
        $this->assertSame(
            [],
            $this->batchStore($this->shopper),
            'The captured bypass returns before the verdict cache, so it must write nothing to it.'
        );
    }

    /**
     * With no API key configured the method reports 'noKeyFound' and touches neither the API
     * nor either cache. Callers must not merge this shape into row-indexed data - see
     * ValidateImportAddressTest - so it is pinned here at the source.
     */
    public function testNoApiKeyReportsNoKeyFoundRatherThanRowIndexedVerdicts(): void
    {
        $keyless = $this->createKeylessValidator();

        $result = $keyless->verifyMultipleAddresses([self::ADDRESS], false);

        $this->assertSame(
            ['noKeyFound' => true],
            $result,
            'With no API key there is nothing to validate, and that must be reported as its own shape '
            . 'rather than as a row-indexed verdict array.'
        );
    }

    /**
     * AQI values that are PRESENT in the response but carry no readable verdict, keyed by the
     * $apiVerdicts token that produces them.
     *
     * Every one of them compares as BETTER than any letter threshold under PHP 8's <= rules -
     * '' <= 'C' (string comparison), false <= 'C' ('C' is truthy) and 0 <= 'C' (the int is
     * cast to '0') are all true - so before the shape guard checkQualityIndex() answered "valid"
     * for each. That is exactly why the guard has to test whether the AQI is READABLE rather than
     * merely whether it is null: a "verdict" that is really a response the module could not read
     * must be rejected, and must not be stored and replayed for the rest of the session. See
     * testAPresentButUnreadableQualityIndexFailsClosedAndIsNeverCached().
     *
     * Note that the INT 0 belongs here while the STRING '0' does NOT: '0' is a readable
     * non-empty string and still passes - see
     * testALegitimateQualityIndexStillPassesAndIsStillCached().
     *
     * @var array<string, mixed>
     */
    private const UNREADABLE_AQI_VALUES = [
        'empty-aqi' => '',
        'false-aqi' => false,
        'zero-aqi' => 0,
    ];

    /**
     * Whole RESPONSE-ROW shapes that carry no readable AQI at all, keyed by the $apiVerdicts
     * token that produces them. Each value is one element of the list the CONNECTOR emits - that
     * is, one record's 'Matches' value after Verify::verifyAddress()'s
     * array_column($response, 'Matches') (vendor/lqt/api-connector/src/Client/Verify.php:50-52).
     *
     * ALL THREE SURVIVE that array_column() with the row count PRESERVED, verified on PHP 8.3: it
     * drops only records that lack the 'Matches' KEY, so a record whose 'Matches' is [] - or even
     * null - still contributes an element. The row-count guard (Helper/Validator.php:643-651)
     * therefore cannot see any of them; they reach the attribution loop
     * (Helper/Validator.php:703-711), where $addressResponse[0]['AQI'] ?? null is null for every
     * one, and only checkQualityIndex()'s shape guard (Helper/Validator.php:841-843) stands
     * between them and a PASS. See
     * testARowWithNoReadableQualityIndexFailsClosedAndIsNeverCached().
     *
     * @var array<string, mixed>
     */
    private const UNREADABLE_ROW_SHAPES = [
        // "Matches":[{}] - a match object with no AQI field in it.
        'match carrying no AQI' => [[]],
        // "Matches":[] - Loqate saying "no match for this address", the case where answering
        // "valid" is the maximally wrong answer.
        'Matches list that is empty' => [],
        // "Matches":null - a survivor of array_column() just like [] is, and the shape that was
        // missing from this taxonomy until the fail-closed guard was written.
        'Matches value that is null' => null,
    ];

    /**
     * What the API answers for one parsed address, in the shape
     * Validator::verifyMultipleAddresses() reads: $response[$n][0]['AQI'].
     *
     * The return type is deliberately left off rather than declared array: the connector's
     * array_column($response, 'Matches') emits whatever each record's 'Matches' held, so a row
     * can legitimately arrive as null or as [] - see self::UNREADABLE_ROW_SHAPES.
     *
     * @param array $address One entry of the payload's 'Addresses' list.
     * @return mixed One response row.
     */
    private function responseRowFor(array $address)
    {
        $verdict = $this->apiVerdicts[(string)($address['Address1'] ?? '')] ?? 'pass';

        if (array_key_exists($verdict, self::UNREADABLE_ROW_SHAPES)) {
            // A row with no readable AQI in it at all, so the '??' in the production code
            // supplies null. Kept distinct from the UNREADABLE_AQI_VALUES below because the two
            // reach the production guard by different routes: a missing value versus a value
            // that is really there.
            return self::UNREADABLE_ROW_SHAPES[$verdict];
        }

        if (array_key_exists($verdict, self::UNREADABLE_AQI_VALUES)) {
            // A row whose AQI is present but is not a usable quality index. Kept distinct from
            // 'unreadable' above because the two reach the production guard by different
            // routes: '??' on a missing key versus a value that is really there.
            return [[
                'AQI' => self::UNREADABLE_AQI_VALUES[$verdict],
                'AVC' => self::PASSING_AVC,
            ]];
        }

        return [[
            'AQI' => $verdict === 'fail' ? self::FAILING_AQI : self::PASSING_AQI,
            'AVC' => self::PASSING_AVC,
        ]];
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

    /**
     * A captured-address store entry for a Magento-shaped address, as
     * Helper\Controller::storeCapturedAddress() writes it: the ADDRESS_CAPTURE_MAPPING keys,
     * serialised.
     *
     * @param array $address Magento-shaped address.
     * @return string
     */
    private static function capturedEntry(array $address): string
    {
        return (string)json_encode([
            'Address1' => $address['street'][0] ?? '',
            'Address2' => $address['street'][1] ?? '',
            'Address3' => $address['city'] ?? '',
            'Address4' => $address['region'] ?? '',
            'PostalCode' => $address['postcode'] ?? '',
            'Country' => $address['country_id'] ?? '',
        ]);
    }

    /**
     * Store configuration for these tests: the AQI threshold every batch verdict is judged
     * against, plus whatever else the test cares about.
     *
     * @param array<string, string> $overrides Config path => value.
     * @return array<string, string>
     */
    private static function configWith(array $overrides): array
    {
        return array_merge([self::AQI_CONFIG_PATH => self::AQI_THRESHOLD], $overrides);
    }

    /** Number of billable Loqate Cleansing requests issued by the primary session. */
    private function apiCallCount(): int
    {
        return count($this->apiRequests);
    }

    /** apiCallCount() for a specific shopper built by createShopper(). */
    private function shopperCallCount(array $shopper): int
    {
        return count($shopper['requests']);
    }

    /**
     * Number of ADDRESSES the primary session put on the invoice: the Cleansing API is
     * billed per address, not per request, so this - and not the request count - is what the
     * de-duplication has to reduce.
     */
    private function addressesBilled(): int
    {
        $billed = 0;
        foreach ($this->apiRequests as $payload) {
            $billed += count((array)($payload['Addresses'] ?? []));
        }

        return $billed;
    }

    /** The BATCH verdict cache as currently held in a shopper's session. */
    private function batchStore(array $shopper): array
    {
        $store = $shopper['session'][self::BATCH_VERIFY_CACHE_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    /** The SINGLE-address verdict cache as currently held in a shopper's session. */
    private function singleStore(array $shopper): array
    {
        $store = $shopper['session'][self::VERIFY_CACHE_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    private function batchCacheLimit(): int
    {
        if (!defined(Validator::class . '::BATCH_VERIFY_CACHE_LIMIT')) {
            $this->fail(
                'Validator::BATCH_VERIFY_CACHE_LIMIT is not defined: the batch verdict cache must be '
                . 'bounded, or an import can inflate the customer session without limit.'
            );
        }

        $limit = (int)constant(Validator::class . '::BATCH_VERIFY_CACHE_LIMIT');
        $this->assertGreaterThanOrEqual(
            100,
            $limit,
            'The batch cache must hold at least one full import chunk (Plugin\Admin\ValidateImportAddress '
            . 'verifies in chunks of 100), or eviction discards a chunk\'s earliest rows before the chunk '
            . 'has finished and a re-run re-bills them.'
        );

        return $limit;
    }

    /** A Validator with no API key configured, so every method short-circuits. */
    private function createKeylessValidator(): Validator
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturn('');
        $helper->method('getCurrentStore')->willReturn(0);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn ($value) => json_encode($value));
        $serializer->method('unserialize')->willReturnCallback(static fn ($value) => json_decode($value, true));

        return new Validator(
            $this->createMock(Logger::class),
            $this->createMock(Session::class),
            $this->createMock(RegionFactory::class),
            $this->createMock(ModuleListInterface::class),
            $helper,
            $serializer
        );
    }

    /** The AQI-threshold fingerprint segment of a namespaced cache key. */
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
     * The ONE line shape the batch cache instrumentation may write: an outcome, the (single)
     * key family and a 12-hex hash - or "unkeyed". Anything else - an address, a signature, a
     * store id - fails to match, which is what makes this a whitelist rather than a guess at
     * what a leak would look like.
     */
    private const BATCH_CACHE_LOG_PATTERN =
        '/^Loqate batch verify cache (hit|miss) \[family=strict, key=([0-9a-f]{12}|unkeyed)\]$/';

    /**
     * The batch cache-outcome debug records of a shopper, parsed, asserting on the way that
     * each one matches the whitelisted shape and carries no log context.
     *
     * @param array $shopper Shopper harness from createShopper().
     * @return array<int, array{outcome: string, token: string}>
     */
    private function batchCacheLogRecords(array $shopper): array
    {
        $parsed = [];
        foreach ($this->logRecords($shopper, 'debug') as $index => $record) {
            $this->assertMatchesRegularExpression(
                self::BATCH_CACHE_LOG_PATTERN,
                $record['message'],
                sprintf(
                    'Debug record #%d ("%s") is not one of the permitted batch cache-outcome lines. These '
                    . 'records may contain the outcome, the key family and a truncated hash only - never '
                    . 'the address and never the signature.',
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

            preg_match(self::BATCH_CACHE_LOG_PATTERN, $record['message'], $matches);
            $parsed[] = ['outcome' => $matches[1], 'token' => $matches[2]];
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
        return array_values(array_filter(
            iterator_to_array($shopper['events']),
            static fn (array $event): bool => $event['type'] !== 'api'
                && ($level === null || $event['type'] === $level)
        ));
    }

    /**
     * The ordered timeline of what the Validator emitted, as compact tokens: 'log:hit',
     * 'log:miss' and 'api' for a billable request. Lets the tests assert that the misses are
     * logged BEFORE the request they account for, which is what makes the log usable to
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
}
