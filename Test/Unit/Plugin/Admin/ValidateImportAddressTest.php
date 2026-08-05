<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin\Admin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Plugin\Admin\ValidateImportAddress;
use Magento\CustomerImportExport\Model\Import\Address;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregator;
use ArrayIterator;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for Plugin\Admin\ValidateImportAddress, which had NO coverage at all.
 *
 * The plugin verifies a customer-address import file in chunks of 100 rows and reports each
 * invalid row back to the merchant by ROW NUMBER. Validator::verifyMultipleAddresses() has
 * THREE return shapes, and the plugin used to hand all three straight to array_merge():
 *  - array<int, bool>, keys 0..N-1 per chunk - the normal case;
 *  - false, when the billable API call itself failed OR answered a row count that cannot be
 *    attributed to the addresses sent (the Validator's row-count guard - see
 *    ValidatorBatchVerifyCacheTest - which is why the false branch now carries strictly more
 *    traffic than "the API was down");
 *  - ['noKeyFound' => true], when no API key is configured.
 * array_merge($allRowsResult, false) is a TypeError in PHP 8, and the plugin's own
 * catch (\Exception) does NOT catch it - a TypeError is an \Error, not an \Exception - so a
 * single failed Loqate request part-way through an import took the whole admin request down
 * with a 500 instead of degrading. That is what testAFailedBatchIsReportedOnceAndDoesNotCrash()
 * pins, and it is why the test asserts the plugin RETURNS rather than merely "does not error":
 * a test that only checked the reported errors would still have crashed before it got there.
 *
 * The 'noKeyFound' shape merges without throwing, which makes it the more insidious of the
 * two: a string key lands in row-indexed data, and every subsequent chunk is reported against
 * a row numbering that has an extra entry in it - see
 * testNoKeyFoundIsNeverMergedIntoRowIndexedData().
 *
 * The row arithmetic itself is the third thing pinned here. It is CORRECT as written and must
 * not be "fixed": each chunk comes back keyed 0..N-1 in input order (a guarantee
 * Validator::verifyMultipleAddresses() upholds by pre-seeding its result - see
 * ValidatorBatchVerifyCacheTest), so array_merge() renumbering by insertion order yields
 * 0..149 across chunks of 100 and ($index + 1) is the import row number by construction.
 * Nothing else in the suite pins that arithmetic across a chunk boundary, which is exactly
 * where an off-by-one hides - see testInvalidRowsAreReportedWithCorrectRowNumbersAcrossChunks().
 */
class ValidateImportAddressTest extends TestCase
{
    /** Config path gating this feature. */
    private const IMPORT_ENABLED = 'loqate_settings/address_settings/enable_customer_import';

    /** Rows per verification batch, as the plugin chunks them. */
    private const CHUNK_SIZE = 100;

    /** @var ArrayObject Live store configuration, config path => value. */
    private $config;

    /** @var ArrayObject Batches handed to Validator::verifyMultipleAddresses(), in order. */
    private $batchCalls;

    /**
     * Queued Validator::verifyMultipleAddresses() return values, one per call. A call past
     * the end of the queue fails the test rather than reusing the last entry, because "how
     * many chunks were verified" is itself part of what several of these tests assert.
     *
     * @var array<int, mixed>
     */
    private $batchResults = [];

    protected function setUp(): void
    {
        $this->config = new ArrayObject([
            'loqate_settings/settings/api_key' => 'TEST-API-KEY-0000',
            self::IMPORT_ENABLED => '1',
        ]);
        $this->batchCalls = new ArrayObject();
        $this->batchResults = [];
    }

