<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Model\Config\Source\AddressQualityIndex;
use Loqate\ApiIntegration\Plugin\Admin\ValidateImportAddress;
use Loqate\ApiIntegration\Test\Support\ProductionSerializerDouble;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
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
 * could not be read, or a threshold that could not be read, produces a rejection that is a FAULT
 * REPORT rather than a verdict - so it is remembered nowhere, not even for the identical address
 * in the same run. One connector fault or one bad credential must not brand every matching row
 * in the file invalid for the rest of the run. That is what
 * testAnUnreadableQualityIndexIsRememberedNowhereSoTheRowIsBilledAgain() and
 * testAnUnreadableThresholdRejectsEveryRowAndIsRememberedNowhere() hold, and it is the assertion
 * to look at first if a dedupe change ever makes the headline test cheaper.
 *
 * THE TWO FAULTS COST DIFFERENT AMOUNTS, and the difference is the point rather than an
 * inconsistency. An unreadable RESPONSE is re-billed on the repeat, because only Loqate can
 * settle that row and asking again is the cheaper mistake. An unreadable THRESHOLD is billed
 * NOTHING, ever, because nothing Loqate could answer would change the outcome: the bar cannot be
 * read, so every row is refused before the request is composed. "Remembered nowhere" is the
 * same rule in both cases; what differs is whether buying an answer could tell us anything.
 *
 * Most counts asserted here are ADDRESSES BILLED - count($payload['Addresses']) summed over every
 * connector invocation - and not requests, because that is what the invoice is. The pre-flight
 * tests also assert the REQUEST count, which is a stronger claim than zero billed addresses: an
 * empty 'Addresses' payload sent to a billable endpoint bills nothing and is still a round trip
 * paid for and discarded.
 */
class ValidatorImportRunDedupeTest extends TestCase
{
    /**
     * The serializer double, shared with every other harness that reads a serialised payload
     * back. What it buys THIS class: the session verdict store is read through it on every
     * lookup, so a lenient double would make getCachedBatchVerifyResult()'s catch block
     * unreachable from here and an import meeting a truncated payload would fatal in production
     * while this file stayed green.
     */
    use ProductionSerializerDouble;

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

    /** Session data key the captured-address store lives under. */
    private const CAPTURED_ADDRESSES_SESSION_KEY = 'captured_addresses';

    /** Config path of the threshold batch verdicts are judged against. */
    private const AQI_CONFIG_PATH = 'loqate_settings/address_settings/address_quality_index';

