<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin\Admin;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Plugin\Admin\ValidateImportAddress;
use Loqate\ApiIntegration\Test\Support\ProductionSerializerDouble;
use Magento\Customer\Model\Session;
use Magento\CustomerImportExport\Model\Import\Address;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregator;
use ArrayIterator;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * THE ROW-ATTRIBUTION GUARANTEE of the customer import, asserted over the REAL Validator
 * (LOQ-17148).
 *
 * NO ROW'S VERDICT IS EVER ATTRIBUTED TO ANOTHER ROW. That is the guarantee a merchant reads
 * when the import report says "Invalid address at row #101": row 101 of their file, and no
 * other. Getting it wrong is worse than getting the billing wrong, and worse than blocking the
 * import: the merchant is sent to edit a row that is perfectly valid while the genuinely bad
 * one imports unnoticed, and the report gives them no reason to doubt it.
 *
 * WHY THIS FILE EXISTS ALONGSIDE ValidateImportAddressTest. That class stubs the Validator out,
 * which is right for what it asserts - the plugin's handling of the three return shapes - but
 * it means the row numbers it pins are computed from verdict arrays the test itself wrote. The
 * risk LOQ-17148 introduces lives in the seam BETWEEN the two units: verifyMultipleAddresses()
 * pre-seeds one result slot per input row and filters out any slot still holding null, and
 * afterValidateData() array_merge()s the per-chunk arrays and derives the row number from the
 * merged offset. array_merge() renumbers integer keys BY INSERTION ORDER, so ONE dropped slot
 * silently shifts every row number after it - across chunk boundaries included. Adding a new
 * way to skip a row (a verdict remembered from earlier in the run) is exactly the kind of
 * change that can leave a slot unfilled, and no test that stubs either side can see it. So this
 * one wires the real plugin to the real Validator and reads the row numbers out of the
 * merchant's own error report.
 *
 * The file under test mixes all four kinds of row that the run-scoped memory has to tell apart,
 * and places them either side of the 100-row chunk boundary the plugin cuts at:
 *  - rows Loqate PASSES, one of which repeats in a later chunk (a remembered pass);
 *  - rows Loqate REJECTS on a readable AQI, repeating in a later chunk (a remembered
 *    rejection - the case LOQ-17148 adds, and the one that must not cost a row its slot);
 *  - rows whose AQI could NOT BE READ, repeating in a later chunk (never remembered, so
 *    verified again and rejected again - a fault report is not a verdict);
 *  - filler rows, distinct and passing, so the boundary sits where the plugin's arithmetic puts
 *    it rather than at the end of a short fixture.
 *
 * The billed count is asserted in the same test on purpose. Row numbers alone would still be
 * right if nothing were de-duplicated at all, so without it this file would pass against the
 * unfixed code and stop being evidence of anything.
 */
class ValidateImportAddressRowAttributionTest extends TestCase
{
    /**
     * The serializer double, shared with every other harness that reads a serialised payload
     * back - see the trait. This class is the one that most needs it: it is the only end-to-end
     * harness in the suite, so a lenient double would leave the entire import path's recovery
     * from an unreadable session payload untested anywhere.
     */
    use ProductionSerializerDouble;

    /** Any non-empty key makes the import path reach the billable call. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /** Config path gating the import verification feature. */
    private const IMPORT_ENABLED = 'loqate_settings/address_settings/enable_customer_import';

    /** Config path of the threshold batch verdicts are judged against. */
    private const AQI_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /** Threshold the fixture's grades are judged against: 'A' passes it, 'E' does not. */
    private const AQI_THRESHOLD = 'C';

    /** AQI better than the threshold => the row passes. */
    private const PASSING_AQI = 'A';

    /** AQI poorer than the threshold => the row is rejected on a READABLE verdict. */
    private const FAILING_AQI = 'E';

    /** Rows in the import file: two chunks of the plugin's 100, 100 + 50. */
    private const FILE_ROWS = 150;

    /** Rows per verification batch, as ValidateImportAddress::afterValidateData() chunks them. */
    private const CHUNK_SIZE = 100;