    /**
     * THE crash (task 4): a billable call that fails part-way through an import must degrade,
     * not take the admin request down.
     *
     * Asserted on three things, each of which the pre-fix code got wrong:
     *  - the plugin RETURNS the error aggregator. Before, array_merge($allRowsResult, false)
     *    raised a TypeError, which catch (\Exception) does not catch, so the import died with
     *    a 500 - this test errors outright if that comes back;
     *  - exactly ONE error is reported, at CRITICAL, with NO row number, because no row is at
     *    fault and the merchant must not be sent looking for one;
     *  - verification STOPS. Continuing would either pass the remaining rows unverified or
     *    attribute a transport failure to individual rows, and the failure has already been
     *    reported.
     */
    public function testAFailedBatchIsReportedOnceAndDoesNotCrash(): void
    {
        // 250 rows = three chunks, so the failure genuinely happens MID-import and there is a
        // third chunk left that must not be verified.
        $this->batchResults = [
            self::allValid(self::CHUNK_SIZE),
            false,
            self::allValid(50),
        ];

        $result = $this->runPlugin(250);

        $this->assertInstanceOf(
            ProcessingErrorAggregator::class,
            $result,
            'The plugin must return the aggregator it was given. Before LOQ-16976 this line was never '
            . 'reached: array_merge($allRowsResult, false) raised a TypeError, which the plugin\'s own '
            . 'catch (\Exception) does not catch, so a mid-import Loqate failure was a 500.'
        );
        $this->assertCount(
            1,
            $result->recordedErrors,
            'A failed batch must be reported ONCE, not once per row: the merchant needs one actionable '
            . '"try again", not a hundred identical ones.'
        );

        $error = $result->recordedErrors[0];
        $this->assertSame(
            AbstractEntity::ERROR_CODE_SYSTEM_EXCEPTION,
            $error['errorCode'],
            'A Loqate outage is a system fault, not a data fault in the file.'
        );
        $this->assertSame(
            ProcessingError::ERROR_LEVEL_CRITICAL,
            $error['errorLevel'],
            'The error must be CRITICAL so the import is refused: letting the file through would import '
            . 'addresses that were never verified, which is the same fail-closed stance the module takes '
            . 'in admin order create and at checkout.'
        );
        $this->assertNull(
            $error['rowNumber'],
            'No row is at fault, so no row number may be reported: a row number here sends the merchant '
            . 'to edit a row that is probably perfectly valid.'
        );
        $this->assertNull($error['columnName'], 'No column is at fault either.');
        $this->assertStringContainsString(
            'could not be validated',
            (string)$error['errorMessage'],
            'The message must say the addresses could not be validated...'
        );
        $this->assertStringContainsString(
            'try the import again',
            (string)$error['errorMessage'],
            '...and tell the merchant what to do about it.'
        );

        $this->assertSame(
            2,
            count($this->batchCalls),
            'Verification must STOP at the failed chunk: the remaining rows cannot be verified any more '
            . 'reliably, and every further chunk is another billable request against an API that has '
            . 'just failed.'
        );
    }

    /**
     * The FIRST chunk failing is the same contract, and worth its own case because it is the
     * only path on which $allRowsResult is still empty - the shape where a merge of false
     * would have been most tempting to consider harmless.
     */
    public function testAFailedFirstBatchIsReportedWithoutVerifyingAnyFurtherChunk(): void
    {
        $this->batchResults = [false, self::allValid(50)];

        $result = $this->runPlugin(150);

        $this->assertCount(1, $result->recordedErrors, 'Exactly one error, whichever chunk failed.');
        $this->assertNull($result->recordedErrors[0]['rowNumber'], 'Still no row number.');
        $this->assertSame(1, count($this->batchCalls), 'The second chunk must not be verified.');
    }

    /**
     * The 'noKeyFound' shape must never reach the row-indexed data.
     *
     * It merges without throwing, which is what makes it worse than the false case rather than
     * better: 'noKeyFound' becomes a string key inside an array whose keys are row offsets, so
     * every reported row number afterwards is computed from a numbering with a phantom entry
     * in it - and if the entry were ever falsy, ($index + 1) of the string key would itself be
     * reported as "Invalid address at row #1", a row the merchant cannot fix because it is not
     * the row that is wrong.
     *
     * There is nothing to validate without a key, so the import must be left exactly as it was
     * found: no errors, and no further chunk verified. The queued second chunk - which would
     * report a row - is what makes this test fail if the guard is removed.
     */
    public function testNoKeyFoundIsNeverMergedIntoRowIndexedData(): void
    {
        $this->batchResults = [
            ['noKeyFound' => true],
            // Would be reported as "Invalid address at row #1" once merged behind the phantom
            // key, even though it is really row #101 of the file.
            [0 => false] + self::allValid(self::CHUNK_SIZE),
        ];

        $result = $this->runPlugin(200);

        $this->assertSame(
            [],
            $result->recordedErrors,
            'With no API key there is nothing to validate, so the import must be left exactly as it was '
            . 'found. A "noKeyFound" entry must never be merged into row-indexed data: it is not a row, '
            . 'and it shifts the numbering of every row reported after it.'
        );
        $this->assertSame(
            1,
            count($this->batchCalls),
            'Verification must stop: there is no key, so no further chunk can be verified either.'
        );
    }