    /**
     * Rows per verification batch, as Plugin\Admin\ValidateImportAddress::afterValidateData()
     * chunks an import file, and the size every fixture in this file is laid out around: the
     * headline file's three chunks and its "repeats across chunk boundaries only" precondition are
     * both arithmetic on this number.
     *
     * It is NOT the authority for that number - importChunkSize() reads the plugin's own literal,
     * and fails the test if the two ever disagree. Written here as well because the layouts above
     * cannot follow a change silently: if the plugin chunks differently, the fixtures have to be
     * re-thought by a person, so the divergence must stop the suite rather than quietly re-scale it.
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
     * appears twice inside ONE chunk. All k copies of an address in a single batch are sent,
     * because nothing is written until the response comes back - that is LOQ-17015, a separate
     * ticket with its own arithmetic (the row-count guard in verifyMultipleAddresses() depends
     * on one response row per sent item), and pulling it into this measurement would make the
     * headline number unmeetable for a reason this ticket is not about. That bound is measured
     * on its own fixture by
     * testEveryCopyInTheChunkAnAddressFirstAppearsInIsBilledAndEveryLaterChunkIsFree().
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
     * An UNREADABLE THRESHOLD rejects every row, is remembered nowhere - and is NOT PAID FOR.
     *
     * The threshold side of the "never remember a verdict we could not read" rule, and the one
     * that is easier to get wrong, because the rejection LOOKS like a verdict: the response was
     * perfectly readable, and the only unreadable thing is the merchant's own configuration. It
     * is still not a verdict. A value nobody can read cannot be said to admit or refuse any
     * address, so remembering the refusal would keep refusing rows after the configuration was
     * corrected - within the run for the run-scoped memory, and for the whole session had it
     * been stored there.
     *
     * THE BILLED COUNT IS ZERO, AND THAT IS THE CORRECTION LOQ-17148 MAKES HERE. This test used
     * to assert FOUR - two rows, sent again in the repeat chunk - on the reasoning that a
     * rejection nobody could read must be re-earned rather than replayed. That reasoning is
     * sound for an unreadable RESPONSE, where only Loqate can settle the row (see
     * testAnUnreadableQualityIndexIsRememberedNowhereSoTheRowIsBilledAgain(), which still bills
     * twice). It is wrong for an unreadable THRESHOLD, because nothing Loqate could answer would
     * change the outcome: the bar cannot be read, so EVERY row is refused whatever comes back,
     * and the answer is settled by the configuration before the request is composed. Paying per
     * address for it is paying for a guaranteed refusal - on every row of the file, on every
     * "Check Data" click, for as long as the setting stays broken. So the threshold is read once
     * before payload assembly and the whole file is answered without a request.
     *
     * The other three assertions are unchanged, and they are what stops "spend nothing" being
     * implemented as "remember the refusal": both chunks still reject both rows, and the session
     * store is still empty.
     *
     * 'E' is the AQI under test on purpose: the worst grade Loqate returns is the value that
     * must never pass a threshold nobody can read, and a test using 'A' would still pass if the
     * guard regressed to comparing against the shipped default. It is now also the grade that is
     * never asked for.
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
            0,
            $this->addressesBilled($request),
            'NOT ONE address may be billed. The answer is decided by the CONFIGURATION before the request '
            . 'is composed - a threshold nobody can read refuses every row whatever Loqate replies - so '
            . 'buying the reply is paying, per address and per "Check Data" click, for a refusal that was '
            . 'already made. Contrast an unreadable RESPONSE, which IS re-billed on its repeat '
            . '(testAnUnreadableQualityIndexIsRememberedNowhereSoTheRowIsBilledAgain()): there only Loqate '
            . 'can settle the row, so paying to ask again is the cheaper mistake. Here nobody can.'
        );
        $this->assertSame(
            [],
            $this->batchStore($request),
            'And nothing may be written to the session store, where it would outlive the request entirely.'
        );
        $this->assertSame(
            [],
            $this->runScopedVerdicts($request),
            'NOR to the run-scoped map, read here DIRECTLY and not inferred. That directness is the point: '
            . 'while this test asserted four billed addresses, the re-billing was itself the evidence that '
            . 'nothing had been remembered. At zero there is no such evidence left - a refusal that HAD '
            . 'been remembered would produce the same zero and the same [false, false] - so the memory is '
            . 'read instead. It has to stay empty, or a merchant who corrects the setting mid-run keeps '
            . 'being refused by a rule that no longer applies.'
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
     * An unreadable threshold issues NO REQUEST AT ALL, and says so EXACTLY ONCE PER BATCH.
     *
     * The wire-and-diagnostic half of the test above, which measures verdicts and memories. Two
     * separate guarantees are pinned here and both are new in LOQ-17148:
     *
     * NO REQUEST, not merely no billed addresses. The distinction is not academic: an
     * implementation that assembled the payload and then answered every slot false before
     * sending would satisfy a per-address count of zero taken from the payloads while still
     * making a call, and the endpoint is billable per address it carries - an empty
     * 'Addresses' list to a billable endpoint is the worst version of that, since it pays for a
     * round trip and discards the answer. The threshold is therefore read BEFORE payload
     * assembly, and this asserts the request count itself.
     *
     * EXACTLY ONE LINE PER BATCH, which is a frequency and not just a presence. Zero would leave
     * the merchant with a whole file of "Invalid address at row #N" and nothing anywhere saying
     * their own setting is a bar no address can clear - the failure this line exists to prevent.
     * One per ROW would bury it: a 100-row chunk would write 100 identical lines and a 10,000-row
     * file 10,000, so the signal is lost in its own volume and the log costs real money to ship.
     * The line is asserted BYTE FOR BYTE, because it is a merchant-facing artefact that support
     * reads out of a log file: rewording it is a decision to make deliberately, which means
     * updating this expectation, not one to have absorbed by a test matching a fragment.
     *
     * WHAT THE LINE MAY NOT SAY, and why the wording moved. It used to end "rejecting the
     * address", which the response-side caller could say truthfully and this pre-flight cannot:
     * the threshold is read ONCE PER BATCH before any row is decided, so on a batch that refuses
     * nothing the line named an outcome that never happened and sent support looking for refused
     * rows that do not exist. It now reports only the configuration. That batch is pinned by
     * testAnAllCapturedBatchUnderAnUnreadableThresholdStillReportsTheConfigurationOnce().
     *
     * @param mixed $threshold Configured address_quality_index that cannot be read as a grade.
     * @param string $why What this case protects, quoted into the failure message.
     */
    #[DataProvider('unreadableThresholdProvider')]
    public function testAnUnreadableThresholdIssuesNoRequestAndSaysSoOncePerBatch($threshold, string $why): void
    {
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

        $this->assertSame(
            0,
            $this->apiCallCount($request),
            sprintf(
                'A batch under a threshold nobody can read must issue NO REQUEST - not an empty '
                . '\'Addresses\' payload to a billable endpoint, and not one carrying rows whose verdict '
                . 'the configuration has already settled. This case pins that %s.',
                $why
            )
        );
        $this->assertSame(
            [false, false],
            array_values($firstChunk),
            'And every row is still answered, and still answered false: spending nothing may not become '
            . 'reporting nothing. A row with no verdict at all would be dropped from the merchant\'s '
            . 'report entirely, or - on the admin order path - accepted unverified.'
        );
        $this->assertSame(
            [$this->expectedBrokenThresholdLine($threshold)],
            $this->thresholdLogLines($request),
            'ONE line, and this exact line. It is the only signal a merchant has that their own quality '
            . 'bar - not their data - is a bar nothing can clear, so it must name the offending value and '
            . 'its type and say what the accepted values are, and it must claim no more than that. Zero '
            . 'lines is the defect this replaces; one per ROW would bury the signal under a line per '
            . 'import row.'
        );

        $request['validator']->verifyMultipleAddresses($rows, false);

        $this->assertSame(
            0,
            $this->apiCallCount($request),
            'The repeat chunk must not buy the answer either. Nothing was remembered - there is nothing a '
            . 'broken threshold could legitimately remember - so this has to be re-decided each time, and '
            . 're-deciding it costs nothing.'
        );
        $this->assertSame(
            2,
            count($this->thresholdLogLines($request)),
            'Once per BATCH: the second chunk gets its own line, because the merchant\'s bar is still '
            . 'broken and each verified batch is an occasion on which it mattered. Two chunks, two lines - '
            . 'not four (one per row) and not one (a per-instance latch that would go quiet on the very '
            . 'file that needed the warning most).'
        );
    }

