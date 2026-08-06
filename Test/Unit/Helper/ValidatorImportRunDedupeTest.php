<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Model\Config\Source\AddressQualityIndex;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unit tests for the BILLING guarantee LOQ-17148 buys on the CUSTOMER IMPORT path:
 * Validator::verifyMultipleAddresses() called repeatedly, within ONE PHP request, over the
 * chunks of one import file.
 *
 * WHY A SECOND FILE BESIDE ValidatorBatchVerifyCacheTest. That class pins what the SESSION
 * batch cache does - it is keyed on "a later request in the same session", and its whole
 * subject is what survives between requests. Everything here is keyed on the opposite
 * lifetime: what one import RUN must remember about itself and must NOT leave behind. The two
 * lifetimes are different guarantees with opposite failure modes (replaying too little costs
 * money, replaying too much brands a merchant's rows invalid), so they are asserted apart
 * rather than interleaved into a class that is already the largest in the suite.
 *
 * WHAT WAS UNFIXED, and what "one run" means here. LOQ-16976 gave this method a session-scoped
 * verdict cache, but Plugin\Admin\ValidateImportAddress::afterValidateData() chunks one import
 * file at 100 rows and verifies every chunk INSIDE ONE REQUEST, and the session store caches
 * PASSES ONLY. So a REJECTED address repeated across chunks was re-sent - and therefore
 * re-billed, the Cleansing API being billed per ADDRESS and not per request - in every chunk it
 * appeared in, however many times that was. On a default install that is close to every row:
 * etc/config.xml ships address_quality_index at 'A', the strictest grade, so most rows fail and
 * were never cacheable in the first place. "One run" is therefore modelled as ONE Validator
 * INSTANCE answering several batches, because that is exactly the lifetime an import chunk loop
 * has; "a later run" is a second Validator over the same session double, which is what
 * createRequest() takes a session for.
 *
 * WHAT MUST NOT BE FIXED WITH IT, measured and deliberate (LOQ-17148 design note D2). The
 * SESSION store stays passes-only. Caching failures THERE is a regression on the target
 * workload, not an improvement: eviction is FIFO and bounded by
 * Validator::BATCH_VERIFY_CACHE_LIMIT, so on a 1000-row file at a 5% pass rate the failures
 * crowd out the sparse passes the store currently manages to keep, and run 2 goes from 950
 * billed addresses to 1000. It is also what keeps a merchant from being stranded: a rejection
 * that outlived the request would still be replayed after they corrected the file or loosened
 * the threshold. testARejectionIsNeverReplayedToALaterRequest() is that half.
 *
 * THE ONE RULE THAT GOVERNS ALL OF IT: NEVER REMEMBER A VERDICT WE COULD NOT READ. An AQI that
 * could not be read, or a threshold that could not be read, produces a rejection that is a
 * FAULT REPORT rather than a verdict - so it is remembered nowhere, not even for the identical
 * address in the same run, and the row is billed again. One connector fault or one bad
 * credential must not brand every matching row in the file invalid for the rest of the run.
 * That is what testAnUnreadableQualityIndexIsRememberedNowhereSoTheRowIsBilledAgain() and
 * testAnUnreadableThresholdRejectsEveryRowAndIsRememberedNowhere() hold, and it is the
 * assertion to look at first if a dedupe change ever makes the headline test cheaper.
 *
 * Every count asserted here is ADDRESSES BILLED - count($payload['Addresses']) summed over
 * every connector invocation - and not requests, because that is what the invoice is.
 */
class ValidatorImportRunDedupeTest extends TestCase
{
    /** Any non-empty key makes the batch path reach the billable call. */
    private const API_KEY = 'TEST-API-KEY-0000';

    /**
     * Configured address quality index threshold. checkQualityIndex() compares the response's
     * AQI against it with <=, as plain strings, so 'A' and 'B' pass a 'C' threshold and 'E'
     * does not. Deliberately NOT the shipped 'A', so a passing grade and a failing grade are
     * both expressible without either being the boundary value.
     */
    private const AQI_THRESHOLD = 'C';

    /** AQI better than self::AQI_THRESHOLD => the row passes. */
    private const PASSING_AQI = 'A';

    /** AQI poorer than self::AQI_THRESHOLD => the row is REJECTED, which is the broken case. */
    private const FAILING_AQI = 'E';

    /**
     * An AVC the response rows carry for realism only. The batch path judges the AQI and
     * ignores the AVC entirely; a row without one would still be a faithful fixture, but the
     * Cleansing API does not send one, so neither does this.
     */
    private const CARRIED_AVC = 'V55-I22-P9-99';

    /** Session data key the BATCH verdict cache must live under. */
    private const BATCH_VERIFY_CACHE_SESSION_KEY = 'loqate_verified_batch_addresses';

    /** Session data key the SINGLE-address verdict cache lives under. */
    private const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /** Config path of the threshold batch verdicts are judged against. */
    private const AQI_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /**
     * Rows per verification batch, as Plugin\Admin\ValidateImportAddress::afterValidateData()
     * chunks an import file. Mirrored rather than read from the plugin because it is a literal
     * there; if the plugin ever chunks differently, this test's file layout is what has to be
     * re-thought, so it should fail loudly rather than follow along.
     */
    private const IMPORT_CHUNK_SIZE = 100;

    /**
     * Response-row shapes carrying no readable AQI at all, keyed by the $apiVerdicts token
     * that produces them. Each value is one element of the list the CONNECTOR emits - one
     * record's 'Matches' value after Verify::verifyAddress()'s array_column($response,
     * 'Matches').
     *
     * ALL THREE survive that array_column() with the row count PRESERVED (it drops only
     * records lacking the 'Matches' KEY), so verifyMultipleAddresses()' row-count guard cannot
     * see them and they reach the attribution loop as an AQI of null. The same taxonomy is
     * used by ValidatorBatchVerifyCacheTest; it is repeated rather than shared because these
     * are fixture shapes and a shared parent class would couple two test classes with
     * different subjects.
     *
     * @var array<string, mixed>
     */
    private const UNREADABLE_ROW_SHAPES = [
        // "Matches":[{}] - a match object with no AQI field in it.
        'match carrying no AQI' => [[]],
        // "Matches":[] - Loqate saying "no match for this address".
        'Matches list that is empty' => [],
        // "Matches":null - a survivor of array_column() just as [] is.
        'Matches value that is null' => null,
    ];

    /**
     * AQI values that are PRESENT in the response but carry no readable grade, keyed by the
     * $apiVerdicts token that produces them. Every one compares as BETTER than any letter
     * threshold under PHP 8's <= rules, which is what made them dangerous rather than merely
     * odd. The INT 0 belongs here; the STRING '0' does not, being a readable non-empty string.
     *
     * @var array<string, mixed>
     */
    private const UNREADABLE_AQI_VALUES = [
        'empty-aqi' => '',
        'false-aqi' => false,
        'zero-aqi' => 0,
    ];

    /** @var array The primary request harness, as returned by createRequest(). */
    private $request;

    /**
     * What the API answers per address, keyed by the address's first street line: 'pass',
     * 'fail', or one of the self::UNREADABLE_ROW_SHAPES / self::UNREADABLE_AQI_VALUES tokens.
     * Anything absent answers 'pass'.
     *
     * Mutable mid-test on purpose: an address whose answer CHANGES between two batches of one
     * run is how a remembered verdict is told apart from a live one.
     *
     * @var array<string, string>
     */
    private $apiVerdicts = [];

    protected function setUp(): void
    {
        $this->apiVerdicts = [];
        $this->request = $this->createRequest();
    }

    /**
     * THE ACCEPTANCE MEASUREMENT of LOQ-17148, on the file shape that showed the defect: an
     * import file LARGER than the session cache's bound, whose repeated addresses are the ones
     * Loqate REJECTS.
     *
     * Within one run, the number of addresses billed must equal the number of DISTINCT
     * addresses in the file. Not "fewer than before" - exactly the distinct count, because any
     * excess is a duplicate somebody paid for twice.
     *
     * WHY THE REPEATS ARE ALL REJECTIONS. Passing repeats were already deduped by the session
     * store (LOQ-16976), so a file whose repeats pass would go green against the unfixed code
     * and measure nothing. Rejections are the case that was re-billed in every chunk they
     * appeared in, and on a default install they are most of the file: etc/config.xml ships
     * address_quality_index at 'A'.
     *
     * WHY THE FILE IS BIGGER THAN THE LIMIT, and why the limit is read by reflection rather
     * than mirrored: this is precisely the size at which the session store stops helping, since
     * FIFO eviction over a working set larger than the cache yields close to zero hits however
     * the constant is chosen. A file that FITS in the limit cannot distinguish the fix from the
     * cache that was already there.
     *
     * WHAT THE FIXTURE DELIBERATELY DOES NOT CONTAIN, asserted rather than promised: no address
     * appears twice inside ONE chunk. Two copies of an address in a single batch are both sent,
     * because nothing is written until the response comes back - that is LOQ-17015, a separate
     * ticket with its own arithmetic (the row-count guard in verifyMultipleAddresses() depends
     * on one response row per sent item), and pulling it into this measurement would make the
     * headline number unmeetable for a reason this ticket is not about.
     */
    public function testAnImportRunLargerThanTheCacheLimitBillsEachDistinctAddressOnce(): void
    {
        $limit = $this->batchCacheLimit();
        $fileIds = $this->crossChunkRepeatFileIds();
        $rejectedIds = $this->rejectedFileIds();
        foreach ($rejectedIds as $id) {
            $this->apiVerdicts[$this->distinctAddress($id)['street'][0]] = 'fail';
        }
        $this->assertFileRepeatsOnlyAcrossChunks($fileIds, $rejectedIds, $limit);

        $verdicts = $this->verifyFileInChunks($this->request, $fileIds);

        $distinctCount = count(array_unique($fileIds));
        $this->assertSame(
            $distinctCount,
            $this->addressesBilled($this->request),
            sprintf(
                'A %d-row import file holding %d distinct addresses must cost %d billed addresses in one '
                . 'run, not one per row. The Cleansing API is billed per ADDRESS, and before LOQ-17148 a '
                . 'REJECTED address was re-sent in every chunk it appeared in, because the session store '
                . 'caches passes only - which on a default install (address_quality_index ships as \'A\') is '
                . 'most of the file. Any number above %d is a duplicate the merchant paid for twice.',
                count($fileIds),
                $distinctCount,
                $distinctCount,
                $distinctCount
            )
        );
        $this->assertSame(
            [],
            array_keys(array_filter(
                array_count_values($this->streetsBilled($this->request)),
                static fn (int $times): bool => $times > 1
            )),
            'No address may reach the billable endpoint twice in one run. Asserted per address rather than '
            . 'only in total, so a total that happens to add up while one address is billed twice and '
            . 'another is skipped cannot pass.'
        );

        $expected = [];
        foreach (array_values($fileIds) as $row => $id) {
            $expected[$row] = !in_array($id, $rejectedIds, true);
        }
        $this->assertSame(
            $expected,
            $verdicts,
            'Every row must still carry the verdict of the address on THAT row, in file order: the merged '
            . 'chunk results are what Plugin\Admin\ValidateImportAddress turns into row numbers, so a '
            . 'de-duplicated row that loses its slot renumbers every row after it.'
        );
    }

    /**
     * The single row of the measurement above, in isolation: a REJECTED address that comes back
     * in a later chunk of the same run is billed exactly once.
     *
     * Kept separate from the headline test because a 260-row file that is off by one tells you
     * only that something is wrong, while this one names it. It is also the smallest fixture
     * that fails against the unfixed code, so it is the first thing to run while implementing.
     */
    public function testARejectedAddressRepeatedInALaterChunkIsBilledOnce(): void
    {
        $accepted = $this->distinctAddress(1);
        $rejected = $this->distinctAddress(2);
        $this->apiVerdicts[$rejected['street'][0]] = 'fail';

        $firstChunk = $this->request['validator']->verifyMultipleAddresses([$accepted, $rejected], false);
        $laterChunk = $this->request['validator']->verifyMultipleAddresses([$rejected, $accepted], false);

        $this->assertSame(
            2,
            $this->addressesBilled($this->request),
            'Two distinct addresses, each appearing in two chunks of one import run: two billed addresses. '
            . 'The rejected one used to be billed again in every chunk it appeared in, because only passes '
            . 'were remembered - and a rejection is exactly as definitive a verdict as a pass, as long as '
            . 'the AQI it was read from was readable.'
        );
        $this->assertSame(
            [true, false],
            array_values($firstChunk),
            'Fixture precondition, read off the verdicts rather than assumed: the second address really is '
            . 'the one Loqate rejects, or this test would be measuring a repeated PASS - which the session '
            . 'store already deduped before LOQ-17148.'
        );
        $this->assertSame(
            [false, true],
            array_values($laterChunk),
            'And the later chunk must report the SAME verdicts under ITS OWN keys: dedupe may not cost a '
            . 'row its verdict, or the import reports an invalid row against a valid row\'s number.'
        );
    }

    /**
     * A rejection is NEVER replayed to a LATER REQUEST - the deliberate limit of the fix
     * (LOQ-17148 design note D2), and the reason the session store stays passes-only.
     *
     * Two things rest on it. A merchant who corrects the file, or loosens
     * address_quality_index, must be re-verified rather than stranded on a "no" earned under
     * the old data or the old threshold - so the second request here is answered by a Loqate
     * that has changed its mind, and must report the NEW verdict. And the session payload must
     * not fill with rejections: eviction is FIFO and bounded, so on a file whose rows mostly
     * fail, cached failures would crowd out the sparse passes the store does manage to keep and
     * the NEXT run would be billed MORE than it is today, not less.
     *
     * A fresh Validator over the SAME session double is what "a later request" is: the run
     * scoped memory dies with the object, and only what was written to the session survives.
     */
    public function testARejectionIsNeverReplayedToALaterRequest(): void
    {
        $address = $this->distinctAddress(1);
        $this->apiVerdicts[$address['street'][0]] = 'fail';

        $firstRun = $this->createRequest();
        $rejection = $firstRun['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame([0 => false], $rejection, 'The address is rejected on the first run.');
        $this->assertSame(
            [],
            $this->batchStore($firstRun),
            'A rejection may not be written to the SESSION store. Stored there it would outlive the '
            . 'request, so a merchant who corrected the address or loosened the threshold would keep being '
            . 'told "invalid" with no request made and no way to clear it - and, on a file whose rows '
            . 'mostly fail, the FIFO bound would spend the whole store on rejections and re-bill the passes '
            . 'it used to keep.'
        );
        $this->assertSame(
            [],
            $this->singleStore($firstRun),
            'Nor to the single-address store, whose verdicts are judged against the AVC thresholds and not '
            . 'the AQI: a rejection hidden there would be replayed by verifyAddress() at checkout.'
        );

        // The merchant loosens the threshold, or fixes the data: Loqate now accepts the address.
        $this->apiVerdicts[$address['street'][0]] = 'pass';
        $laterRun = $this->createRequest($firstRun['session']);
        $reVerified = $laterRun['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            1,
            $this->addressesBilled($laterRun),
            'The later request must ASK Loqate again. Being billed one address per run for a row that keeps '
            . 'failing is the accepted cost of never stranding a merchant on a stale rejection; it is also '
            . 'what stops a rejection outliving the configuration it was judged under.'
        );
        $this->assertSame(
            [0 => true],
            $reVerified,
            'And it must report the LIVE verdict, so the stale rejection can be seen not to have answered '
            . 'the lookup rather than merely to have agreed with it.'
        );
    }

    /**
     * THE TRAP. A rejection reached because the AQI could not be READ is remembered NOWHERE -
     * not in the session, and not for the rest of the run either - so the identical address is
     * billed again, and a later readable answer for it is reported as it comes.
     *
     * This is the invariant a run-scoped memory of failures is most likely to break, and the
     * damage is the opposite of a billing one: an unreadable response is a FAULT REPORT, not a
     * verdict, and remembering it would let ONE connector fault, one credential problem or one
     * "no match for this address" brand every matching row in the file invalid for the rest of
     * the run - reported to the merchant as a row to go and fix, with nothing on the server
     * saying otherwise.
     *
     * Both routes to an unreadable AQI are covered, because they arrive differently and a guard
     * written for one misses the other: a row with no AQI in it at all (the '??' supplies null)
     * and an AQI that is PRESENT but unreadable ('', false, int 0 - each of which compares as
     * better than any letter grade under PHP 8's <= rules).
     *
     * The third batch is the load-bearing one: it proves no FALSE verdict was remembered, which
     * an assertion about the billed count alone cannot distinguish from a rejection that was
     * remembered and happened to be re-billed anyway.
     *
     * @param string $token $apiVerdicts token naming the unreadable answer to give.
     */
    #[DataProvider('unreadableAnswerProvider')]
    public function testAnUnreadableQualityIndexIsRememberedNowhereSoTheRowIsBilledAgain(string $token): void
    {
        $address = $this->distinctAddress(1);
        $this->apiVerdicts[$address['street'][0]] = $token;

        $firstChunk = $this->request['validator']->verifyMultipleAddresses([$address], false);
        $laterChunk = $this->request['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame([0 => false], $firstChunk, 'An AQI we could not read must still REJECT the row.');
        $this->assertSame(
            [0 => false],
            $laterChunk,
            'And it must reject it again on its own merits, not on a remembered one.'
        );
        $this->assertSame(
            2,
            $this->addressesBilled($this->request),
            'The repeated row must be BILLED AGAIN. Remembering a rejection nobody could read - even only '
            . 'for the rest of this run - is what would let ONE connector fault or one bad credential mark '
            . 'every matching row in the file invalid, and the merchant would be sent to edit rows Loqate '
            . 'never rejected. Paying for the retry is the cheaper mistake by a wide margin.'
        );
        $this->assertSame(
            [],
            $this->batchStore($this->request),
            'Nothing may reach the session store either: NEVER REMEMBER A VERDICT WE COULD NOT READ has to '
            . 'hold in ONE place, for both lifetimes, or the two rules drift apart and one of them is the '
            . 'one that gets relaxed.'
        );

        // Loqate answers the third chunk readably, and PASSES the address.
        $this->apiVerdicts[$address['street'][0]] = 'pass';
        $afterTheFaultCleared = $this->request['validator']->verifyMultipleAddresses([$address], false);

        $this->assertSame(
            [0 => true],
            $afterTheFaultCleared,
            'Once Loqate answers readably the row must be reported VALID, in the SAME run. This is the '
            . 'assertion a billed-count check cannot make: it fails if the unreadable rejection was '
            . 'remembered at all, whatever it cost.'
        );
    }

    /**
     * The two families of unreadable answer, one data set each: a row with no readable AQI in
     * it at all, and an AQI that is present but is not a grade.
     *
     * @return array<string, array{0: string}>
     */
    public static function unreadableAnswerProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::UNREADABLE_ROW_SHAPES) as $token) {
            $cases['response row is a ' . $token] = [$token];
        }
        foreach (array_keys(self::UNREADABLE_AQI_VALUES) as $token) {
            $cases['AQI is ' . $token] = [$token];
        }

        return $cases;
    }

    /**
     * An UNREADABLE THRESHOLD rejects every row, and is remembered nowhere - so a repeated row
     * is billed again.
     *
     * The threshold side of the same rule, and the one that is easier to get wrong, because the
     * rejection LOOKS like a verdict: the response was perfectly readable, and the only
     * unreadable thing is the merchant's own configuration. It is still not a verdict. A value
     * nobody can read cannot be said to admit or refuse any address, so remembering the
     * refusal would keep refusing rows after the configuration was corrected - within the run
     * for the run-scoped memory, and for the whole session had it been stored there.
     *
     * 'E' is the AQI under test on purpose: the worst grade Loqate returns is the value that
     * must never pass a threshold nobody can read, and a test using 'A' would still pass if the
     * guard regressed to comparing against the shipped default.
     *
     * @param mixed $threshold Configured address_quality_index that cannot be read as a grade.
     * @param string $why What this case protects, quoted into the failure message.
     */
    #[DataProvider('unreadableThresholdProvider')]
    public function testAnUnreadableThresholdRejectsEveryRowAndIsRememberedNowhere(
        $threshold,
        string $why
    ): void {
        $request = $this->createRequest(
            null,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => $threshold])),
            static fn ($payload): array => array_map(
                static fn (): array => [['AQI' => 'E', 'AVC' => self::CARRIED_AVC]],
                (array)$payload['Addresses']
            )
        );
        $rows = [$this->distinctAddress(1), $this->distinctAddress(2)];

        $firstChunk = $request['validator']->verifyMultipleAddresses($rows, false);
        $laterChunk = $request['validator']->verifyMultipleAddresses($rows, false);

        $this->assertSame(
            [false, false],
            array_values($firstChunk),
            sprintf(
                'The worst AQI Loqate returns must not pass a threshold nobody can read; this case pins '
                . 'that %s.',
                $why
            )
        );
        $this->assertSame(
            [false, false],
            array_values($laterChunk),
            'The repeated rows must be rejected on their own answer, not on a remembered one.'
        );
        $this->assertSame(
            4,
            $this->addressesBilled($request),
            'Both rows must be BILLED AGAIN in the later chunk. A rejection produced by a threshold we '
            . 'could not read is a configuration fault report, not a verdict: remembered, it would keep '
            . 'rejecting rows after the merchant corrected the setting, and it would do so for a whole '
            . 'import run with no request made and nothing in the log for the rows after the first.'
        );
        $this->assertSame(
            [],
            $this->batchStore($request),
            'And nothing may be written to the session store, where it would outlive the request entirely.'
        );
    }

    /**
     * Threshold values that cannot be read as a grade, and why each matters. Mirrors
     * ValidatorBatchVerifyCacheTest's own provider: the three fail-OPEN cases are the point,
     * each sorting above 'E' under the string comparison, and the rest are pinned so that
     * staying closed is a rule rather than an accident of comparison semantics.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unreadableThresholdProvider(): array
    {
        return [
            "the fail-open case 'zzz'" => [
                'zzz',
                "'zzz' sorts above every grade, so 'E' <= 'zzz' is true and every address would pass",
            ],
            "a lowercase grade 'c'" => [
                'c',
                'lowercase sorts above uppercase in ASCII, so a plausible typo in a data patch loosens the '
                . 'bar instead of tightening it',
            ],
            'an unset threshold' => [
                null,
                'a path never written to reads as null, which is the state a fresh install would be in if '
                . 'etc/config.xml ever stopped supplying a default',
            ],
            'a numeric threshold' => [
                3,
                'an int is not a grade at all, and comparing a grade letter against an int is not a '
                . 'comparison anyone reasoned about',
            ],
        ];
    }

    /**
     * A batch whose every address is answered from a remembered verdict issues NO REQUEST AT
     * ALL - not an empty one against a billable endpoint, and not one carrying the rows that
     * were already decided.
     *
     * True today for passes; it must become true for the run-scoped rejections too, and it is
     * the property that makes the saving real rather than notional. A chunk of an import file
     * that repeats rows already decided earlier in the run is the common case on a file with
     * duplicated addresses, and the cheapest possible handling of it is silence.
     */
    public function testAChunkAnsweredEntirelyFromRememberedVerdictsIssuesNoRequest(): void
    {
        $accepted = $this->distinctAddress(1);
        $rejected = $this->distinctAddress(2);
        $this->apiVerdicts[$rejected['street'][0]] = 'fail';

        $this->request['validator']->verifyMultipleAddresses([$accepted, $rejected], false);
        $this->assertSame(1, $this->apiCallCount($this->request), 'The first chunk is verified normally.');

        $repeatChunk = $this->request['validator']->verifyMultipleAddresses([$rejected, $accepted], false);

        $this->assertSame(
            1,
            $this->apiCallCount($this->request),
            'A chunk in which every row is already decided must issue NO further request. Sending the '
            . 'decided rows again bills them again; sending an empty \'Addresses\' payload to a billable '
            . 'endpoint and discarding the answer is worse still.'
        );
        $this->assertSame(
            [0 => false, 1 => true],
            $repeatChunk,
            'And it must answer every row, under its own key and in input order, without asking anyone: a '
            . 'chunk that returns fewer rows than it was given renumbers every row after it in '
            . 'Plugin\Admin\ValidateImportAddress\'s merged result.'
        );
    }

    /**
     * A PASSING address evicted from the bounded session store is still not re-billed later in
     * the SAME run.
     *
     * This is the other half of "a file larger than the limit", and the one the session store
     * structurally cannot deliver: eviction is FIFO and the store is bounded, so on a file
     * bigger than the bound the rows verified early are gone before the file ends. Raising the
     * constant does not fix that - it is inherent to FIFO over a working set larger than the
     * cache - and this is why the run-scoped memory has to hold BOTH polarities rather than
     * only the rejections the session store never held.
     *
     * The eviction is asserted, not assumed: if the fill stopped evicting, the replay below
     * would be a session hit and this test would silently be measuring the old cache.
     */
    public function testAPassEvictedFromTheSessionStoreIsNotRebilledLaterInTheSameRun(): void
    {
        $limit = $this->batchCacheLimit();
        $earlyRow = $this->addressOnStreet('Evicted Pass Lane');

        $this->request['validator']->verifyMultipleAddresses([$earlyRow], false);
        $this->assertSame(1, $this->addressesBilled($this->request), 'The early row is verified and billed.');

        $filler = [];
        for ($i = 1; $i <= $limit; $i++) {
            $filler[] = $this->distinctAddress($i);
        }
        $this->request['validator']->verifyMultipleAddresses($filler, false);

        $streetsStillCached = implode(' ', array_keys($this->batchStore($this->request)));
        $this->assertStringNotContainsString(
            'EVICTED PASS LANE',
            $streetsStillCached,
            'Fixture precondition: the early row\'s verdict must have been EVICTED from the session store '
            . 'by the rows that followed it, or the replay below would be answered by that store and this '
            . 'test would prove nothing about a file larger than the bound.'
        );

        $replay = $this->request['validator']->verifyMultipleAddresses([$earlyRow], false);

        $this->assertSame(
            1 + $limit,
            $this->addressesBilled($this->request),
            'The evicted row must NOT be billed again inside the same run. FIFO eviction over a file larger '
            . 'than the bound is exactly where the session store stops paying for itself, and it is why the '
            . 'run-scoped memory has to remember passes as well as rejections.'
        );
        $this->assertSame([0 => true], $replay, 'And it must still be reported with the verdict it earned.');
    }

    /**
     * Every address quality index a merchant can SELECT in the admin form must be a threshold
     * the verifier actually accepts addresses against - so no selectable value can silently
     * reject every address in the file.
     *
     * The list is read from the admin field's own source model rather than written out here,
     * because that is the artefact that decides what the merchant is offered (LOQ-17148 design
     * note D4 exposes the field with this source model). The set-level correspondence with the
     * verifier's accepted values is asserted in
     * Test\Unit\Model\Config\Source\AddressQualityIndexTest; this is the BEHAVIOURAL half - the
     * option is put in the configuration, an address at exactly that grade is verified, and the
     * verdict is read off the wire.
     *
     * Equality is the grade under test on purpose: it is the boundary of the <= comparison and
     * the case the shipped default 'A' actually runs, since 'A' is the strongest grade there is
     * and nothing beats it.
     *
     * @param string $threshold One value of the admin field's option list.
     */
    #[DataProvider('selectableQualityIndexProvider')]
    public function testEverySelectableQualityIndexThresholdAcceptsAnAddressAtThatGrade(string $threshold): void
    {
        $request = $this->createRequest(
            null,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => $threshold])),
            static fn ($payload): array => array_map(
                static fn (): array => [['AQI' => $threshold, 'AVC' => self::CARRIED_AVC]],
                (array)$payload['Addresses']
            )
        );

        $verdicts = $request['validator']->verifyMultipleAddresses([$this->distinctAddress(1)], false);

        $this->assertSame(
            [0 => true],
            $verdicts,
            sprintf(
                'A merchant who selects "%s" in the admin form must get a threshold that ACCEPTS an address '
                . 'graded "%s". A selectable value the verifier rejects everything under is worse than an '
                . 'unexposed setting: the merchant configures a quality bar, every import row comes back '
                . 'invalid, and nothing anywhere says the value itself is the problem.',
                $threshold,
                $threshold
            )
        );
        $this->assertCount(
            1,
            $this->batchStore($request),
            'And the pass must be cacheable under that threshold: a value that verifies but never caches '
            . 'would re-bill every row of every run on that store view.'
        );
    }

    /**
     * The address quality indexes the admin field offers, one data set each, read from the
     * source model the field is wired to.
     *
     * @return array<string, array{0: string}>
     */
    public static function selectableQualityIndexProvider(): array
    {
        $cases = [];
        foreach ((new AddressQualityIndex())->toOptionArray() as $option) {
            $value = (string)($option['value'] ?? '');
            $cases['the selectable threshold ' . var_export($value, true)] = [$value];
        }

        return $cases;
    }

    /**
     * Verify a whole import file the way Plugin\Admin\ValidateImportAddress::afterValidateData()
     * does: chunked at self::IMPORT_CHUNK_SIZE and array_merge()d, on ONE Validator - which is
     * what makes it one run.
     *
     * The merge is the plugin's own, deliberately: it renumbers integer keys BY INSERTION
     * ORDER, so a chunk that comes back short or out of order shows up here as a shifted row
     * exactly as it would in the merchant's import report.
     *
     * @param array $request Request harness from createRequest().
     * @param int[] $fileIds distinctAddress() indexes, one per file row, in file order.
     * @return array<int, bool> Merged verdicts, keyed by 0-based file row.
     */
    private function verifyFileInChunks(array $request, array $fileIds): array
    {
        $merged = [];
        foreach (array_chunk($fileIds, self::IMPORT_CHUNK_SIZE) as $position => $chunk) {
            $verdicts = $request['validator']->verifyMultipleAddresses(
                array_map(fn (int $id): array => $this->distinctAddress($id), $chunk),
                false
            );

            $this->assertIsArray(
                $verdicts,
                sprintf(
                    'Chunk #%d must come back as a verdict array. false means the request failed or its row '
                    . 'count could not be attributed, and neither is what this fixture asked for.',
                    $position + 1
                )
            );
            $this->assertSame(
                range(0, count($chunk) - 1),
                array_keys($verdicts),
                sprintf(
                    'Chunk #%d must answer every row it was given, keyed 0..N-1 in input order. That is the '
                    . 'guarantee the merge below rests on: array_merge() renumbers by insertion order, so a '
                    . 'missing or out-of-order slot mis-attributes every later row number.',
                    $position + 1
                )
            );

            $merged = array_merge($merged, $verdicts);
        }

        return $merged;
    }

    /**
     * The import file of the headline measurement, as distinctAddress() indexes in file order:
     * three chunks, repeats ACROSS chunk boundaries only.
     *
     * Chunk 1 introduces 100 addresses. Chunk 2 repeats 40 of them and introduces 60 more.
     * Chunk 3 repeats 10 of chunk 1's a THIRD time and introduces 50 more. 260 rows, 210
     * distinct - more than the session store's bound, which is the size at which that store
     * stops helping.
     *
     * @return int[]
     */
    private function crossChunkRepeatFileIds(): array
    {
        return array_merge(
            range(1, 100),
            array_merge(range(1, 40), range(101, 160)),
            array_merge(range(1, 10), range(161, 210))
        );
    }

    /**
     * The addresses of that file Loqate REJECTS: exactly the ones that repeat across chunks.
     *
     * Repeats have to be the rejections for this fixture to measure anything - a repeated PASS
     * was already deduped by the session store before LOQ-17148 - and it is also the realistic
     * shape, since etc/config.xml ships the strictest grade as the threshold.
     *
     * @return int[]
     */
    private function rejectedFileIds(): array
    {
        return range(1, 40);
    }

    /**
     * Assert the headline fixture is the shape its measurement needs, so a green run cannot
     * come from a file that quietly stopped exercising the case.
     *
     * @param int[] $fileIds distinctAddress() indexes in file order.
     * @param int[] $rejectedIds Indexes Loqate rejects.
     * @param int $limit Validator::BATCH_VERIFY_CACHE_LIMIT.
     * @return void
     */
    private function assertFileRepeatsOnlyAcrossChunks(array $fileIds, array $rejectedIds, int $limit): void
    {
        $this->assertGreaterThan(
            $limit,
            count(array_unique($fileIds)),
            'The file must hold MORE distinct addresses than the session store can, or the measurement '
            . 'below could be met by the bounded cache that was already there and would say nothing about '
            . 'a file the size of a real import.'
        );

        $repeatedAcrossChunks = [];
        foreach (array_chunk($fileIds, self::IMPORT_CHUNK_SIZE) as $position => $chunk) {
            $this->assertSame(
                count($chunk),
                count(array_unique($chunk)),
                sprintf(
                    'Chunk #%d must not repeat an address WITHIN itself. Two copies in one batch are both '
                    . 'sent - nothing is written until the response comes back - which is LOQ-17015 and its '
                    . 'own arithmetic, so including one here would make this measurement unmeetable for a '
                    . 'reason LOQ-17148 is not about.',
                    $position + 1
                )
            );

            if ($position > 0) {
                $repeatedAcrossChunks = array_merge(
                    $repeatedAcrossChunks,
                    array_intersect($chunk, array_slice($fileIds, 0, $position * self::IMPORT_CHUNK_SIZE))
                );
            }
        }

        $this->assertNotSame(
            [],
            $repeatedAcrossChunks,
            'The file must actually repeat addresses across chunk boundaries, or there is nothing to '
            . 'de-duplicate and the measurement is trivially satisfied.'
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_unique($repeatedAcrossChunks), $rejectedIds)),
            'Every cross-chunk repeat must be an address Loqate REJECTS. A repeated pass was already '
            . 'deduped by the session store before LOQ-17148, so a fixture whose repeats pass would go '
            . 'green against the unfixed code.'
        );
    }

    /**
     * Build one independent "request": a Validator wired to its own billable connector mock,
     * its own live store configuration and a session double that actually retains data.
     *
     * ONE Validator IS ONE REQUEST, and that is the whole point of this harness: the import
     * chunk loop in Plugin\Admin\ValidateImportAddress::afterValidateData() calls
     * verifyMultipleAddresses() several times on one instance, so several calls on one harness
     * model one run, and a second harness over the SAME session models a later request.
     *
     * Modelled on ValidatorBatchVerifyCacheTest::createShopper() and kept deliberately similar,
     * since the two classes' results are read against each other.
     *
     * @param ArrayObject|null $session Session backing store to reuse, or null for a new session.
     * @param ArrayObject|null $config Live store configuration, config path => value.
     * @param callable|null $respond Replacement connector response builder, given the payload.
     * @return array{validator: Validator, connector: Verify&MockObject, requests: ArrayObject,
     *     session: ArrayObject, config: ArrayObject}
     */
    private function createRequest(
        ?ArrayObject $session = null,
        ?ArrayObject $config = null,
        ?callable $respond = null
    ): array {
        $sessionStore = $session ?? new ArrayObject();
        $requests = new ArrayObject();
        $configStore = $config ?? new ArrayObject(self::configWith([]));

        $logger = $this->createMock(Logger::class);

        // The shared Test/stubs Session is a no-op (getData() returns null, setData() stores
        // nothing), so nothing could ever survive between calls and every "is this remembered"
        // assertion would pass for the wrong reason. This double retains data in $sessionStore,
        // which also lets the tests read the cache attributes directly. getData()/setData() have
        // to be *added* when the real Magento\Customer\Model\Session is present, because it does
        // not declare them (SessionManager __call-forwards them to Session\Storage); the
        // Test/stubs Session does declare them, and PHPUnit refuses to "add" an existing method
        // - hence the method_exists() filter, which keeps this double working on both sides.
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

        // No fixture here uses region_id, but parseAddress() resolves one through RegionFactory,
        // so the factory must not return null if one ever grows one.
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

        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static function ($configPath) use ($configStore) {
                // A non-empty API key is required twice: the constructor only builds the
                // connector when it is set, and verifyMultipleAddresses() short-circuits with
                // noKeyFound when it is not.
                if ($configPath === 'loqate_settings/settings/api_key') {
                    return self::API_KEY;
                }

                return $configStore->offsetExists($configPath) ? $configStore[$configPath] : '';
            }
        );
        // Verdicts are namespaced per store view, because the AQI threshold behind them is read
        // at SCOPE_STORE. Stubbed explicitly: getCurrentStore() is declared int, so an unstubbed
        // mock returns 0 anyway, but relying on that hides which scope a key was built for.
        $helper->method('getCurrentStore')->willReturn(0);

        // Fails the way the PRODUCTION serializer fails.
        // Magento\Framework\Serialize\Serializer\Json::unserialize() THROWS
        // \InvalidArgumentException on anything it cannot decode - the empty string and null
        // included - rather than answering null. A lenient `fn ($v) => json_decode($v, true)`
        // double makes getCachedBatchVerifyResult()'s catch block unreachable from the harness,
        // so an import meeting a truncated session payload would fatal in production while this
        // file stayed green. Mirrors CapturedAddressStoreTest::createSerializerDouble() and
        // ValidatorBatchVerifyCacheTest::createSerializerDouble().
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn ($value) => json_encode($value));
        $serializer->method('unserialize')->willReturnCallback(
            static function ($value) {
                if ($value === false || $value === null || $value === '') {
                    throw new \InvalidArgumentException('Unable to unserialize value.');
                }

                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \InvalidArgumentException('Unable to unserialize value, string is corrupted.');
                }

                return $decoded;
            }
        );

        $validator = new Validator($logger, $sessionMock, $regionFactory, $moduleList, $helper, $serializer);

        // The connector is built inside the constructor (new Verify($apiKey)), so the only way
        // to intercept the billable call is to swap the private property afterwards.
        $connector = $this->createMock(Verify::class);
        $connector->method('verifyAddress')->willReturnCallback(
            function ($payload) use ($requests, $respond) {
                $requests[] = $payload;

                if ($respond !== null) {
                    return $respond($payload);
                }

                // One row per address SENT, in the order they were sent: that positional
                // correspondence is what the production code attributes verdicts by, and it is
                // how the Cleansing API answers a batch.
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
        ];
    }

    /**
     * What the API answers for one parsed address, in the shape verifyMultipleAddresses() reads:
     * $response[$n][0]['AQI'].
     *
     * The return type is deliberately left off rather than declared array: the connector's
     * array_column($response, 'Matches') emits whatever each record's 'Matches' held, so a row
     * can legitimately arrive as null or as [].
     *
     * @param array $address One entry of the payload's 'Addresses' list.
     * @return mixed One response row.
     */
    private function responseRowFor(array $address)
    {
        $verdict = $this->apiVerdicts[(string)($address['Address1'] ?? '')] ?? 'pass';

        if (array_key_exists($verdict, self::UNREADABLE_ROW_SHAPES)) {
            return self::UNREADABLE_ROW_SHAPES[$verdict];
        }

        if (array_key_exists($verdict, self::UNREADABLE_AQI_VALUES)) {
            return [[
                'AQI' => self::UNREADABLE_AQI_VALUES[$verdict],
                'AVC' => self::CARRIED_AVC,
            ]];
        }

        return [[
            'AQI' => $verdict === 'fail' ? self::FAILING_AQI : self::PASSING_AQI,
            'AVC' => self::CARRIED_AVC,
        ]];
    }

    /** A unique, fully-formed import row per index. */
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
     * An import row on a named street, for the one test that has to recognise its address
     * inside a cache key: distinctAddress()' streets are numeric prefixes of one another
     * ('1 Test Street' is a substring of '11 Test Street'), so they cannot be searched for.
     *
     * @param string $street Street line, unique among the fixtures it is used with.
     * @return array Magento-shaped address.
     */
    private function addressOnStreet(string $street): array
    {
        return [
            'street' => [$street],
            'city' => 'London',
            'region' => 'Greater London',
            'postcode' => 'SW1A 9ZZ',
            'country_id' => 'GB',
        ];
    }

    /**
     * Store configuration for these tests: the AQI threshold every batch verdict is judged
     * against, plus whatever else a test cares about.
     *
     * @param array<string, mixed> $overrides Config path => value.
     * @return array<string, mixed>
     */
    private static function configWith(array $overrides): array
    {
        return array_merge([self::AQI_CONFIG_PATH => self::AQI_THRESHOLD], $overrides);
    }

    /**
     * Number of ADDRESSES a request put on the invoice: the Cleansing API is billed per
     * address, not per request, so this - and not the request count - is what LOQ-17148 has to
     * reduce.
     *
     * @param array $request Request harness from createRequest().
     * @return int
     */
    private function addressesBilled(array $request): int
    {
        return count($this->streetsBilled($request));
    }

    /**
     * The first street line of every address a request sent to the billable endpoint, in order:
     * one entry per billed address, so a repeat is visible as a repeat rather than only as a
     * total.
     *
     * @param array $request Request harness from createRequest().
     * @return string[]
     */
    private function streetsBilled(array $request): array
    {
        $streets = [];
        foreach ($request['requests'] as $payload) {
            foreach ((array)($payload['Addresses'] ?? []) as $address) {
                $streets[] = (string)(((array)$address)['Address1'] ?? '');
            }
        }

        return $streets;
    }

    /** Number of billable Loqate Cleansing requests a request harness issued. */
    private function apiCallCount(array $request): int
    {
        return count($request['requests']);
    }

    /** The BATCH verdict cache as currently held in a request's session. */
    private function batchStore(array $request): array
    {
        $store = $request['session'][self::BATCH_VERIFY_CACHE_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    /** The SINGLE-address verdict cache as currently held in a request's session. */
    private function singleStore(array $request): array
    {
        $store = $request['session'][self::VERIFY_CACHE_SESSION_KEY] ?? [];

        return is_array($store) ? $store : [];
    }

    /**
     * Validator::BATCH_VERIFY_CACHE_LIMIT, read from the production class rather than mirrored,
     * because these tests are about what happens on a file LARGER than it: a mirrored literal
     * that drifted would leave them measuring the easy case instead.
     *
     * @return int
     */
    private function batchCacheLimit(): int
    {
        $reflection = new ReflectionClass(Validator::class);
        if (!$reflection->hasConstant('BATCH_VERIFY_CACHE_LIMIT')) {
            $this->fail(
                'Validator::BATCH_VERIFY_CACHE_LIMIT is not defined: the session verdict store must be '
                . 'bounded, or an import can inflate the customer session without limit.'
            );
        }

        return (int)$reflection->getConstant('BATCH_VERIFY_CACHE_LIMIT');
    }
}