    /**
     * ...and the half of that guard the ORDER of the chunks decides: an invalid row found
     * BEFORE the key disappeared must still be reported.
     *
     * testNoKeyFoundIsNeverMergedIntoRowIndexedData() passes with either `break` or
     * `return $result` in this branch, because there the 'noKeyFound' chunk is the FIRST one and
     * $allRowsResult is still empty - so it pins the "never merged" half and nothing else. This
     * case is the one that distinguishes them: chunk 1 reports a genuinely invalid row, chunk 2
     * comes back 'noKeyFound' (the API key removed mid-import, a config cache flushed under a
     * long-running import), and returning there would throw $allRowsResult away and import the
     * bad row silently. Breaking keeps the verdicts already earned and lets the reporting loop
     * run on them, which is strictly better than nothing and is the whole reason the branch is a
     * `break`.
     */
    public function testAnInvalidRowFoundBeforeTheKeyDisappearedIsStillReported(): void
    {
        $this->batchResults = [
            // Row 7 of the file is genuinely invalid, and Loqate said so before the key went.
            self::invalidAt(self::CHUNK_SIZE, [7]),
            ['noKeyFound' => true],
        ];

        $result = $this->runPlugin(200);

        $this->assertSame(
            [8],
            array_column($result->recordedErrors, 'rowNumber'),
            'The invalid row found in chunk 1 must still be reported after chunk 2 comes back with no key. '
            . 'Returning instead of breaking discards every verdict already collected, so a row Loqate had '
            . 'ALREADY rejected imports unnoticed - the exact outcome this plugin exists to prevent - and '
            . 'it does so only when the key disappears part-way through, which is unreproducible on demand.'
        );
        $this->assertSame(
            'Invalid address at row #8',
            (string)$result->recordedErrors[0]['errorMessage'],
            'And it must be reported against its own row number: the numbering of the chunks already '
            . 'verified is unaffected by a later chunk having nothing to say.'
        );
        $this->assertSame(
            2,
            count($this->batchCalls),
            'Verification must still stop at the keyless chunk: without a key no further chunk can be '
            . 'verified either.'
        );
    }