    /**
     * The rows that are NOT filler, as file offset => [address id, what Loqate answers].
     *
     * Placed so that every kind of row appears on both sides of the chunk boundary at offset
     * 99/100, which is where an off-by-one in the merge hides:
     *  - id 1 passes at the file's FIRST row and repeats at its LAST;
     *  - id 2 is rejected at offset 5 and repeats TWICE in chunk 2 (offsets 100 and 104), the
     *    second of those being a repeat of a row already repeated - a remembered verdict served
     *    twice from one chunk;
     *  - id 4 is rejected at the LAST row of chunk 1 and repeats at the SECOND row of chunk 2,
     *    the adjacent pair an off-by-one collapses onto one number;
     *  - id 3's AQI cannot be read, and repeats in chunk 2, where it must be asked about again;
     *  - id 5's AQI is present but unreadable, and appears in chunk 2 only, so a fault that
     *    first occurs AFTER rows have already been remembered is covered too.
     *
     * @var array<int, array{0: int, 1: string}>
     */
    private const PLANNED_ROWS = [
        0 => [1, 'pass'],
        5 => [2, 'fail'],
        50 => [3, 'no-readable-aqi'],
        99 => [4, 'fail'],
        100 => [2, 'fail'],
        101 => [4, 'fail'],
        102 => [3, 'no-readable-aqi'],
        103 => [5, 'unreadable-aqi'],
        104 => [2, 'fail'],
        149 => [1, 'pass'],
    ];

    /**
     * The 1-based file row numbers the merchant must be shown, and nothing else.
     *
     * Written out rather than derived from self::PLANNED_ROWS, because a derivation would share
     * its arithmetic with the code under test's and the two would be wrong together. Every
     * number here is "the file position of a row Loqate did not pass", counted by hand:
     * offsets 5, 50 and 99 in chunk 1 and offsets 100 to 104 in chunk 2.
     *
     * @var int[]
     */
    private const EXPECTED_INVALID_ROW_NUMBERS = [6, 51, 100, 101, 102, 103, 104, 105];

    /**
     * Addresses distinct enough to be told apart, of which one - the row whose AQI cannot be
     * read - has to be asked about a second time because a fault report is never remembered.
     */
    private const EXPECTED_DISTINCT_ADDRESSES = 145;

    /** @var ArrayObject Payloads of every connector call, in order. */
    private $apiRequests;

    /** @var ArrayObject Live store configuration, config path => value. */
    private $config;