    /**
     * ...and the line still fires on a file Loqate answers with NO MATCHES AT ALL.
     *
     * THE SECOND DEFECT LOQ-17148 CLOSES, and it is easy to miss because it looks like the same
     * test as the one above. It is not: the old code read the threshold only from
     * checkQualityIndex(), on the RESPONSE side, and that method returns on an unreadable AQI
     * BEFORE it ever looks at the threshold. "Matches":[] is Loqate's ordinary answer for an
     * address it could not match, and on a poor file that is most of the file - so a merchant
     * whose threshold was broken AND whose file matched badly got every row rejected and NOT ONE
     * line telling them why. The two faults hid each other, and the worse-configured the install,
     * the quieter it was.
     *
     * The response stub below is now never consulted, which is the point rather than a
     * redundancy: the guarantee is that the diagnostic no longer depends on what comes back,
     * because nothing comes back. If the pre-flight is ever narrowed or moved after the request,
     * this fixture is the one that goes quiet again.
     */
    public function testAnUnreadableThresholdStillReportsItselfOnAFileLoqateMatchesNowhere(): void
    {
        $request = $this->createRequest(
            null,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => 'zzz'])),
            // "Matches":[] for every address - the shape that survives the connector's
            // array_column() with the row count preserved, so it reaches the attribution loop
            // as an AQI of null and used to return from checkQualityIndex() before the
            // threshold was read.
            static fn ($payload): array => array_map(
                static fn (): array => [],
                (array)$payload['Addresses']
            )
        );
        $rows = [$this->distinctAddress(1), $this->distinctAddress(2)];

        $verdicts = $request['validator']->verifyMultipleAddresses($rows, false);

        $this->assertSame(
            [$this->expectedBrokenThresholdLine('zzz')],
            $this->thresholdLogLines($request),
            'A file Loqate matches nowhere must STILL produce the broken-threshold line. It used to '
            . 'produce none: the unreadable AQI returned from checkQualityIndex() before the threshold '
            . 'was ever read, so the one diagnostic that would have told the merchant their setting was '
            . 'the problem was suppressed by exactly the kind of file that makes it hardest to guess.'
        );
        $this->assertSame([false, false], array_values($verdicts), 'Every row is still rejected.');
        $this->assertSame(
            0,
            $this->apiCallCount($request),
            'And it is reported without asking Loqate anything, so the diagnostic no longer depends on '
            . 'the answer at all.'
        );
    }

    /**
     * A CAPTURED address still passes under an unreadable threshold, and still costs no request.
     *
     * A DELIBERATE DEVIATION, pinned so it is a decision rather than an accident of where the
     * guard was pasted. The pre-flight sits AFTER the captured-address short-circuit and not
     * before it, so an address the Loqate lookup itself authored is answered true even while the
     * merchant's quality bar is unreadable. Two reasons, both about not making things worse: a
     * captured address never consults the AQI at all - it is trusted because Loqate produced it,
     * not because it was graded - so refusing it would be a NEW refusal on a batch that passes
     * today, on the one path where the module is most confident; and the alternative reading,
     * "the configuration is broken so refuse everything", would turn a mis-set dropdown into a
     * total block of admin order create for addresses picked from the lookup.
     *
     * The mixed batch is what makes it a deviation rather than a special case: the captured row
     * passes and the typed row beside it is refused, in ONE call, so the guard demonstrably runs
     * per row and after the short-circuit rather than as a whole-batch bail-out.
     *
     * BEING MIXED IS ALSO ITS LIMIT, which is why it has a sibling. One row IS refused here, so
     * this fixture cannot see anything the broken-threshold log line claims about refusals - the
     * retired "rejecting the address" wording was accidentally true on exactly this batch. The
     * all-captured batch, where the line fires with nothing refused at all, is pinned by
     * testAnAllCapturedBatchUnderAnUnreadableThresholdStillReportsTheConfigurationOnce().
     */
    public function testACapturedAddressStillPassesUnderAnUnreadableThresholdAndStillCostsNoRequest(): void
    {
        $captured = $this->distinctAddress(1);
        $typed = $this->distinctAddress(2);

        $request = $this->createRequest(
            null,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => 'zzz'])),
            static fn ($payload): array => array_map(
                static fn (): array => [['AQI' => 'E', 'AVC' => self::CARRIED_AVC]],
                (array)$payload['Addresses']
            )
        );
        $request['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = [self::capturedEntry($captured)];

        $verdicts = $request['validator']->verifyMultipleAddresses(
            ['captured' => $captured, 'typed' => $typed],
            true
        );

        $this->assertSame(
            ['captured' => true, 'typed' => false],
            $verdicts,
            'The captured address must still PASS. The pre-flight threshold guard is deliberately placed '
            . 'AFTER the captured-address short-circuit: a captured address never consults the AQI, so '
            . 'refusing it would be a brand-new refusal on a batch that passes today, and a mis-set '
            . 'dropdown would become a total block of every address picked from the Loqate lookup. The '
            . 'typed row beside it is still refused, which is what shows the guard runs per row rather '
            . 'than bailing the batch out.'
        );
        $this->assertSame(
            0,
            $this->apiCallCount($request),
            'And nothing is bought either way: the captured row needs no verification and the typed row\'s '
            . 'answer is already settled.'
        );
        $this->assertSame(
            [],
            $this->batchStore($request),
            'Nor is the captured pass written to the verdict store: it is not a verdict, it is a bypass, '
            . 'and it is keyed under a threshold nobody can read.'
        );
    }

    /**
     * A batch whose EVERY row is a captured address, under an unreadable threshold: every row
     * passes, nothing is bought - and the broken-quality-bar line is written anyway, saying only
     * what the configuration is.
     *
     * THE CASE THAT PRODUCED THE REWORD, and the reason it is pinned in its own fixture rather
     * than left to
     * testACapturedAddressStillPassesUnderAnUnreadableThresholdAndStillCostsNoRequest(): that
     * batch is MIXED, so one row IS refused there and the retired sentence "rejecting the address"
     * was accidentally true. Here nothing is refused at all, and the old wording named an outcome
     * that never happened - support would go looking for refused rows that do not exist.
     *
     * THE LINE IS A CONFIGURATION REPORT, WHICH IS WHY ITS PRESENCE IS ASSERTED INDEPENDENTLY OF
     * THE REFUSAL COUNT. Read quickly, "zero rejections, one warning" looks like the bug rather
     * than the fix. It is the fix: the threshold pre-flight reads the setting ONCE PER BATCH,
     * before any row is decided, and a merchant whose quality bar cannot be read needs to be told
     * so on every batch that ran under it - not only on the batches that happened to contain a row
     * the fault could bite. Both halves are therefore asserted together and neither is derived
     * from the other: [true, true] AND exactly one line.
     *
     * IT IS ALSO THE FIXTURE THAT FAILS IF THE LINE IS EVER MADE CONDITIONAL on at least one row
     * having been refused - the alternative fix the coder rejected. That would make a
     * CONFIGURATION diagnostic depend on the composition of whatever batch happened to run, and on
     * this path specifically on the shopper's captured-address session: an admin creating an order
     * entirely from Loqate-picked addresses would silence the only signal their setting is broken,
     * and it would stay silent until an uncaptured row happened along. Same hole as the
     * all-empty-'Matches' file this branch closed, through a different door.
     *
     * The negative assertion on 'rejecting the address' is deliberately cheap and deliberately
     * redundant with the byte-for-byte expectation: a future edit that loosens the exact-match
     * helper - for a grade list change, say - must not also quietly re-admit a sentence that
     * claims a refusal nobody made.
     */
    public function testAnAllCapturedBatchUnderAnUnreadableThresholdStillReportsTheConfigurationOnce(): void
    {
        $firstCaptured = $this->distinctAddress(1);
        $secondCaptured = $this->distinctAddress(2);

        $request = $this->createRequest(
            null,
            new ArrayObject(self::configWith([self::AQI_CONFIG_PATH => 'zzz'])),
            static fn ($payload): array => array_map(
                static fn (): array => [['AQI' => 'E', 'AVC' => self::CARRIED_AVC]],
                (array)$payload['Addresses']
            )
        );
        $request['session'][self::CAPTURED_ADDRESSES_SESSION_KEY] = [
            self::capturedEntry($firstCaptured),
            self::capturedEntry($secondCaptured),
        ];

        $verdicts = $request['validator']->verifyMultipleAddresses(
            [$firstCaptured, $secondCaptured],
            true
        );

        $this->assertSame(
            [true, true],
            array_values($verdicts),
            'Fixture precondition AND guarantee in one: EVERY row of this batch is a captured address, so '
            . 'the captured short-circuit answers all of them and the threshold guard refuses NOTHING. If '
            . 'this ever reads [false, false] the pre-flight has moved ahead of the captured guard, and a '
            . 'mis-set dropdown has become a total block of every address picked from the Loqate lookup.'
        );
        $this->assertSame(
            0,
            $this->apiCallCount($request),
            'And nothing is bought: captured rows need no verification, so there is no payload to assemble '
            . 'and no round trip to pay for.'
        );
        $this->assertSame(
            [$this->expectedBrokenThresholdLine('zzz')],
            $this->thresholdLogLines($request),
            'The broken quality bar is STILL reported, exactly once, on a batch where not one row was '
            . 'refused. This is not "a warning with nothing behind it": the line reports the CONFIGURATION, '
            . 'which is broken whatever this batch contained, and making it conditional on a refusal would '
            . 'silence it for an admin who creates orders entirely from Loqate-picked addresses - until '
            . 'some later row that is not captured finally surfaces a fault that was there all along.'
        );
        $this->assertStringNotContainsString(
            'rejecting the address',
            implode("\n", $this->thresholdLogLines($request)),
            'And it may not claim a refusal that did not happen. This clause is what the line used to end '
            . 'with, and on THIS batch it was simply false - zero rows were rejected. Asserted separately '
            . 'from the exact-match expectation above on purpose: reverting the wording has to fail loudly '
            . 'here too, rather than only through a byte-for-byte helper a later edit might loosen.'
        );
    }

    /**
     * THE LOQ-17015 RESIDUE BOUND, both halves, pinned so it cannot drift back into prose.
     *
     * WHAT THE BOUND IS. An address appearing k times in the chunk where it FIRST appears is
     * billed k TIMES, up to the chunk size; every appearance in any LATER chunk is free. The
     * pre-flight loop runs to completion over the whole chunk before the request is issued, and
     * the only writer to either memory is rememberBatchVerdict(), which runs AFTER the response
     * - so all k copies miss, and all k go on the wire.
     *
     * WHY IT IS PINNED RATHER THAN DESCRIBED. The bound published with the first half of this
     * work said the residue was "ONE duplicate charge per distinct address per run", and
     * labelled that verified. It was not: the two-copy case had been probed and generalised to
     * all n without being run. Two reviewers then disproved it independently BY EXECUTION. A
     * claim about a billing bound that nothing executes is a claim that will be wrong again, so
     * both halves now have a test - three copies, not two, because three is the smallest count
     * that tells "k copies cost k" from "the first copy plus one".
     *
     * BOTH POLARITIES IN ONE FIXTURE, deliberately. A rejected address is remembered only in the
     * RUN map and a passing one in both memories, so an implementation that de-duplicated
     * intra-chunk copies for one polarity and not the other would still satisfy a single-polarity
     * test. Six rows, two distinct addresses, one accepted and one rejected.
     *
     * IF THIS TEST EVER GOES RED BECAUSE THE COUNT DROPPED, that is LOQ-17015 being fixed and it
     * is good news - but read verifyMultipleAddresses()' ACCEPTED LIMITS first: collapsing
     * duplicates into a single payload slot changes the row arithmetic the row-count guard and
     * the positional attribution both depend on, so the dedupe and that guard have to change
     * TOGETHER.
     */
    public function testEveryCopyInTheChunkAnAddressFirstAppearsInIsBilledAndEveryLaterChunkIsFree(): void
    {
        $accepted = $this->distinctAddress(1);
        $rejected = $this->distinctAddress(2);
        $this->apiVerdicts[$rejected['street'][0]] = 'fail';

        $chunk = array_merge(array_fill(0, 3, $accepted), array_fill(0, 3, $rejected));

        $firstChunk = $this->request['validator']->verifyMultipleAddresses($chunk, false);

        $this->assertSame(
            array_merge(
                array_fill(0, 3, $accepted['street'][0]),
                array_fill(0, 3, $rejected['street'][0])
            ),
            $this->streetsBilled($this->request),
            'Read off the WIRE: all THREE copies of each address really were sent, in row order. Nothing '
            . 'is written to either memory until the response comes back, and the pre-flight loop runs to '
            . 'completion over the whole chunk before the request is issued, so every copy misses. That is '
            . 'LOQ-17015, and its bound is the CHUNK SIZE - not one charge per distinct address, which is '
            . 'what an earlier revision of this claim said. The bound AT the chunk size is executed, not '
            . 'argued, by '
            . 'testAFullChunkOfOneAddressIsBilledOncePerRowAndTheChunkAfterItIsFree().'
        );
        $this->assertSame(
            [true, true, true, false, false, false],
            array_values($firstChunk),
            'And every copy is answered under its own row, with the verdict of the address on that row: '
            . 'the row-count guard and the positional attribution both depend on ONE RESPONSE ROW PER SENT '
            . 'ITEM, which is exactly what makes the copies cost what they cost.'
        );

        $laterChunk = $this->request['validator']->verifyMultipleAddresses($chunk, false);

        $this->assertSame(
            6,
            $this->addressesBilled($this->request),
            'THE OTHER HALF: an identical LATER chunk bills NOTHING. By then both memories hold both '
            . 'addresses - the pass in the session store and the run map, the rejection in the run map '
            . 'alone - so all six rows are answered from memory. Six billed addresses in total, not '
            . 'twelve, and the residue is bounded by the chunk an address first appears in.'
        );
        $this->assertSame(
            1,
            $this->apiCallCount($this->request),
            'And it issues no request at all, not even an empty one.'
        );
        $this->assertSame(
            [true, true, true, false, false, false],
            array_values($laterChunk),
            'The replayed chunk must report the same verdicts under its own keys, including the rejections: '
            . 'a de-duplicated row that loses its slot renumbers every import row after it.'
        );
    }

    /**
     * THE LOQ-17015 RESIDUE BOUND AT ITS WORST CASE: a chunk that is nothing but copies of ONE
     * address, as many as the import plugin will ever put in one batch, bills one address PER ROW
     * - and the identical chunk after it bills nothing.
     *
     * WHY THIS EXISTS BESIDE THE THREE-COPY TEST ABOVE. That one proves the SHAPE of the bound
     * ("k copies cost k" rather than "the first copy plus one") on a cheap fixture. It does not
     * prove the SIZE, and the size is the part of the claim that costs money: the published bound
     * says "a chunk of 100 identical rows bills 100", and a suite that only ever runs k=3 leaves
     * the strongest sentence in the documentation argued rather than executed. That is precisely
     * how this series shipped a bound that was wrong by 99x - the two-copy case was probed,
     * generalised to all n in prose, and disproved by execution twice. So the worst case now runs.
     *
     * THE k IS THE CHUNK SIZE ITSELF, READ FROM THE PLUGIN, not a literal repeated here. See
     * importChunkSize(): it takes the number out of
     * Plugin\Admin\ValidateImportAddress::afterValidateData()'s own array_chunk() call, so this
     * test measures the batch production actually assembles. A mirrored literal drifting away from
     * the plugin would leave the worst case unmeasured while looking measured, which is the same
     * failure mode as describing it in prose.
     *
     * BOTH POLARITIES, ONE FULL-SIZE CHUNK EACH, rather than a half-and-half chunk: an accepted
     * address is remembered in both memories and a rejected one only in the run map, so a dedupe
     * that collapsed copies for one polarity and not the other must fail here too - but splitting
     * a single chunk between them would pin k at half the chunk size and stop measuring the worst
     * case, which is the whole point of this fixture.
     *
     * THE WIRE CONTENTS ARE ASSERTED, not just the count. A count says the right number of
     * addresses was billed; the street list in row order says the right ADDRESSES were, in the
     * order the response has to be attributed back to. That is what makes this stronger than a
     * counter, and it is what would catch a dedupe that sent k copies of the wrong row.
     */
    public function testAFullChunkOfOneAddressIsBilledOncePerRowAndTheChunkAfterItIsFree(): void
    {
        $chunkSize = $this->importChunkSize();
        $accepted = $this->distinctAddress(1);
        $rejected = $this->distinctAddress(2);
        $this->apiVerdicts[$rejected['street'][0]] = 'fail';

        $acceptedChunk = array_fill(0, $chunkSize, $accepted);
        $rejectedChunk = array_fill(0, $chunkSize, $rejected);

        $firstAcceptedChunk = $this->request['validator']->verifyMultipleAddresses($acceptedChunk, false);
        $firstRejectedChunk = $this->request['validator']->verifyMultipleAddresses($rejectedChunk, false);

        $this->assertSame(
            array_merge(
                array_fill(0, $chunkSize, $accepted['street'][0]),
                array_fill(0, $chunkSize, $rejected['street'][0])
            ),
            $this->streetsBilled($this->request),
            sprintf(
                'Read off the WIRE at FULL CHUNK SIZE: a chunk of %1$d identical rows bills %1$d addresses, '
                . 'in row order, for each polarity. Nothing is written to either memory until the response '
                . 'comes back and the pre-flight loop runs to completion before the request is issued, so '
                . 'every copy misses. This is the sentence the LOQ-17015 residue bound is published as, and '
                . 'until now nothing executed it at this size - the earlier claim of one duplicate charge '
                . 'per distinct address was wrong here by a factor of %1$d, and was believed because it was '
                . 'only ever argued.',
                $chunkSize
            )
        );
        $this->assertSame(
            array_fill(0, $chunkSize, true),
            array_values($firstAcceptedChunk),
            'Every copy is answered under its own row: the row-count guard and the positional attribution '
            . 'both depend on ONE RESPONSE ROW PER SENT ITEM, which is exactly what makes the copies cost '
            . 'what they cost. A chunk that answered fewer rows than it was given would renumber every '
            . 'import row after it.'
        );
        $this->assertSame(
            array_fill(0, $chunkSize, false),
            array_values($firstRejectedChunk),
            'And the rejected polarity is answered the same way, row for row - the polarity the session '
            . 'store never held, so its copies are the ones only the run map can make free later.'
        );

        $laterAcceptedChunk = $this->request['validator']->verifyMultipleAddresses($acceptedChunk, false);
        $laterRejectedChunk = $this->request['validator']->verifyMultipleAddresses($rejectedChunk, false);

        $this->assertSame(
            2 * $chunkSize,
            $this->addressesBilled($this->request),
            sprintf(
                'THE OTHER HALF, ALSO AT FULL SIZE: repeating both %1$d-row chunks bills NOTHING further. '
                . 'By then the pass is in the session store and the run map and the rejection is in the run '
                . 'map, so all %2$d replayed rows are answered from memory. %2$d billed addresses in total, '
                . 'not %3$d: the residue is bounded by the chunk an address FIRST appears in, whatever the '
                . 'file does afterwards.',
                $chunkSize,
                2 * $chunkSize,
                4 * $chunkSize
            )
        );
        $this->assertSame(
            2,
            $this->apiCallCount($this->request),
            'And the replays issue no request at all - not even an empty \'Addresses\' payload, which would '
            . 'be a round trip to a billable endpoint paid for and discarded.'
        );
        $this->assertSame(
            array_fill(0, $chunkSize, true),
            array_values($laterAcceptedChunk),
            'The replayed accepted chunk still reports every row, under its own key.'
        );
        $this->assertSame(
            array_fill(0, $chunkSize, false),
            array_values($laterRejectedChunk),
            'And so does the replayed rejected chunk: free must not become silent, or a whole chunk of '
            . 'invalid rows vanishes from the merchant\'s report.'
        );
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
                    'Chunk #%d must not repeat an address WITHIN itself. ALL k copies in one batch are '
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
     *     session: ArrayObject, config: ArrayObject, logs: ArrayObject}
     */
    private function createRequest(
        ?ArrayObject $session = null,
        ?ArrayObject $config = null,
        ?callable $respond = null
    ): array {
        $sessionStore = $session ?? new ArrayObject();
        $requests = new ArrayObject();
        $configStore = $config ?? new ArrayObject(self::configWith([]));

        // Every INFO record the Validator wrote, in order. Needed by the pre-flight tests: the
        // "your quality bar is broken" line is the ONLY signal a merchant has that their
        // configuration - and not their file - is what rejected every row, and its FREQUENCY is
        // part of the guarantee (once per verified batch, not once per row and not never).
        $logs = new ArrayObject();
        $logger = $this->createMock(Logger::class);
        $logger->method('info')->willReturnCallback(
            static function ($message, array $context = []) use ($logs) {
                $logs[] = (string)$message;
            }
        );

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

        // Fails the way the PRODUCTION serializer fails - see the trait for why that matters.
        $serializer = $this->createSerializerDouble();

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
            'logs' => $logs,
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

    /**
     * The broken-quality-bar INFO records a request emitted, in order.
     *
     * Filtered rather than taken whole, because the same logger carries unrelated INFO records
     * (a missing API key, a response whose row count could not be attributed) and this file's
     * subject is only the one line a merchant needs to see when their own setting is a quality
     * bar no address can clear. It reports the CONFIGURATION, not what became of any row: the
     * pre-flight that emits it runs once per batch, before a single verdict exists.
     *
     * @param array $request Request harness from createRequest().
     * @return string[]
     */
    private function thresholdLogLines(array $request): array
    {
        return array_values(array_filter(
            iterator_to_array($request['logs']),
            static fn (string $line): bool => str_contains($line, 'address_quality_index')
        ));
    }

    /**
     * The exact line Validator must write for a threshold it cannot read.
     *
     * The accepted grades come from Validator::VALID_QUALITY_INDEXES rather than being spelled
     * out, so a grade added to the module is offered to the merchant in this message without
     * anybody having to remember this file - the WORDING is what is pinned here, not the list.
     *
     * The middle clause is load-bearing and is the reason this helper is worth reading twice: it
     * states what the CONFIGURATION is, and deliberately not what happened to any address. The
     * caller emitting it is a once-per-batch pre-flight that runs before any row is decided, so a
     * sentence naming an outcome can be false - it was, on an all-captured batch, which is what
     * testAnAllCapturedBatchUnderAnUnreadableThresholdStillReportsTheConfigurationOnce() pins.
     * That test also asserts the retired 'rejecting the address' clause is ABSENT, so a revert of
     * the wording fails on its own terms and not only through this byte-for-byte helper.
     *
     * @param mixed $threshold The unreadable configured value.
     * @return string
     */
    private function expectedBrokenThresholdLine($threshold): string
    {
        return sprintf(
            'Loqate: address_quality_index is not a recognised quality index (%s of type %s); '
            . 'no address can pass a quality bar that cannot be read. Set it to one of %s.',
            var_export($threshold, true),
            gettype($threshold),
            implode(', ', Validator::VALID_QUALITY_INDEXES)
        );
    }

    /**
     * A captured-address store entry for a Magento-shaped address, as
     * Helper\Controller::storeCapturedAddress() writes it: the ADDRESS_CAPTURE_MAPPING keys,
     * serialised. Mirrors ValidatorBatchVerifyCacheTest::capturedEntry().
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
     * The RUN-scoped verdict map of a request's Validator, read straight off the instance.
     *
     * The other memory, and the only way to see it. It is never serialised and never written to
     * the session - that mortality is the feature - so unlike the session stores there is no
     * artefact to inspect and no second request that could report on it: a fresh Validator
     * simply has an empty one. Reflection is therefore not a shortcut past a public route, it is
     * the only route. Used where "remembered nowhere" has to be asserted rather than inferred
     * from a billed count, which stopped being evidence once the count under a broken threshold
     * became zero.
     *
     * @param array $request Request harness from createRequest().
     * @return array<string, bool> Batch cache key => remembered verdict.
     */
    private function runScopedVerdicts(array $request): array
    {
        $map = new ReflectionProperty(Validator::class, 'runScopedBatchVerdicts');
        $map->setAccessible(true);

        return (array)$map->getValue($request['validator']);
    }

    /**
     * The number of rows Plugin\Admin\ValidateImportAddress::afterValidateData() puts in ONE
     * verification batch, taken from that method's own array_chunk() call.
     *
     * WHY IT IS READ OUT OF THE PRODUCTION SOURCE. The LOQ-17015 residue bound is a claim about
     * the worst case a real import can produce - "a chunk of N identical rows bills N" - so the N
     * a test pins has to be the N the plugin actually assembles. A literal repeated in this file
     * would keep passing while the plugin moved, leaving the worst case unmeasured but looking
     * measured, which is the same failure that shipped a bound wrong by 99x.
     *
     * WHY BY SOURCE SCAN. The chunk size is an inline literal in a method body: there is no
     * constant to reflect on and no accessor to call, and this file may not add one, so the source
     * the code is compiled from is the only place the value exists. The scan is deliberately
     * narrow - one array_chunk() call with an integer literal - and anything else FAILS rather
     * than guesses, because a wrong guess here silently weakens the only test of the worst case.
     *
     * @return int
     */
    private function importChunkSize(): int
    {
        $pluginFile = (new ReflectionClass(ValidateImportAddress::class))->getFileName();
        $this->assertIsString(
            $pluginFile,
            'Plugin\Admin\ValidateImportAddress must be a file-backed class: its chunk size is an inline '
            . 'literal, so the source file is the only place the value can be read from.'
        );

        $matched = preg_match_all(
            '/array_chunk\s*\(\s*\$\w+\s*,\s*(\d+)\s*\)/',
            (string)file_get_contents($pluginFile),
            $matches
        );
        $sizes = $matched ? array_values(array_unique(array_map('intval', $matches[1]))) : [];

        $this->assertCount(
            1,
            $sizes,
            'Plugin\Admin\ValidateImportAddress::afterValidateData() must chunk the import file with '
            . 'exactly one array_chunk() call taking an integer literal, or this helper cannot tell what '
            . 'size a real batch is. If the plugin now computes the size, or chunks in more than one place, '
            . 'read it from there instead - do NOT fall back to a literal in the test, because the worst '
            . 'case would then be measured at a size production never produces.'
        );
        $this->assertSame(
            self::IMPORT_CHUNK_SIZE,
            $sizes[0],
            sprintf(
                'The plugin now chunks at %d, and every fixture in this class is laid out for %d - the '
                . 'headline file\'s three chunks, and its assertion that repeats fall only across chunk '
                . 'boundaries. That layout has to be re-thought by a person rather than re-scaled silently, '
                . 'so the disagreement stops the suite here.',
                $sizes[0],
                self::IMPORT_CHUNK_SIZE
            )
        );

        return $sizes[0];
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