    /**
     * THE row arithmetic, across a chunk boundary - the one place an off-by-one can hide and
     * the reason ValidateImportAddress must keep receiving each chunk keyed 0..N-1 in input
     * order.
     *
     * 250 rows in chunks of 100/100/50, with invalid rows placed at the file positions that
     * break different mistakes: the very first row (0), an interior one (5), the LAST row of
     * chunk 1 (99) and the FIRST row of chunk 2 (100) - which is the pair an off-by-one
     * collapses onto one number - the last row of chunk 2 (149), and the last row of the file
     * (249).
     */
    public function testInvalidRowsAreReportedWithCorrectRowNumbersAcrossChunks(): void
    {
        $this->batchResults = [
            self::invalidAt(self::CHUNK_SIZE, [0, 5, 99]),
            self::invalidAt(self::CHUNK_SIZE, [0, 49]),
            self::invalidAt(50, [49]),
        ];

        $result = $this->runPlugin(250);

        $this->assertSame(
            [1, 6, 100, 101, 150, 250],
            array_column($result->recordedErrors, 'rowNumber'),
            'Row numbers are 1-based file positions and must survive the chunk boundary: rows 99 and 100 '
            . 'of the file are the last row of chunk 1 and the first row of chunk 2, so an off-by-one '
            . 'anywhere in the chunking or the merge collapses or shifts them.'
        );
        $this->assertSame(
            [
                'Invalid address at row #1',
                'Invalid address at row #6',
                'Invalid address at row #100',
                'Invalid address at row #101',
                'Invalid address at row #150',
                'Invalid address at row #250',
            ],
            array_map(
                static fn (array $error): string => (string)$error['errorMessage'],
                $result->recordedErrors
            ),
            'The message the merchant reads must name the same row as the machine-readable row number.'
        );
        foreach ($result->recordedErrors as $error) {
            $this->assertSame(
                ProcessingError::ERROR_LEVEL_CRITICAL,
                $error['errorLevel'],
                'An invalid address must block the import, not merely annotate it.'
            );
        }

        // ...and the chunking itself, since the row numbers above are only meaningful if the
        // chunks really were the file's rows in order.
        $batches = array_values(iterator_to_array($this->batchCalls));
        $this->assertSame(
            [self::CHUNK_SIZE, self::CHUNK_SIZE, 50],
            array_map(static fn (array $call): int => count($call['batch']), $batches),
            'The file must be verified in chunks of 100 rows, in order, with the remainder last.'
        );
        $this->assertSame(
            ['ROW-0', 'ROW-100', 'ROW-200'],
            array_map(
                static fn (array $call): string => (string)reset($call['batch'])['postcode'],
                $batches
            ),
            'Each chunk must start at the row it should: the row numbers reported above are derived from '
            . 'the chunk offset, so a chunk that started elsewhere would number every row in it wrongly.'
        );
        $this->assertSame(
            [false, false, false],
            array_column($batches, 'checkForCaptured'),
            'The import must pass $checkForCaptured = false: an import file cannot contain addresses '
            . 'picked from the Loqate lookup in this shopper\'s session, so consulting that store would '
            . 'match rows against another customer\'s captured addresses.'
        );
    }

    /**
     * A clean file must be reported clean, and a single chunk shorter than the chunk size must
     * not be padded or shifted by the chunking.
     */
    public function testACleanFileIsReportedWithoutErrors(): void
    {
        $this->batchResults = [self::allValid(7)];

        $result = $this->runPlugin(7);

        $this->assertSame([], $result->recordedErrors, 'A file whose every row verified must report nothing.');
        $this->assertSame(1, count($this->batchCalls), 'Seven rows are one chunk.');
    }

    /**
     * DEFENSIVENESS ONLY, and explicitly so: this is the plugin's behaviour on a verdict array
     * with a GAP in it, a shape Validator::verifyMultipleAddresses() can no longer produce.
     *
     * It used to be a statement of a known limit. It is not one any more. A short or shifted
     * response now fails the whole batch inside the Validator (its row-count guard returns
     * false, which the false branch above handles), so every chunk this plugin receives is
     * either a complete verdict array, false, or 'noKeyFound'. The test is kept, re-framed,
     * because the plugin is a separate unit that stubs the Validator out: it has no way to
     * enforce that guarantee, and what it does when handed a gappy array is worth pinning -
     * it must degrade, not crash, and above all it must not report the GAP as an invalid row,
     * which would block an import over rows nobody rejected.
     *
     * The shifted row number in the expectation below is therefore NOT an endorsement. It is
     * the arithmetic consequence of array_merge() renumbering by insertion order, recorded here
     * so that if a future change ever makes gappy arrays reachable again - a new caller, a
     * relaxed guard - the cost is already written down and is found by reading this test rather
     * than by a merchant editing a valid row while the bad one imports.
     */
    public function testAGappyVerdictArrayIsToleratedEvenThoughTheValidatorCanNoLongerProduceOne(): void
    {
        $this->batchResults = [
            // Row 50 of chunk 1 has no entry at all. Unreachable through
            // Validator::verifyMultipleAddresses() since its row-count guard, but the plugin
            // cannot know that: this is a stubbed Validator.
            self::invalidAt(self::CHUNK_SIZE, [3], [50]),
            self::invalidAt(50, [0]),
        ];

        $result = $this->runPlugin(150);

        $this->assertSame(
            [4, 100],
            array_column($result->recordedErrors, 'rowNumber'),
            'The plugin must degrade rather than crash, and must not report the gap itself as an invalid '
            . 'row - a missing verdict is not a rejection, and reporting it would block an import over a '
            . 'row nobody rejected. Row 4 keeps its true number. The first row of chunk 2 is row 101 of '
            . 'the file and is reported as 100, because array_merge() renumbers by insertion order and the '
            . 'chunk before it was one entry short: the arithmetic consequence of a gap, recorded rather '
            . 'than endorsed, and now UNREACHABLE through Validator::verifyMultipleAddresses() - a row '
            . 'count that does not match the addresses sent returns false and is handled above.'
        );
    }