    /**
     * THE test: an import file whose rows repeat across the chunk boundary, mixing passes,
     * readable rejections, unreadable answers and de-duplicated rows, must report EXACTLY the
     * right row numbers - and must bill each distinct address once.
     *
     * Both halves in one test deliberately. They are the two failure modes of the same change
     * and they trade off against each other: skip too little and the merchant is over-billed,
     * skip a row's SLOT rather than only its billable call and every row number after it is
     * wrong. A test that asserted either alone would be satisfied by the bug the other one
     * catches.
     */
    public function testEveryInvalidRowIsReportedUnderItsOwnFileRowNumberWhileEachAddressIsBilledOnce(): void
    {
        $rows = $this->importFileRows();

        $report = $this->runImport($rows);

        $this->assertSame(
            self::EXPECTED_INVALID_ROW_NUMBERS,
            array_column($report->recordedErrors, 'rowNumber'),
            'Every invalid row must be reported under ITS OWN 1-based file position. The merged verdicts '
            . 'are renumbered by insertion order, so a row that loses its slot - which is what happens if a '
            . 'de-duplicated or skipped row is ever left holding null and filtered out of '
            . 'verifyMultipleAddresses()\' result - shifts every row number after it. The merchant is then '
            . 'sent to edit a VALID row while the bad one imports unnoticed, and nothing in the report '
            . 'suggests otherwise. Note rows 100 and 101 are the last row of chunk 1 and the first of '
            . 'chunk 2: the pair an off-by-one anywhere in the chunking, the merge or the skip collapses.'
        );
        $this->assertSame(
            self::EXPECTED_DISTINCT_ADDRESSES + 1,
            $this->addressesBilled(),
            sprintf(
                'The file holds %d distinct addresses, and exactly ONE of them - the row whose AQI could '
                . 'not be read - may be sent twice, because a fault report is never remembered and its '
                . 'repeat has to be asked about again. Anything more means a rejected row was re-billed in '
                . 'a later chunk, which is the defect LOQ-17148 fixes; anything less means a row was '
                . 'skipped that nobody had a verdict for.',
                self::EXPECTED_DISTINCT_ADDRESSES
            )
        );

        $this->assertSame(
            array_map(
                static fn (int $row): string => 'Invalid address at row #' . $row,
                self::EXPECTED_INVALID_ROW_NUMBERS
            ),
            array_map(
                static fn (array $error): string => (string)$error['errorMessage'],
                $report->recordedErrors
            ),
            'The message the merchant reads must name the same row as the machine-readable row number.'
        );
        foreach ($report->recordedErrors as $error) {
            $this->assertSame(
                ProcessingError::ERROR_LEVEL_CRITICAL,
                $error['errorLevel'],
                'An invalid address must block the import rather than merely annotate it, whether its '
                . 'verdict was earned in this chunk or remembered from an earlier one.'
            );
        }

        // PRECONDITION 1, READ OFF THE WIRE: the PLUGIN really did cut this file at row 100.
        //
        // This is the load-bearing premise of everything above - the docblock's whole subject is
        // the boundary at offset 99/100 - and it can only be established from the connector
        // payloads, because that is the only place the plugin's chunking is observable. An
        // earlier revision asserted it from array_chunk($rows, self::CHUNK_SIZE): the test
        // chunking its OWN array by its OWN constant, which cannot fail and says nothing about
        // the plugin. If the plugin chunked at 50 that assertion stayed green while every
        // sentence around it was false.
        $this->assertCount(
            2,
            $this->apiRequests,
            'A 150-row file must reach the connector as exactly TWO batches. One means the plugin stopped '
            . 'chunking and there is no boundary in this fixture at all; three or more means it chunks '
            . 'smaller than 100 and the boundary is not where every row number above was counted from.'
        );
        $this->assertSame(
            array_map(
                static fn (array $row): string => (string)$row['street'][0],
                array_slice($rows, 0, self::CHUNK_SIZE)
            ),
            array_column($this->apiRequests[0]['Addresses'], 'Address1'),
            'The FIRST batch must be exactly the file\'s first 100 rows, in file order. Chunk 1 repeats '
            . 'nothing within itself and nothing is remembered before it, so every one of its rows is sent '
            . '- which makes the first payload a faithful picture of where the plugin cut, and of the '
            . 'order it sends in, which the positional attribution depends on.'
        );
        $this->assertSame(
            [],
            array_values(array_diff(
                array_column($this->apiRequests[1]['Addresses'], 'Address1'),
                array_map(
                    static fn (array $row): string => (string)$row['street'][0],
                    array_slice($rows, self::CHUNK_SIZE)
                )
            )),
            'And the SECOND batch may carry nothing but rows from beyond the boundary. Together with the '
            . 'assertion above that fixes the cut at offset 99/100 from the wire alone: a plugin that '
            . 'chunked anywhere else would put a row on the wrong side of it.'
        );

        // PRECONDITION 2, a FIXTURE SELF-CHECK and not a wire reading: the rows the row numbers
        // above depend on really are repeats of one another. It derives from self::PLANNED_ROWS,
        // so what it can catch is a plan edited into one that no longer repeats anything across
        // the boundary - after which the billed count would be met by a file with nothing to
        // de-duplicate. It is deliberately NOT read off the wire, because most of these rows are
        // not on the wire: being absent from it is the very thing being measured.
        //
        // Offset 103 is excluded on purpose. It is id 5, the row whose AQI is present but
        // unreadable, and it appears in chunk 2 ONLY - it exists to cover a fault that first
        // occurs after other rows have already been remembered, not to be a cross-boundary
        // repeat. Including it would add a fifth street and assert the opposite of what this
        // check is for.
        $this->assertSame(
            ['1 Test Street', '2 Test Street', '3 Test Street', '4 Test Street'],
            array_values(array_unique(array_map(
                static fn (int $offset): string => (string)$rows[$offset]['street'][0],
                [0, 5, 50, 99, 100, 101, 102, 104, 149]
            ))),
            'Nine planned offsets either side of the boundary must resolve to only FOUR distinct '
            . 'addresses, or nothing is being de-duplicated and the billed count proves nothing.'
        );
    }

    /**
     * The import file: the planned rows at their planned offsets, everything else a distinct
     * passing row so the chunk boundary falls where the plugin's arithmetic puts it.
     *
     * @return array<int, array> Magento-shaped import rows, in file order.
     */
    private function importFileRows(): array
    {
        $rows = [];
        for ($offset = 0; $offset < self::FILE_ROWS; $offset++) {
            $id = self::PLANNED_ROWS[$offset][0] ?? (1000 + $offset);
            $rows[$offset] = $this->addressForId($id);
        }

        return $rows;
    }

    /**
     * What Loqate answers for the address with the given id: whatever the plan says the first
     * time that id appears, and the same thing every later time - the API's opinion of an
     * address does not change mid-file.
     *
     * @param int $id Address id.
     * @return string 'pass', 'fail', 'no-readable-aqi' or 'unreadable-aqi'.
     */
    private function apiVerdictForId(int $id): string
    {
        foreach (self::PLANNED_ROWS as $planned) {
            if ($planned[0] === $id) {
                return $planned[1];
            }
        }

        return 'pass';
    }

    /** A distinct, fully-formed import row per address id. */
    private function addressForId(int $id): array
    {
        return [
            'street' => [$id . ' Test Street'],
            'city' => 'London',
            'postcode' => 'SW1A ' . $id . 'AA',
            'country_id' => 'GB',
        ];
    }

    /**
     * Run the REAL plugin, wired to the REAL Validator, over an import file - one admin request,
     * so all of its chunks share one Validator exactly as a real import does.
     *
     * @param array<int, array> $rows Import rows, in file order.
     * @return ProcessingErrorAggregator&object{recordedErrors: array} The merchant's report.
     */
    private function runImport(array $rows)
    {
        $this->apiRequests = new ArrayObject();
        $this->config = new ArrayObject([
            'loqate_settings/settings/api_key' => self::API_KEY,
            self::IMPORT_ENABLED => '1',
            self::AQI_CONFIG_PATH => self::AQI_THRESHOLD,
        ]);

        $subject = $this->createMock(Address::class);
        $subject->method('getBehavior')->willReturn(Import::BEHAVIOR_ADD_UPDATE);
        $subject->method('getSource')->willReturn(new ArrayIterator($rows));

        $aggregator = self::errorAggregator();
        $result = $this->createPlugin()->afterValidateData($subject, $aggregator);

        $this->assertSame(
            $aggregator,
            $result,
            'afterValidateData() must return the aggregator it was given, whatever happened: it is an '
            . 'after-plugin, and its return value replaces validateData()\'s.'
        );

        return $result;
    }

    /**
     * The plugin under test, built WITHOUT its constructor and holding a REAL Validator.
     *
     * AbstractPlugin's constructor wants three framework collaborators this plugin never reads
     * and this bootstrap does not have, so the two it does read are injected into their declared
     * properties instead - the same approach ValidateImportAddressTest takes. The difference
     * that matters is the Validator: a real one, so the chunk loop's shared instance is a real
     * request lifetime and the result shape crossing the seam is the real one.
     *
     * @return ValidateImportAddress
     */
    private function createPlugin(): ValidateImportAddress
    {
        $config = $this->config;
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static fn ($configPath) => $config->offsetExists($configPath) ? $config[$configPath] : ''
        );
        $helper->method('getCurrentStore')->willReturn(0);

        $plugin = (new ReflectionClass(ValidateImportAddress::class))->newInstanceWithoutConstructor();
        foreach (['validator' => $this->createValidator($helper), 'helper' => $helper] as $property => $value) {
            $reflection = new ReflectionProperty(ValidateImportAddress::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue($plugin, $value);
        }

        return $plugin;
    }