    /**
     * Existing behaviour that gates everything: with no API key, or with the feature switched
     * off, or on an import behaviour other than "add/update", the plugin must not verify
     * anything at all - and must still hand the aggregator back untouched.
     *
     * @param array<string, string> $config Configuration overrides.
     * @param string $behavior Import behaviour of the run.
     */
    #[DataProvider('disabledCaseProvider')]
    public function testTheFeatureGatesVerifyNothing(array $config, string $behavior): void
    {
        foreach ($config as $path => $value) {
            $this->config[$path] = $value;
        }

        $result = $this->runPlugin(10, $behavior);

        $this->assertSame([], $result->recordedErrors, 'Nothing was verified, so nothing may be reported.');
        $this->assertSame(
            0,
            count($this->batchCalls),
            'No billable request may be issued when the feature does not apply.'
        );
    }

    public static function disabledCaseProvider(): array
    {
        return [
            'no API key configured' => [
                ['loqate_settings/settings/api_key' => ''],
                Import::BEHAVIOR_ADD_UPDATE,
            ],
            'customer import verification switched off' => [
                [self::IMPORT_ENABLED => ''],
                Import::BEHAVIOR_ADD_UPDATE,
            ],
            'a delete run, not add/update' => [[], Import::BEHAVIOR_DELETE],
            'a replace run, not add/update' => [[], Import::BEHAVIOR_REPLACE],
        ];
    }

    /**
     * An exception thrown while reading the import source must be swallowed and the aggregator
     * returned untouched, which is pre-existing behaviour: the plugin is an afterValidateData
     * plugin, so throwing here would replace Magento's own validation report with a stack
     * trace.
     */
    public function testAnExceptionWhileReadingTheSourceLeavesTheImportUntouched(): void
    {
        $aggregator = self::errorAggregator();
        $subject = $this->createMock(Address::class);
        $subject->method('getBehavior')->willReturn(Import::BEHAVIOR_ADD_UPDATE);
        $subject->method('getSource')->willThrowException(new \RuntimeException('source file is gone'));

        $result = $this->createPlugin()->afterValidateData($subject, $aggregator);

        $this->assertSame($aggregator, $result, 'The aggregator must be handed straight back.');
        $this->assertSame([], $result->recordedErrors, 'Nothing may be reported for a fault we cannot attribute.');
    }

    /**
     * ...and the ONE exception that must NOT be absorbed, which is the contrast that gives the
     * test above its meaning.
     *
     * An \InvalidArgumentException on this path is not a runtime fault. It is
     * ShopperScopedAddressStores::assertEnrolled() reporting that a session store was reached
     * without being enrolled in the shopper-ownership flush - a bug a developer has to fix,
     * and the single signal that a store is escaping the guard that stops one shopper
     * inheriting another's verify bypass.
     *
     * Swallowing it would be worse than a silent failure. This plugin has no logger, so the
     * report would go nowhere at all, and the assertion that exists specifically to be
     * impossible to ignore would become impossible to notice. So it propagates, and the
     * import fails loudly.
     *
     * Safe to propagate because it cannot be a deserialisation failure: every call site that
     * unserialises a cache entry catches \InvalidArgumentException itself and degrades to a
     * miss, so a malformed session entry never reaches this catch. If that stops being true,
     * this test is the one that should be revisited - not the narrowing.
     */
    public function testAnEnrolmentFailurePropagatesRatherThanBeingSwallowed(): void
    {
        $subject = $this->createMock(Address::class);
        $subject->method('getBehavior')->willReturn(Import::BEHAVIOR_ADD_UPDATE);
        $subject->method('getSource')->willThrowException(
            new \InvalidArgumentException(
                'Session key "loqate_email" is not enrolled in the shopper-ownership flush.'
            )
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not enrolled in the shopper-ownership flush');

        $this->createPlugin()->afterValidateData($subject, self::errorAggregator());
    }

    /**
     * Run the plugin over a file of $rowCount rows, answering each chunk from
     * $this->batchResults.
     *
     * @param int $rowCount Number of rows in the import file.
     * @param string $behavior Import behaviour of the run.
     * @return ProcessingErrorAggregator&object{recordedErrors: array} The aggregator it returned.
     */
    private function runPlugin(int $rowCount, string $behavior = Import::BEHAVIOR_ADD_UPDATE)
    {
        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            // 'postcode' doubles as the row's identity, so a test can assert WHICH rows ended
            // up in which chunk and not merely how many.
            $rows[] = [
                'street' => ['1 High St'],
                'city' => 'London',
                'postcode' => 'ROW-' . $i,
                'country_id' => 'GB',
            ];
        }

        $subject = $this->createMock(Address::class);
        $subject->method('getBehavior')->willReturn($behavior);
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
     * The plugin under test, built WITHOUT its constructor.
     *
     * AbstractPlugin's constructor wants Magento\Framework\App\Action\Context, UrlInterface
     * and JsonFactory, none of which this plugin uses and none of which exists in this
     * bootstrap. Stubbing three framework classes to satisfy a constructor whose values are
     * never read would make the fixture less faithful, not more, so the two collaborators the
     * plugin actually reads are injected into their declared properties instead.
     *
     * @return ValidateImportAddress
     */
    private function createPlugin(): ValidateImportAddress
    {
        $config = $this->config;
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static fn ($configPath) => $config[$configPath] ?? ''
        );

        $calls = $this->batchCalls;
        $results = $this->batchResults;
        $validator = $this->createMock(Validator::class);
        $validator->method('verifyMultipleAddresses')->willReturnCallback(
            function ($addresses, $checkForCaptured = true) use ($calls, $results) {
                $position = count($calls);
                $calls[] = ['batch' => $addresses, 'checkForCaptured' => $checkForCaptured];

                $this->assertArrayHasKey(
                    $position,
                    $results,
                    sprintf(
                        'The plugin verified chunk #%d, but this test only queued %d chunk(s). How many '
                        . 'chunks are verified is part of what these tests assert, so an unqueued call '
                        . 'is a failure rather than something to answer with a default.',
                        $position + 1,
                        count($results)
                    )
                );

                return $results[$position];
            }
        );

        $plugin = (new ReflectionClass(ValidateImportAddress::class))->newInstanceWithoutConstructor();
        foreach (['validator' => $validator, 'helper' => $helper] as $property => $value) {
            $reflection = new \ReflectionProperty(ValidateImportAddress::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue($plugin, $value);
        }

        return $plugin;
    }

    /**
     * A ProcessingErrorAggregator that records what was reported to it, in order, keeping the
     * real parameter names so a test failure reads like the merchant's import report.
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

    /**
     * A chunk verdict array in which every row passed: keys 0..$count-1, as
     * Validator::verifyMultipleAddresses() guarantees.
     *
     * @param int $count Rows in the chunk.
     * @return array<int, bool>
     */
    private static function allValid(int $count): array
    {
        return self::invalidAt($count, []);
    }

    /**
     * A chunk verdict array with the given LOCAL offsets failing, and optionally some rows
     * missing entirely (a row Loqate did not answer keeps no entry at all).
     *
     * @param int $count Rows in the chunk.
     * @param int[] $invalidOffsets Local offsets that must come back false.
     * @param int[] $unansweredOffsets Local offsets that must have no entry at all.
     * @return array<int, bool>
     */
    private static function invalidAt(int $count, array $invalidOffsets, array $unansweredOffsets = []): array
    {
        $verdicts = [];
        for ($offset = 0; $offset < $count; $offset++) {
            if (in_array($offset, $unansweredOffsets, true)) {
                continue;
            }

            $verdicts[$offset] = !in_array($offset, $invalidOffsets, true);
        }

        return $verdicts;
    }
}