    /**
     * A real Validator with a live session store and a mocked billable connector, sharing the
     * plugin's configuration helper because in production they read the same configuration.
     *
     * @param Data $helper Configuration helper, shared with the plugin.
     * @return Validator
     */
    private function createValidator(Data $helper): Validator
    {
        $sessionStore = new ArrayObject();

        // The shared Test/stubs Session is a no-op, so nothing would ever be remembered between
        // chunks and the de-duplication under test could not be observed at all. getData() and
        // setData() have to be *added* when the real Magento\Customer\Model\Session is present,
        // because it does not declare them; the stub does, and PHPUnit refuses to "add" an
        // existing method - hence the method_exists() filter.
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
            static fn ($key = '', $clear = false) => $sessionStore[$key] ?? null
        );
        $sessionMock->method('setData')->willReturnCallback(
            static function ($key, $value = null) use ($sessionStore, $sessionMock) {
                $sessionStore[$key] = $value;

                return $sessionMock;
            }
        );

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

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '9.9.9']);

        // Fails the way the PRODUCTION serializer fails. This harness is the only one in the
        // suite driving the real plugin over the real Validator over a real session store end to
        // end, so a lenient double here would be the single place where the whole import path
        // could meet an unreadable session payload and look safe.
        $serializer = $this->createSerializerDouble();

        $validator = new Validator(
            $this->createMock(Logger::class),
            $sessionMock,
            $regionFactory,
            $moduleList,
            $helper,
            $serializer
        );

        // The connector is built inside the constructor (new Verify($apiKey)), so the only way to
        // intercept the billable call is to swap the private property afterwards.
        $requests = $this->apiRequests;
        $connector = $this->createMock(Verify::class);
        $connector->method('verifyAddress')->willReturnCallback(
            function ($payload) use ($requests) {
                $requests[] = $payload;

                // One row per address SENT, in the order they were sent: that positional
                // correspondence is what verifyMultipleAddresses() attributes verdicts by, and it
                // is how the Cleansing API answers a batch. Getting this wrong here would make
                // the row-count guard fail the batch instead of exercising the attribution.
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

        return $validator;
    }

    /**
     * One response row for a parsed address, in the shape verifyMultipleAddresses() reads:
     * $response[$n][0]['AQI'].
     *
     * The two unreadable shapes are the two routes to "no readable AQI" and both survive the
     * connector's array_column($response, 'Matches') with the row count preserved, so neither is
     * caught by the row-count guard: "Matches":[] is Loqate saying "no match for this address",
     * and "AQI":"" is a value that is really there but is not a grade.
     *
     * The return type is deliberately left off rather than declared array: a row can legitimately
     * arrive as [].
     *
     * @param array $address One entry of the payload's 'Addresses' list.
     * @return mixed One response row.
     */
    private function responseRowFor(array $address)
    {
        $street = (string)($address['Address1'] ?? '');
        $id = (int)strtok($street, ' ');

        switch ($this->apiVerdictForId($id)) {
            case 'no-readable-aqi':
                return [];
            case 'unreadable-aqi':
                return [['AQI' => '']];
            case 'fail':
                return [['AQI' => self::FAILING_AQI]];
            default:
                return [['AQI' => self::PASSING_AQI]];
        }
    }

    /**
     * Number of ADDRESSES the run put on the invoice: the Cleansing API is billed per address,
     * not per request.
     *
     * @return int
     */
    private function addressesBilled(): int
    {
        $billed = 0;
        foreach ($this->apiRequests as $payload) {
            $billed += count((array)($payload['Addresses'] ?? []));
        }

        return $billed;
    }

    /**
     * A ProcessingErrorAggregator that records what was reported to it, in order, keeping the
     * real parameter names so a failure reads like the merchant's import report.
     *
     * @return ProcessingErrorAggregator&object{recordedErrors: array}
     */
    private static function errorAggregator()
    {
        return new class extends ProcessingErrorAggregator {
            /** @var array<int, array> */
            public $recordedErrors = [];

            public function addError(
                $errorCode,
                $errorLevel = ProcessingError::ERROR_LEVEL_CRITICAL,
                $rowNumber = null,
                $columnName = null,
                $errorMessage = null,
                $errorDescription = null
            ) {
                $this->recordedErrors[] = [
                    'errorCode' => $errorCode,
                    'errorLevel' => $errorLevel,
                    'rowNumber' => $rowNumber,
                    'columnName' => $columnName,
                    'errorMessage' => $errorMessage,
                    'errorDescription' => $errorDescription,
                ];

                return $this;
            }
        };
    }
}
