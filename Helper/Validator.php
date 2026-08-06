<?php

namespace Loqate\ApiIntegration\Helper;

use Loqate\ApiConnector\Client\Verify;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Class Validator
 */
class Validator
{
    const ADDRESS_MAPPING = [
        'street' => 'Address',
        'street_1' => 'Address1',
        'street_2' => 'Address2',
        'city' => 'Address3',
        'region' => 'Address4',
        'postcode' => 'PostalCode',
        'country_id' => 'Country'
    ];

    const ADDRESS_CAPTURE_MAPPING = [
        'Address1' => 'Line1',
        'Address2' => 'Line2',
        'Country' => 'CountryIso2',
        'PostalCode' => 'PostalCode',
        'Address3' => 'City',
        'Address4' => 'ProvinceName'
    ];

    /**
     * Fields the captured-address signature projects, in key order.
     *
     * Fixed by the captured-address store this signature is compared against:
     * Helper\Controller::storeCapturedAddress() writes exactly
     * ADDRESS_CAPTURE_MAPPING's keys, which carry no 'Address' at all, so no field may
     * be added here (see buildAddressSignature()). The verify cache key extends this
     * list instead - see verifyCacheSignatureFields() for the invariant that governs it.
     */
    private const CAPTURED_SIGNATURE_FIELDS = ['Address1', 'Address2', 'Address3', 'PostalCode', 'Country'];

    /**
     * The region field, named once here rather than spelled out at each builder.
     *
     * It is the one field the shared base (verifySignatureFields()) leaves out, and the key
     * appends it last, in a segment of its own - see verifyCacheSignatureFields().
     */
    private const COUNTY_FIELD = 'Address4';

    /**
     * Every value address_quality_index is allowed to hold, BEST ('A') to WORST ('E') - the
     * Cleansing API's own grade order, and since LOQ-17148 the order the merchant's dropdown
     * lists them in, so this is not a cosmetic ordering.
     *
     * checkQualityIndex() compares the response AQI against the threshold with <=, which is
     * a STRING comparison, so an unrecognised threshold does not fail closed - anything
     * sorting above 'E' passes every address. This list is what makes that comparison
     * meaningful; see checkQualityIndex() for the failure it prevents.
     *
     * PUBLIC BECAUSE THE ADMIN FIELD'S OPTION LIST IS DERIVED FROM IT (LOQ-17148).
     * Model\Config\Source\AddressQualityIndex - the source_model behind the
     * address_quality_index select in etc/adminhtml/system.xml - reads its option VALUES from
     * here and supplies only the labels. It used to spell A-E out itself, which was a second
     * copy of this list free to drift: a grade added here but not there would be honoured by
     * the verifier and unreachable in the form, and a grade added there but not here would be
     * selectable and would then reject EVERY address, reported to the merchant row by row as
     * invalid data rather than as a bad setting. Deriving one from the other makes both
     * directions impossible rather than merely tested (they are tested too, in
     * Test\Unit\Model\Config\Source\AddressQualityIndexTest).
     */
    public const VALID_QUALITY_INDEXES = ['A', 'B', 'C', 'D', 'E'];

    /**
     * Version of the verify caches' KEY SCHEME. Stamped into every verdict written by
     * storeVerifyResult() AND storeBatchVerifyResult(), and required to match on every read
     * (getCachedVerifyResult(), getCachedBatchVerifyResult()); an entry stamped with anything
     * else is discarded.
     *
     * A session outlives a deploy, so without this a payload written under an older key
     * shape would be answered as though its key named the address the CURRENT shape derives
     * from that key. Bump it whenever buildVerifyCacheSignature() or either cache's key
     * builder changes what a key means: stale entries are then re-verified once, which is the
     * safe direction.
     *
     * ONE version covers BOTH caches because both key builders sit on the same signature -
     * buildVerifyCacheSignature() - so any change to what a key means changes it for both at
     * once. A second constant would be two things to bump and one of them would be forgotten.
     *
     * An earlier revision carried this on the single-address cache only, reasoning that the
     * batch payload's distinct shape (['valid' => ...] against ['error' => ...]) already
     * guards the two caches from being read for each other. True, and unrelated: that guard
     * is about CROSS-CACHE conflation, while this stamp is about CROSS-DEPLOY staleness. The
     * batch cache needed it more, not less - storeBatchVerifyResult() stores only PASSES, so
     * every stale batch replay is a false ACCEPT, where the single-address cache stores
     * rejections too and can at worst replay a stale refusal.
     */
    private const VERIFY_KEY_SCHEMA_VERSION = 1;

    /**
     * Session data key holding the verify verdict cache (LOQ-16969).
     *
     * A verdict is customer data, so it lives in the per-shopper customer session
     * and nowhere else: CacheInterface, Registry and static properties are all
     * process- or install-wide and would serve one shopper's verdict to another.
     * Entries are additionally namespaced per store view and per resolved AVC threshold,
     * see buildVerifyCacheKey().
     *
     * "Per-shopper" is enforced, not merely assumed: a PHP session survives a login
     * (session_regenerate_id() changes the ID and keeps the data), so this attribute is
     * reached through ShopperScopedSessionStores, which flushes it whenever the logged-in
     * customer changes (LOQ-16978).
     *
     * An ALIAS of ShopperScopedSessionStores::VERIFY_CACHE_SESSION_KEY, kept so every existing
     * reference to Validator::VERIFY_CACHE_SESSION_KEY still resolves. The literal lives on
     * the guard because the guard is what enforces this attribute's lifetime, and holding
     * it here made the dependency circular - the guard's flush list pointed at this class
     * while this class constructs the guard.
     */
    const VERIFY_CACHE_SESSION_KEY = ShopperScopedSessionStores::VERIFY_CACHE_SESSION_KEY;

    /**
     * Maximum number of verdicts kept per session, oldest evicted first.
     *
     * Bounded so the cache cannot inflate the session payload, and small because this is
     * the INTERACTIVE path: one shopper checking out replays a handful of addresses across
     * the call paths listed on verifyAddress(), so 50 distinct addresses is already far
     * beyond a realistic session. Helper\Controller::CAPTURED_ADDRESSES_LIMIT now bounds
     * the older captured-address store at the same figure and for the same reasons
     * (LOQ-16978); self::BATCH_VERIFY_CACHE_LIMIT is the one that is deliberately larger,
     * because only that path has to hold a 100-row import chunk.
     */
    const VERIFY_CACHE_LIMIT = 50;

    /**
     * Session data key holding the BATCH verdict cache (LOQ-16976).
     *
     * A PHYSICALLY SEPARATE session attribute from self::VERIFY_CACHE_SESSION_KEY - not a
     * prefix inside that array - because the two caches hold verdicts of different KINDS
     * that must never answer each other's lookups. verifyAddress() judges an address
     * against the AVC thresholds (checkAVCStatus()); verifyMultipleAddresses() judges it
     * against the address quality index (checkQualityIndex()). The thresholds differ, so
     * the namespacing fingerprints differ (resolveComparerAvcString() versus
     * resolveQualityIndexThreshold()), and the stored shapes differ ('error' versus
     * 'valid'). Sharing one array would leave conflation one missing-prefix bug away, and
     * a key collision there would let an AVC verdict satisfy an AQI check - silently
     * bypassing a threshold the merchant configured. Two attributes make that
     * structurally impossible rather than merely unlikely, and cost one extra session key.
     *
     * Same lifetime rules as the single-address cache: a verdict is customer data, so it
     * lives in the per-shopper customer session and nowhere process- or install-wide, and
     * it is reached through ShopperScopedSessionStores so that it is flushed when the logged-in
     * customer changes (LOQ-16978) - subject to the ACCEPTED LIMITS on that class, which
     * name THIS attribute specifically: it is written only from adminhtml, where the
     * customer session carries no customer id, so the guard's flush is a no-op for it and
     * an admin-user swap inside one browser session is not covered.
     *
     * An ALIAS of ShopperScopedSessionStores::BATCH_VERIFY_CACHE_SESSION_KEY, for the same
     * dependency-direction reason as self::VERIFY_CACHE_SESSION_KEY above.
     */
    const BATCH_VERIFY_CACHE_SESSION_KEY = ShopperScopedSessionStores::BATCH_VERIFY_CACHE_SESSION_KEY;

    /**
     * Maximum number of batch verdicts kept per session, oldest evicted first.
     *
     * Deliberately larger than self::VERIFY_CACHE_LIMIT because this path is BATCHED:
     * customer import verifies in chunks of 100 rows
     * (Plugin\Admin\ValidateImportAddress::afterValidateData()), so any limit below 100 could
     * not hold even one chunk - the eviction would discard a chunk's earliest rows before the
     * chunk finished, and re-running a file that FITS WITHIN THIS LIMIT would re-bill them.
     * 200 holds two full chunks while still bounding the session payload, which is the whole
     * point of having a limit at all (see self::VERIFY_CACHE_LIMIT and
     * Helper\Controller::CAPTURED_ADDRESSES_LIMIT, both 50 because no import writes to
     * them).
     *
     * READ THAT PARAGRAPH AS A FLOOR ON THE CONSTANT, NOT AS A SAVING ON THE IMPORT PATH.
     * It is why >= 100 is asserted, and it holds only for a file whose whole working set
     * fits inside the limit; for a file LARGER than the limit it claims nothing, because
     * FIFO eviction leaves close to zero hits however the constant is chosen.
     *
     * WHAT DEDUPES THE IMPORT PATH IS NOT THIS STORE (LOQ-17148): a run chunks at 100 rows and
     * verifies every chunk INSIDE ONE PHP REQUEST, so the lifetime that matters there is the RUN,
     * and that is served by the run-scoped map on this instance - self::$runScopedBatchVerdicts,
     * both polarities, unbounded by this limit. Before it, a REJECTED address was re-sent, and
     * re-billed (the Cleansing API bills per ADDRESS), in every chunk it appeared in, because
     * this store caches PASSES ONLY (storeBatchVerifyResult()) and address_quality_index ships as
     * 'A' (etc/config.xml), the strictest grade - so on a default install most rows fail and were
     * never cacheable here at all. The saving is what the file's duplicate distribution makes it,
     * so the only figure quoted here is one that can be re-run: on the acceptance fixture in
     * ValidatorImportRunDedupeTest (260 rows, 210 distinct, repeats across chunk boundaries) it
     * is 210 billed addresses instead of 260, i.e. 19.2%.
     *
     * THIS STORE IS DELIBERATELY UNCHANGED BY LOQ-17148 - passes only, FIFO, 200. Four apparent
     * improvements were considered and refused; every one of them looks obvious, so the
     * DECISIONS are recorded here and the arithmetic behind them in CHANGELOG.md under
     * LOQ-17148's known limitations, in ONE place rather than restated in two:
     *  - CACHING FAILURES HERE IS A BILLING REGRESSION on the target workload, not an omission:
     *    eviction is FIFO and bounded, so failures crowd out the sparse passes this store
     *    manages to keep, and it is never better for a file larger than this limit. (An earlier
     *    revision of this docblock promised LOQ-17148 would deliver exactly that. It was wrong,
     *    and this line is the correction.) It would also strand a merchant whose corrected file
     *    or loosened threshold kept being answered from a rejection that outlived the request.
     *  - "RETAIN ON FULL" (freeze instead of evicting) is refused: one Check Data click on a
     *    200-row file would fill the store for the rest of that admin's browser session -
     *    adminhtml never flushes it, see ShopperScopedSessionStores' ACCEPTED LIMITS -
     *    destroying the admin-order-create win below, which is what LOQ-16976 delivered.
     *  - SIZING THE STORE TO THE FILE is refused on session I/O: Magento reads and writes the
     *    WHOLE session on every request, so a store sized to a large import is paid for on
     *    every unrelated admin page load, to serve a re-run that may never happen.
     *  - A NON-SESSION STORE - Magento\Framework\App\CacheInterface, keyed on the same
     *    threshold- and store-view-namespaced hash buildBatchVerifyCacheKey() already builds -
     *    is named because it is the ONE option class that could move the CLI/cron/programmatic
     *    figure below, which no session variant can. Refused, not overlooked, and here is the
     *    risk being declined: a verdict in a shared cache is not shopper-specific, so it is
     *    readable and replayable by any other shopper, any other admin user, and on shared
     *    infrastructure any other install on the same backend - a bypass licence handed across
     *    identities, the leak LOQ-16978 exists to close, through a store
     *    ShopperScopedSessionStores cannot see. Doing it safely needs an identity in the key, a
     *    tag and lifetime policy, and a decision on whether an address verdict may live outside
     *    the session at all. Its own ticket, tracked separately - record the id here when it is
     *    raised.
     *
     * RESIDUAL EXPOSURE, stated plainly rather than left to be rediscovered from an invoice.
     * CROSS-RUN dedupe is not delivered for a file with more than this many distinct addresses:
     * a re-run in the same browser session re-bills essentially every row, a sequential cyclic
     * scan through a smaller FIFO cache having a ~0% hit rate. For a programmatic, CLI or
     * cron-driven import it is 0% and no cache SIZE changes that - each process starts a fresh
     * session id - so only the non-session store refused above could help, at the price stated
     * there. Raising this constant fixes neither case.
     *
     * NOT THE SAME TICKET AS INTRA-CHUNK DUPLICATES, AND LOQ-17015 IS NOT CLOSED BY THIS WORK.
     * LOQ-17148 dedupes ACROSS the chunks of one run and NOT WITHIN a chunk: EVERY COPY AFTER
     * THE FIRST IS BILLED WITHIN THE CHUNK IN WHICH THE ADDRESS FIRST APPEARS (nothing is
     * remembered until the response returns, so all of them miss in the same pre-flight pass),
     * and every appearance in any LATER chunk is free. The residue is bounded by the CHUNK SIZE
     * - 100 identical rows in one chunk bill 100 - not by one charge per distinct address,
     * which an earlier revision of this docblock claimed. See the ACCEPTED LIMITS on
     * verifyMultipleAddresses() for what fixing it must not break.
     *
     * Where this cache actually pays for itself is ADMIN ORDER CREATE: two addresses per
     * submission, re-submitted after a validation bounce or an unrelated form error, is
     * two billed addresses instead of two per attempt - well inside any limit. That is the
     * win LOQ-16976 buys; a large re-run import is not.
     */
    const BATCH_VERIFY_CACHE_LIMIT = 200;

    /** @var Verify $apiConnector */
    private $apiConnector;

    /** @var Logger $logger */
    private $logger;

    /**
     * @var ShopperScopedSessionStores The captured-address store and the two verdict caches,
     *      behind the shopper-ownership guard. The raw customer session is deliberately
     *      NOT kept as well: keeping it would leave a way to reach those stores without
     *      the guard - see ShopperScopedSessionStores.
     */
    private ShopperScopedSessionStores $shopperSession;

    /**
     * Batch verdicts earned by THIS instance, keyed exactly as the session store is
     * (buildBatchVerifyCacheKey()) and holding BOTH polarities (LOQ-17148).
     *
     * THE LIFETIME IS THE RUN, and that is the whole point: an import file is chunked at 100
     * rows by Plugin\Admin\ValidateImportAddress::afterValidateData() and every chunk is
     * verified inside ONE PHP request against ONE Validator, so "this instance" IS "this import
     * run". A rejected address repeated in a later chunk used to be re-sent and re-billed in
     * every chunk it appeared in, because the session store holds passes only; this map is what
     * answers it instead. Passes are remembered here too, because the session store is bounded
     * and FIFO - on a file larger than self::BATCH_VERIFY_CACHE_LIMIT an early pass is evicted
     * before the file ends and would otherwise be re-billed within the same run.
     *
     * NEVER SERIALISED AND NEVER PERSISTED: a plain property, written to no session, and
     * deliberately not a static or a Registry entry (either would serve one shopper's verdicts to
     * another - see self::VERIFY_CACHE_SESSION_KEY). That mortality is a FEATURE and is asserted:
     * a rejection must never outlive the request, or a merchant who corrects the file or loosens
     * the threshold keeps being told "invalid" with no request made and no way to clear it.
     *
     * "THE RUN" IS THE INSTANCE'S LIFETIME, WHICH IS ONE REQUEST ON EVERY PATH THIS MODULE SHIPS
     * - PHP-FPM, one CLI process per programmatic import. That is an assumption about the runtime
     * rather than something this class enforces, so it is stated and not asserted "by
     * construction": under a long-lived worker (queue consumer, RoadRunner, Swoole) one Validator
     * spans many messages and this map grows for as long as the worker lives. The direction of
     * that failure is memory growth, never a wrong verdict - an identity change still discards
     * it, see discardRunScopedVerdictsIfShopperChanged(). No eviction policy is shipped for a
     * process this module does not run; add one WITH that process, sized against its workload.
     *
     * KEYED ON THE KEY, NOT ON THE RAW SIGNATURE, so the '' - "nothing identifiable" sentinel,
     * the store view and the AQI threshold fingerprint all govern it exactly as they govern the
     * session store; see buildBatchVerifyCacheKey(). ONLY READABLE VERDICTS ARE IN HERE: an
     * unreadable AQI or threshold is a FAULT REPORT, remembered nowhere, see
     * rememberBatchVerdict().
     *
     * @var array<string, bool> Batch cache key => verdict.
     */
    private array $runScopedBatchVerdicts = [];

    /**
     * The ShopperScopedSessionStores ownership epoch self::$runScopedBatchVerdicts was earned
     * under, or null before this instance has asked (LOQ-17148, LOQ-16978).
     *
     * The map holds licences to skip a billable verify, so it must have the same OWNERSHIP
     * lifetime as the session verdict stores and not merely the same request - see
     * discardRunScopedVerdictsIfShopperChanged() for why, and
     * ShopperScopedSessionStores::ownershipGeneration() for what the epoch means.
     *
     * STARTS AT NULL, WHICH IS NOT AN EPOCH, and that is deliberate: the guard's counter is an
     * int from its very first answer, so "I have not asked yet" has to be a value it can never
     * report, or an instance that had never looked would compare equal to an epoch it never saw.
     *
     * @var int|null
     */
    private ?int $runScopedVerdictsGeneration = null;

    /** @var RegionFactory */
    private $regionFactory;

    /** @var string */
    private $version = null;

    protected $helper;
    private SerializerInterface $serializer;

    /**
     * Validator construct
     *
     * @param Logger $logger
     * @param Session $session Wrapped in a ShopperScopedSessionStores and not kept raw, so the
     *                         captured-address store and both verdict caches can only be
     *                         reached through the shopper-ownership guard.
     * @param RegionFactory $regionFactory
     * @param ModuleListInterface $moduleList
     * @param Data $helper
     * @param SerializerInterface $serializer
     */
    public function __construct(
        Logger $logger,
        Session $session,
        RegionFactory $regionFactory,
        ModuleListInterface $moduleList,
        Data $helper,
        SerializerInterface $serializer
    ) {
        $this->logger = $logger;
        $this->shopperSession = new ShopperScopedSessionStores($session);
        $this->regionFactory = $regionFactory;
        $this->helper = $helper;
        $this->serializer = $serializer;

        if ($apiKey = $this->helper->getConfigValue('loqate_settings/settings/api_key')) {
            $this->apiConnector = new Verify($apiKey);
        } else {
            $this->logger->info('No Api Key found! - Please configure Loqate plugin on Admin side!');
            return false;
        }

        $this->version = 'AdobeCommerce_v' . $moduleList->getOne('Loqate_ApiIntegration')['setup_version'];
    }

    /**
     * Verify email address
     *
     * @param $emailAddress
     * @return array
     */
    public function verifyEmail($emailAddress)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return ['noKeyFound' => true];
        }

        $timeout = $this->helper->getConfigValue('loqate_settings/email_settings/email_validation_timeout_value');

        $data = ['Email' => $emailAddress, 'source' => $this->version, 'Timeout' => $timeout];

        if ($this->helper->getConfigValue('loqate_settings/email_settings/enable_accept_valid_catch_all')) {
            $data[Verify::ACCEPT_VALID_CATCH_ALL] = true;
        }
        $response = $this->apiConnector->verifyEmail($data);

        if (isset($response['error'])) {
            $this->logger->info($response['message']);
        }

        return $response;
    }

    /**
     * Verify phone number
     *
     * @param $phoneNumber
     * @return array
     */
    public function verifyPhoneNumber($phoneNumber, $country = null)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return ['noKeyFound' => true];
        }
        $data = ['Phone' => $phoneNumber, 'source' => $this->version];
        if (!empty($country)) {
            $data['Country'] = $country;
        }
        $response = $this->apiConnector->verifyPhone($data);

        if (isset($response['error'])) {
            $this->logger->info($response['message']);
        }

        return $response;
    }

    /**
     * Verify single address using Loqate API
     *
     * The Cleansing API behind $this->apiConnector is billable PER ADDRESS (see the billing
     * note on verifyMultipleAddresses()); this method sends exactly one address per request,
     * so here one call is one billed address. It is wired in from SIX call statements across
     * five classes, every one of them registered GLOBALLY - etc/di.xml and etc/events.xml
     * carry no area scoping:
     *  - Plugin\Frontend\CheckoutShippingInformation.php:32 - shipping-information POST;
     *  - Plugin\Frontend\CheckoutBillingAddress.php:34 - billing save, which
     *    savePaymentInformation replays at place order, so this one statement can run
     *    twice in a checkout;
     *  - Observer\QuoteSubmitBefore.php:85 and :109 - shipping and billing on
     *    sales_model_service_quote_submit_before, a global event, so it also fires on
     *    multishipping, not just in checkout; admin order create is the one path that
     *    event reaches but these two statements no longer do - the observer returns
     *    before them there, see QuoteSubmitBefore::isAdminArea() and the note in
     *    etc/events.xml:14-16;
     *  - Plugin\Frontend\CustomerAccountAddress.php:37 - customer address-book save;
     *  - Plugin\Admin\ValidateAddress.php:42 - admin customer-address validation,
     *    including an admin re-testing the same address repeatedly.
     * So one checkout of one address reaches this method 3-5 times depending on
     * Magento version and checkout front-end, and account saves and admin re-tests
     * replay the same address on top of that. Verdicts are therefore de-duplicated on
     * the normalised address signature and replayed from a session-scoped cache, so one
     * address costs one request (LOQ-16969). verifyMultipleAddresses() now has its own
     * equivalent guard (LOQ-16976), built by the same signature builder but held in its own
     * session attribute and namespaced by its own threshold, because its verdicts come from
     * the AQI rather than the AVC - see self::BATCH_VERIFY_CACHE_SESSION_KEY.
     *
     * CONCURRENCY - stated limit: this de-duplicates SEQUENTIAL calls only. Reading the
     * cache, issuing the billable call and writing the verdict are not atomic and
     * nothing serialises them, so two genuinely concurrent duplicate submissions (a
     * double-clicked place order, a retrying front end, parallel REST calls) can both
     * miss the cache and both be billed, and the later write can drop the earlier
     * verdict. File-based PHP sessions serialise same-session requests in practice, but
     * Magento's Redis session handler releases the session lock after
     * break_after_frontend and also supports disable_locking - under either, this
     * window is open. Locking a billable call for the length of a checkout request is
     * deliberately out of scope here: the fix targets the sequential replay that causes
     * the over-billing, and this residual case is accepted, not overlooked.
     *
     * @param $address
     * @param $checkForCaptured
     * @return array
     */
    public function verifyAddress($address, $checkForCaptured = true): array
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return ['noKeyFound' => true];
        }

        $requestArray = $this->parseAddress($address);
        $capturedKey = Controller::CAPTURED_ADDRESSES_SESSION_KEY;
        if ($checkForCaptured && ($storedAddresses = $this->shopperSession->getData($capturedKey))) {
            if ($this->checkForCapturedAddress($requestArray, $storedAddresses)) {
                return ['error' => false];
            }
        }

        // ONE key, one read, and both verdicts written under it. Its region segment is
        // Address4 - the region value ACTUALLY SENT to Loqate - which is what makes one key
        // enough for successes and rejections alike; see buildVerifyCacheSignature().
        //
        // The key is DERIVED from the captured-address signature rather than being it: of
        // the street, buildAddressSignature() covers lines 1 and 2 only (Address1/Address2),
        // while the request sent to Loqate carries the FULL joined street in 'Address', so
        // with customer/address/street_lines >= 3 an edit to line 3 or 4 would leave the
        // signature untouched and replay a verdict for an address Loqate never saw.
        // buildVerifySignature() folds 'Address' in, and must not be pushed down into
        // buildAddressSignature(), which is also compared against the captured-address
        // store whose entries (Helper\Controller::storeCapturedAddress() via
        // ADDRESS_CAPTURE_MAPPING) have no 'Address' key at all.
        //
        // THE INVARIANT THE WHOLE SCHEME RESTS ON: the key must project EVERY field
        // parseAddress() sends to Loqate - that is, every value in ADDRESS_MAPPING. Only
        // then is one key safe for both verdicts: a rejection is recorded against the
        // complete address Loqate actually judged, so correcting ANY field re-verifies
        // rather than replaying a rejection (no checkout dead-end), and a success is
        // replayed only for an address Loqate actually accepted. A field added to
        // ADDRESS_MAPPING but left out of the key would be sent to Loqate yet invisible to
        // the cache, silently re-opening BOTH the double billing and the dead-end, so the
        // field list is DERIVED from ADDRESS_MAPPING rather than written out by hand - see
        // verifyCacheSignatureFields().
        $signature = $this->buildVerifyCacheSignature($requestArray);

        $cachedResult = $this->getCachedVerifyResult($signature);
        if ($cachedResult !== null) {
            $this->logVerifyCacheOutcome('hit', $signature);

            return $cachedResult;
        }

        // Every miss is followed by exactly one billable request (the call below), so
        // counting these lines counts the Loqate invoice. The miss and every hit that later
        // replays it are reported under the same key, so they share a token - see
        // logVerifyCacheOutcome().
        $this->logVerifyCacheOutcome('miss', $signature);

        $response = $this->apiConnector->verifyAddress(['Addresses' => [$requestArray], 'source' => $this->version]);

        if (isset($response['error'])) {
            // A transport failure is not a verdict: it is never cached, so the
            // next call retries the API instead of replaying the failure for the
            // rest of the session.
            $this->logger->info($response['message']);

            return [
                'error' => true,
                'message' => __('An unexpected error occurred while trying to validate your address.')
            ];
        }

        $avcCode = $response[0][0]['AVC'] ?? null;
        if (!is_string($avcCode) || $avcCode === '') {
            // No AVC at all means a response shape we cannot read (the connector
            // collapses anything unexpected to an empty array): that is a failure,
            // not a verdict, so it is rejected but never cached - one connector or
            // credential fault must not brand every address invalid all session.
            $this->logger->info('Loqate verify response contained no AVC; rejection not cached.');

            return ['error' => true, 'message' => __('The provided address is invalid.')];
        }

        if (!$this->checkAVCStatus($avcCode)) {
            // A real AVC that fails the thresholds is a definitive verdict, so it is cached.
            $this->storeVerifyResult($signature, true);

            return ['error' => true, 'message' => __('The provided address is invalid.')];
        }

        // The same key a rejection is stored under, which is safe precisely because that key
        // is a faithful projection of the address Loqate just judged.
        //
        // WHY THE SEPARATE SUCCESS KEY IS GONE, where its rationale used to live: it existed
        // because the success key was LOSSY about the region, so a stricter key was needed
        // beside it to stop a shopper being stranded on a replayed "no". Nothing is lossy
        // now, so there is nothing for a second key to absorb - and both invariants that
        // motivated the split still hold: a shopper who corrects a genuinely wrong region
        // gets a different key and a fresh verification, and a success can only ever be
        // replayed for an address Loqate actually accepted.
        $this->storeVerifyResult($signature, false);

        return ['error' => false];
    }

    /**
     * Verify multiple addresses using Loqate API
     *
     * Used by admin order create (Plugin\Admin\OrderSave::aroundExecute()) and customer import
     * (Plugin\Admin\ValidateImportAddress::afterValidateData()).
     *
     * BILLING (LOQ-16976): the Cleansing API is billable PER ADDRESS, not per request, so
     * the verdict cache is consulted PER ADDRESS, before the address is added to the
     * payload - only misses are sent, so a five-row batch with three rows already verified
     * in this session bills two. Verdicts live in their own session attribute
     * (self::BATCH_VERIFY_CACHE_SESSION_KEY) with their own AQI-based namespacing, and are
     * therefore invisible to verifyAddress()' cache and vice versa: read that constant
     * before considering merging the two. Only PASSING verdicts are cached, see
     * storeBatchVerifyResult(). The pre-existing captured-address guard is applied first
     * and is likewise free.
     *
     * TWO MEMORIES, TWO LIFETIMES, ONE KEY (LOQ-17148). The lookup before payload assembly
     * consults BOTH, in this order:
     *  - self::$runScopedBatchVerdicts, this INSTANCE's map, holding BOTH polarities for one
     *    import RUN - an import file is chunked at 100 rows and every chunk is verified against
     *    the same Validator inside one PHP request. This is what stops a REJECTED address being
     *    re-billed in every chunk it appears in, which the session store structurally could not
     *    do, and it is unbounded by self::BATCH_VERIFY_CACHE_LIMIT so an evicted PASS is not
     *    re-billed mid-run either;
     *  - the SESSION store, holding PASSES ONLY and bounded, which is what survives to a LATER
     *    REQUEST (the re-submitted admin order, a re-run import that fits inside the limit).
     * Both are keyed by buildBatchVerifyCacheKey(), so one address is one key in both and a
     * threshold or store-view change invalidates both at once. The asymmetry - a rejection is
     * remembered for the run and never for the session - is measured on
     * self::BATCH_VERIFY_CACHE_LIMIT.
     *
     * A BROKEN QUALITY BAR COSTS NOTHING, BECAUSE ITS ANSWER IS KNOWN BEFORE THE REQUEST. If
     * address_quality_index cannot be read as a grade, checkQualityIndex() can only reject, so
     * every row is rejected whatever Loqate answers - and paying per address for that answer is
     * paying for a refusal already decided, on every Check Data click, for the whole file. The
     * threshold is therefore read ONCE, before payload assembly, through
     * readableQualityIndexThreshold(); when it is unreadable every address that would have been
     * sent is answered false, no request is made and nothing is remembered. It is a PRE-FLIGHT
     * decision and only that: it depends on configuration alone, never on the response, so no
     * response-side judgement is moved forward. The captured-address guard still answers first,
     * unchanged, because a captured address never consults the AQI at all - which is why the
     * broken-threshold line reports the CONFIGURATION rather than a count of refusals: it is
     * emitted once for any batch verified under an unreadable bar, including one whose rows are
     * all captured and none of which is refused. See readableQualityIndexThreshold() for the
     * wording and for why the alternative - staying quiet on such a batch - is worse.
     *
     * RETURN SHAPE - load-bearing in two ways, do not "simplify" either:
     *  - one entry per input address, under the INPUT's OWN KEY.
     *    Plugin\Admin\OrderSave::aroundExecute()
     *    reports that key to the admin, and before LOQ-16977 the keys of a mixed batch
     *    were wrong: the original code recovered them with
     *    array_search(false, $addressesToCheck) over an array whose values were all
     *    truthy, so it always got false, coerced to key 0 - every response row overwrote
     *    $result[0], and the captured addresses' own verdicts were never merged into
     *    $result at all. The mapping is now an explicit parallel array, see $sentItems.
     *  - in the INPUT's OWN ORDER, because Plugin\Admin\ValidateImportAddress::
     *    afterValidateData() array_merge()s the
     *    per-chunk arrays and then derives the import row number from the merged
     *    $index + 1. array_merge() renumbers integer keys BY INSERTION ORDER, not by key
     *    value, so a result that came back as [3 => .., 0 => .., 1 => .., 2 => ..] - which
     *    is precisely what filling cache hits during the first pass and API verdicts
     *    afterwards would otherwise produce - would silently mis-attribute EVERY reported
     *    row number. $result is therefore pre-seeded with one slot per address in input
     *    order before any verdict is filled in. A ksort() at the end would NOT be an
     *    equivalent fix: it would reorder string keys (OrderSave's) and any input that is
     *    not already in ascending key order.
     *
     * WHY THE ROW COUNT IS CHECKED BEFORE ANY ROW IS ATTRIBUTED, and why a mismatch fails the
     * whole batch. Verdicts are attributed POSITIONALLY - the Nth answer belongs to the Nth
     * address sent - and the connector makes that attribution unverifiable from the response
     * itself: the connector's Verify::verifyAddress() (vendor/lqt/api-connector)
     * ends in array_column($response, 'Matches'), and array_column() SILENTLY DROPS every
     * record that has no 'Matches' key and REINDEXES the survivors into a clean 0..N-1 list.
     * So a three-address batch whose MIDDLE record came back as a PER-RECORD error envelope
     * reaches us as a TWO-element list in which position 1 holds address 3's verdict (verified
     * on PHP 8.3). Without the count guard below, address 2 would be handed address 3's verdict
     * and - far worse - storeBatchVerifyResult() would persist address 3's PASS against ADDRESS
     * 2's signature for the rest of the session: the "wrong valid replayed" failure the strict
     * key exists to prevent, arriving through the one door the single-address path does not have
     * (verifyAddress() sends one address, so a dropped record leaves it nothing to
     * mis-attribute).
     *
     * NOTE THE EXACT SCOPE, so this guard is not credited with more than it does: array_column()
     * drops only records MISSING the key. A record that HAS 'Matches' whose value is [] or null
     * SURVIVES as an empty element and the count is PRESERVED (verified on PHP 8.3), so it sails
     * past this guard and lands in the attribution loop with no readable AQI. That disjoint fault
     * is answered there, by checkQualityIndex() failing closed - see both.
     *
     * The same array_column() also flattens a class of WHOLE-response faults to the same empty
     * list: any HTTP 200 body that is not a list of records carrying 'Matches' - {"Items": ...},
     * {"error":"Unauthorized"}, {} - since each collapses to []. None of those sets
     * $response['error'], so before the guard they produced zero verdicts, zero log lines and an
     * import that proceeded entirely unverified with no diagnostic at all.
     *
     * WHAT IS NOT IN THAT CLASS, named explicitly so this justification is not widened back out
     * to faults that were never silent: an unparseable body and a TOP-LEVEL
     * {"Number":..,"Description":..} envelope. The first json_decode()s to null, so
     * Verify::verifyAddress()'s is_array() test fails and it returns
     * ['error' => true, 'message' => 'Unexpected error occurred.']. The
     * second makes HttpClient::searchForError() report an error, so HttpClient::post() THROWS
     * and Verify::verifyAddress() catches it into that same shape. Both were
     * therefore already answered by the isset($response['error']) branch below, before this
     * guard existed (all traced on PHP 8.3). Such an envelope does arrive as [] in two
     * corners, both of them just further instances of the class above rather than new ones,
     * and both because HttpClient::post() tests searchForError()'s return for TRUTHINESS
     * rather than for false:
     *  - a FALSY 'Description' - '' and '0', but equally JSON null, 0 and false;
     *  - an ABSENT 'Description' next to a present 'Number', which
     *    HttpClient::searchForError() reads UNGUARDED. Which branch this takes is decided by the
     *    error handler in force rather than by the body: with no throwing handler PHP 8 evaluates
     *    the read to null, the return is falsy and the envelope reaches array_column() as [] -
     *    the count guard's branch; under Magento\Framework\App\ErrorHandler (registered by
     *    Bootstrap::run(), irrespective of MAGE_MODE) the warning becomes an \Exception that
     *    Verify::verifyAddress() converts into ['error' => true, ...] - the other branch. Either
     *    is safe - both end in this method returning false - which is why it is documented here
     *    rather than defended against.
     *
     * TRADE-OFF, accepted deliberately: a genuinely TRUNCATED response (Loqate answering
     * fewer rows than it was sent) now fails the whole batch instead of reporting the rows it
     * does hold. For the import that means one critical error and no import, where before it
     * meant later chunks silently renumbered by ValidateImportAddress's array_merge(). That is
     * the right direction: a mis-numbered row sends the merchant to edit a VALID row while the
     * genuinely bad one imports unnoticed, whereas a blocked import is loud, retryable and
     * loses nothing.
     *
     * ACCEPTED LIMITS, stated:
     *  - CONCURRENCY: exactly the limit documented on verifyAddress(). Read, billable call
     *    and write are not atomic, so two genuinely concurrent submissions of the same
     *    batch can both miss and both be billed. Sequential replay - the re-submitted
     *    admin order, the re-run import - is what this de-duplicates.
     *  - A ROW WITH NO READABLE AQI is answered INVALID and remembered NOWHERE - not in the
     *    session, not for the rest of the run. That is checkQualityIndex() answering null for a
     *    record whose 'Matches' list is present but EMPTY, a shape the row-count guard cannot
     *    catch because array_column() PRESERVES it (see the attribution loop). Its BILLING cost
     *    is not "one re-attempt": remembered nowhere means EVERY OCCURRENCE of that address is
     *    sent and billed again, all run and every run after, and 'Matches' => [] is Loqate's
     *    ordinary answer for an address it cannot match - on a poorly-matching file, most of the
     *    file. It is still the direction chosen rather than the direction missed: remembering it
     *    would let ONE connector fault or one bad credential brand every matching row in the file
     *    invalid for the rest of the run, sending the merchant to edit rows Loqate never rejected.
     *  - LOQ-17015 IS NOT RESOLVED BY LOQ-17148, and here is the boundary exactly, so that ticket
     *    is neither closed on a partial fix nor re-implemented for work already done. EVERY COPY
     *    AFTER THE FIRST IS BILLED WITHIN THE CHUNK IN WHICH THE ADDRESS FIRST APPEARS: the
     *    pre-flight loop runs to completion over the WHOLE chunk before the request is issued and
     *    the only writer to either memory is rememberBatchVerdict(), which runs AFTER the
     *    response, so k copies in that chunk all miss and all k go on the wire - 100 identical
     *    rows in one chunk bill 100. EVERY APPEARANCE IN ANY LATER CHUNK IS FREE, however many
     *    copies that chunk holds. The residue is therefore bounded by the CHUNK SIZE, not by one
     *    charge per distinct address, which an earlier revision of this docblock claimed after
     *    probing the two-copy case and generalising it to all n without testing it.
     *    Whoever implements the rest MUST preserve the ONE-RESPONSE-ROW-PER-SENT-ITEM assumption
     *    the row-count guard and the positional attribution loop both depend on: collapsing
     *    duplicates into a single payload slot changes the row/address arithmetic, so that dedupe
     *    and this guard have to change TOGETHER. Sending N slots and fanning one row out to
     *    several caller keys, or sending N-k slots and re-deriving the expected row count from
     *    the de-duplicated payload rather than from the caller's address count, are both safe;
     *    silently comparing count($response) against the pre-dedupe count is not.
     *
     * @param $addresses
     * @param bool $checkForCaptured
     * @return array|false THREE shapes, and every caller has to handle all three - see the
     *                     docblock on Plugin\Admin\ValidateImportAddress::afterValidateData():
     *                     array<int|string, bool>, one verdict per input key in input order,
     *                     the normal case; ['noKeyFound' => true] when no API key is
     *                     configured, which is load-bearing and must NOT be merged into
     *                     row-indexed data (a string key there is reported as row #1); or
     *                     false when the API call failed or answered a row count that cannot
     *                     be attributed to the addresses sent.
     */
    public function verifyMultipleAddresses($addresses, $checkForCaptured = true)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return ['noKeyFound' => true];
        }

        $storedAddresses = $checkForCaptured
            ? $this->shopperSession->getData(Controller::CAPTURED_ADDRESSES_SESSION_KEY)
            : null;

        // PRE-FLIGHT, READ ONCE PER BATCH AND BEFORE ANY PAYLOAD IS ASSEMBLED: a threshold that
        // cannot be read as a grade decides every row before Loqate is asked, so asking is paying
        // per address for a refusal already made. Same rule and same log line as the response
        // side, because readableQualityIndexThreshold() is the single site of both - and reading
        // it here LOGS, on every batch verified under a broken bar, whether or not this batch goes
        // on to refuse anything (an all-captured batch refuses nothing). That is why the line
        // reports the configuration and never an outcome. See the docblock's "A BROKEN QUALITY BAR
        // COSTS NOTHING" paragraph.
        $thresholdIsReadable = $this->readableQualityIndexThreshold() !== null;

        $result = [];
        $requestArray = [];

        // PARALLEL TO $requestArray BY POSITION: $sentItems[$n] describes the address sent
        // at position $n of the payload - the caller's key to report its verdict under,
        // and the signature to cache that verdict against. This is the whole of the
        // LOQ-16977 fix: the key an address came in under is RECORDED when the address is
        // added to the payload, instead of being guessed at afterwards.
        $sentItems = [];

        foreach ($addresses as $index => $address) {
            // Reserve this address's slot NOW, so the returned array follows the input key
            // order however out of order the verdicts are filled in below. See the return
            // shape note above: this ordering is what keeps import row numbers correct.
            $result[$index] = null;

            $parsedAddress = $this->parseAddress($address);

            // Pre-existing guard, kept ahead of the verdict cache because it is free and
            // Loqate authored these addresses itself.
            if ($storedAddresses && $this->checkForCapturedAddress($parsedAddress, $storedAddresses)) {
                $result[$index] = true;
                continue;
            }

            // THE QUALITY BAR IS BROKEN, SO THIS ROW IS ALREADY DECIDED - do not buy the answer.
            // AFTER the captured guard, which never consults the AQI, and BEFORE the memories,
            // which cannot hold anything for this row anyway: an unreadable threshold produces a
            // fault report that rememberBatchVerdict() writes nowhere, and every readable verdict
            // is keyed under the threshold that produced it (buildBatchVerifyCacheKey()). So the
            // lookup would always miss, and skipping it skips two log lines that would both be
            // untrue - no hit to report, and no billed address behind the miss.
            if (!$thresholdIsReadable) {
                $result[$index] = false;
                continue;
            }

            // The SAME builder verifyAddress() uses, and now the only one there is: the
            // region is in the key on the read exactly as on the write, so a region variant
            // Loqate was never asked about simply misses and is billed. (That is why the
            // warning this comment replaces - "no county rewrite may be pushed down into the
            // shared builders" - is deletable: there is no second builder left to widen.)
            // What stays separate is the STORE and the namespacing, because these verdicts
            // come from the AQI: see self::BATCH_VERIFY_CACHE_SESSION_KEY and
            // buildBatchVerifyCacheKey(). Pinned by
            // ValidatorBatchVerifyCacheTest::testAnUnverifiedCountyVariantIsNeverServedACachedBatchPass()
            // and ::testChangingOnlyTheCountyCostsASecondBillableAddress().
            $signature = $this->buildVerifyCacheSignature($parsedAddress);

            // BOTH memories, run-scoped first, and reported as ONE 'hit' either way (LOQ-17148).
            // The run map is asked first because it is CHEAPER - no session read, no unserialise
            // - strictly newer than anything in the session store for the same key, and the only
            // one of the two that can answer a REJECTION. Cheaper, NOT free: reading it runs the
            // ownership check, which costs two session reads and, on first access, a session
            // write - and that check is exactly what makes answering from this map safe, see
            // discardRunScopedVerdictsIfShopperChanged(). The log line stays a single 'hit'
            // token deliberately: it reconciles the drop in billed addresses against the
            // invoice, and an operator counting hits against misses does not care which memory
            // answered - see logBatchVerifyCacheOutcome().
            $cachedVerdict = $this->getRunScopedBatchVerdict($signature)
                ?? $this->getCachedBatchVerifyResult($signature);
            if ($cachedVerdict !== null) {
                $this->logBatchVerifyCacheOutcome('hit', $signature);
                $result[$index] = $cachedVerdict;
                continue;
            }

            // Every miss becomes exactly one billed address in the request below, so
            // counting these lines counts the Loqate invoice for this path.
            $this->logBatchVerifyCacheOutcome('miss', $signature);

            $sentItems[] = ['key' => $index, 'signature' => $signature];
            $requestArray[] = $parsedAddress;
        }

        if ($requestArray === []) {
            // Nothing left to ask, because every address was captured, cached or decided by the
            // pre-flight threshold guard (or the batch was empty). Returning here is what makes
            // an all-hit batch cost NO request, rather than sending an empty 'Addresses' payload
            // to a billable endpoint and discarding the answer. Through sealBatchVerdicts() like
            // every other exit: this path used to return $result RAW, so the terminal guarantee
            // held on one exit and not the other, and one future edit would have produced two
            // different wrong behaviours depending on cache state.
            return $this->sealBatchVerdicts($result);
        }

        $response = $this->apiConnector->verifyAddress(['Addresses' => $requestArray, 'source' => $this->version]);
        if (isset($response['error'])) {
            // A transport failure is not a verdict: nothing is cached, so the next attempt
            // retries the API instead of replaying the failure for the rest of the session.
            // Callers MUST handle this non-array return - see
            // Plugin\Admin\ValidateImportAddress::afterValidateData().
            $this->logger->info($response['message']);
            return false;
        }

        // THE PRECONDITION OF POSITIONAL ATTRIBUTION, checked before a single verdict is read or
        // cached: exactly one answer per address sent. The connector's array_column($response,
        // 'Matches') drops records with no 'Matches' key and reindexes the rest, so a gap arrives
        // as a SHORTER CLEAN LIST indistinguishable from a truncated one, shifting every position
        // after it - and the same call collapses the whole-response faults ({"Items": ...},
        // {"error":"Unauthorized"}, {}) to [], which were previously silent. The docblock above
        // has the full mechanism, the shapes this does NOT catch, and the accepted trade-off.
        //
        // Returning false, not a partial array: the callers treat false as "no verdict for this
        // batch" and fail closed on it - Plugin\Admin\ValidateImportAddress::afterValidateData()
        // reports one critical error and stops, Plugin\Admin\OrderSave::aroundExecute() blocks
        // the order with a message.
        if (!is_array($response) || count($response) !== count($sentItems)) {
            $this->logger->info(sprintf(
                'Loqate batch verify answered %d rows for %d addresses; verdicts not attributed.',
                is_array($response) ? count($response) : 0,
                count($sentItems)
            ));

            return false;
        }

        // Attribution is positional and, thanks to the guard above, total: $sentItems and
        // $response are now known to be the same length, so an own counter walks them in
        // lockstep and every address sent gets exactly one verdict.
        //
        // WHAT THE COUNTER DOES AND DOES NOT BUY, stated exactly, because it is easy to read
        // more into it. foreach pairs the two lists by ITERATION ORDER, so this loop does
        // depend on $response iterating in the order the payload was built - just as strong an
        // assumption as reading $response[$position] would be, not a weaker one. That order is
        // guaranteed upstream, not here: the connector's array_column($response, 'Matches')
        // emits one element per source record in source order, and Loqate answers records in
        // the order they were sent. What the counter buys is only that $sentItems is indexed by
        // POSITION and never by $response's KEYS, so nothing breaks if those keys are ever
        // something other than the clean 0..N-1 list array_column() happens to produce (a
        // string key, a gap): the loop still reads the Nth iterated answer as the Nth address.
        // The count guard above is what keeps the one realistic reordering out of this loop
        // altogether - a dropped record shortens the list, so the counts differ and we return.
        $position = 0;
        foreach ($response as $addressResponse) {
            $sentItem = $sentItems[$position];
            $position++;

            // '??' rather than a bare read: post data and connector output are both outside
            // our control, and a missing AQI must not raise a warning mid-import.
            $qualityIndex = $addressResponse[0]['AQI'] ?? null;

            // FAILS CLOSED on an AQI we could not read, and the two guards on this path catch
            // DISJOINT faults - neither subsumes the other. The ROW-COUNT guard above catches
            // records MISSING the 'Matches' key (array_column() DROPS those, so the counts
            // differ) plus the whole-response shapes that collapse to []; checkQualityIndex()
            // catches a record that IS PRESENT carrying an empty or unreadable 'Matches',
            // semantically "Loqate found no match for this address".
            //
            // The second case is FULLY REACHABLE past the count guard - correcting an earlier
            // claim in these comments that it was not. Verified on PHP 8.3: a record that HAS
            // 'Matches' whose value is [] SURVIVES as an empty element and the COUNT IS
            // PRESERVED, so it reaches here as [], $addressResponse[0]['AQI'] ?? null is null,
            // and null <= 'A' is true - that row used to be answered VALID. "No match found"
            // reported as "valid" is the maximally wrong answer, so this is a reachable hole
            // being closed, not defence in depth. See checkQualityIndex()'s docblock for why the
            // test is on the value's SHAPE and why it answers three states rather than two.
            $verdict = $this->checkQualityIndex($qualityIndex);

            // THE STRICT BOOL COERCION IS LOAD-BEARING, AND THIS IS THE ONLY PLACE A null CAN
            // REACH $result (LOQ-17148). checkQualityIndex() answers null for "we could not
            // read this", which must still REJECT the row - it is only the REMEMBERING that
            // differs - so it is coerced here rather than assigned. It is coerced HERE and not
            // left to sealBatchVerdicts() because the rejection must be visible at the point it
            // is decided; the terminal coercion is a containment guard, not this row's verdict.
            // Pinned by Test\Unit\Plugin\Admin\ValidateImportAddressRowAttributionTest, which is
            // where the cost of getting it wrong is stated: a slot that is not filled here would
            // let Plugin\Admin\ValidateImportAddress::afterValidateData()'s array_merge()
            // renumber every later row - an invalid row reported against a valid row's number,
            // the mis-attribution LOQ-16977 was raised to fix.
            $isValid = $verdict === true;
            $result[$sentItem['key']] = $isValid;

            // NEVER REMEMBER A VERDICT WE COULD NOT READ, in ONE place for BOTH lifetimes:
            // rememberBatchVerdict() is the single gate, and it is handed the three-state
            // answer rather than the coerced bool precisely so that "unreadable" is still
            // distinguishable when the decision is made. No readability test here, none in
            // storeBatchVerifyResult(); one rule, one site.
            $this->rememberBatchVerdict($sentItem['signature'], $verdict);
        }

        return $this->sealBatchVerdicts($result);
    }

    /**
     * The ONE exit every verdict array leaves verifyMultipleAddresses() through: coerce each
     * slot to a strict bool, keeping every key, in order.
     *
     * A NO-OP TODAY, and stated rather than deleted. Every reserved slot is necessarily filled
     * by the time it gets here - captured, decided by the pre-flight threshold guard, answered
     * from either memory, or sent and therefore answered, a mismatched row count having returned
     * false earlier - so no null can reach this. It exists to CONTAIN the edit that changes that.
     *
     * IT FAILS CLOSED, WHICH IS THE CORRECTION OF WHAT USED TO BE HERE: array_filter($result,
     * fn ($v) => $v !== null), justified as degrading an unfilled slot to a MISSING KEY "which
     * callers already read as nothing to report". True of
     * Plugin\Admin\ValidateImportAddress::afterValidateData(), which reports only the keys it
     * receives - and the UNSAFE direction for Plugin\Admin\OrderSave::aroundExecute(), whose
     * loop raises an error only for keys that are PRESENT, so a dropped key puts that address on
     * the order UNVERIFIED and SILENTLY. Coercing keeps the slot and reports it INVALID, which is
     * the fail-closed stance the rest of this class takes.
     *
     * '=== true' rather than a cast, so no future truthy value is promoted to "verified".
     * array_map() over a SINGLE array preserves keys and order (verified on PHP 8.3), so
     * OrderSave's 'billing_address'/'shipping_address' keys and the import's 0..N-1 ordering
     * survive unchanged.
     *
     * @param array<int|string, bool|null> $result One slot per input address, in input order.
     * @return array<int|string, bool> The same keys in the same order, every value a bool.
     */
    private function sealBatchVerdicts(array $result): array
    {
        return array_map(static fn ($verdict): bool => $verdict === true, $result);
    }

    /**
     * Parse address and return expected format for verify request
     *
     * @param $address
     * @return array
     */
    private function parseAddress($address): array
    {
        $formattedAddress = ['Address' => ''];

        //get region name
        if (isset($address['region_id']) && $address['region_id']) {
            $region = $this->regionFactory->create()->load($address['region_id']);
            $address['region'] = $region->getName();
        }

        foreach (self::ADDRESS_MAPPING as $key => $value) {
            if (isset($address[$key]) && !is_array($address[$key])) {
                $formattedAddress[$value] = $address[$key];
            }
        }

        // Magento stores the street under a single "street" key as an array (or a
        // newline-separated string); it has no street_1/street_2 fields, so the
        // ADDRESS_MAPPING above never populates the street lines. Without this the
        // street never reaches the verify request and, crucially, an address picked
        // from the Loqate lookup can never be matched against the captured-address
        // store - so it gets re-verified and may be rejected.
        $streetLines = $this->extractStreetLines($address);
        if ($streetLines) {
            $formattedAddress['Address'] = implode(', ', $streetLines);
            $formattedAddress['Address1'] = $streetLines[0];
            if (isset($streetLines[1])) {
                $formattedAddress['Address2'] = $streetLines[1];
            }
        }

        return $formattedAddress;
    }

    /**
     * Normalise Magento's street value (array or newline-separated string) into a
     * list of trimmed, non-empty street lines.
     *
     * @param $address
     * @return array
     */
    private function extractStreetLines($address): array
    {
        if (!isset($address['street'])) {
            return [];
        }

        $street = $address['street'];
        if (!is_array($street) && !is_scalar($street)) {
            // Objects/resources/null cannot be cast to string, so treat anything
            // non-stringable as "no street lines" rather than throwing.
            return [];
        }

        $lines = is_array($street) ? $street : preg_split('/\r\n|\r|\n/', (string)$street);

        // Post data is attacker-controlled ("street[0][]=x" reaches here as a
        // nested array), so drop anything trim() could not accept before mapping.
        $lines = array_filter($lines, 'is_scalar');

        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    /**
     * Check if response quality index matches the quality customer has set.
     *
     * THREE STATES, NOT TWO, AND THAT IS THE WHOLE OF THE READABILITY RULE (LOQ-17148):
     *  - true  - AQI and threshold both readable, and the AQI meets it;
     *  - false - both readable and the AQI MISSES it. A VERDICT: Loqate judged this address and
     *            the merchant's quality bar refused it;
     *  - null  - one of them could not be read. A FAULT REPORT about the response or the
     *            configuration, not a verdict.
     * The row is REJECTED for false and null alike (the caller coerces), so which rows an import
     * rejects does not change. What the third state buys is the ability to remember the second:
     * rememberBatchVerdict() remembers a readable verdict of either polarity and a null nowhere,
     * so one connector fault or one bad credential cannot brand every matching row in a file
     * invalid for the rest of the run. Before the split the two were the same false.
     *
     * THE RULE LIVES IN ONE PLACE: this method decides READABILITY, rememberBatchVerdict()
     * decides what a null means for memory, and neither the call site nor storeBatchVerifyResult()
     * repeats either test. The previous arrangement got that wrong in the other direction - it
     * left "never remember an unreadable verdict" true only as a side effect of failures not
     * being cached, which would have stopped being true the moment one lifetime cached them.
     *
     * FAILS CLOSED on an AQI it cannot read. The guard is on the value's SHAPE - "is this a
     * non-empty string" - and deliberately not on truthiness or emptiness, because the
     * comparison below is a STRING comparison in which 'A' is the strongest grade
     * (etc/config.xml defaults address_quality_index to 'A' and 'A' <= 'A' is true). Anything
     * looser would over-reject:
     *  - empty()/falsy would reject '0', and would reject nothing this guard does not
     *    already catch;
     *  - a whitelist of known grade letters would reject a grade letter Loqate adds later,
     *    turning a forward-compatible comparison into a hard-coded one.
     * A legitimately valid 'A' therefore still passes, and only a genuinely unreadable
     * value - no string, or the empty string - is rejected.
     *
     * WHY THIS IS NOT COSMETIC. Under PHP 8's comparison rules null <= 'A', '' <= 'A',
     * false <= 'A' and 0 <= 'A' are ALL true (verified on 8.3), so before this guard an
     * unreadable AQI was answered VALID. The single call site - the positional attribution loop
     * in verifyMultipleAddresses() - reads $addressResponse[0]['AQI'] ?? null, which is null for
     * a response record whose 'Matches' list is present but EMPTY, i.e. Loqate saying "no match
     * for this address", the case where "valid" is the maximally wrong answer. See that call
     * site for why the row-count guard does not catch that shape. It mirrors verifyAddress()'s
     * AVC guard, so both verify paths draw the "readable verdict" line in the same place.
     *
     * THE THRESHOLD SIDE LIVES IN readableQualityIndexThreshold(), because
     * verifyMultipleAddresses() asks the same question before it assembles a payload; read that
     * method for why an unreadable threshold is the dangerous case rather than a blank one.
     *
     * @param $qualityIndex The AQI from the response row, of any type - it is unvalidated
     *                      connector output, so this method must not assume a string.
     * @return bool|null true when the AQI is readable, the threshold is readable and the AQI
     *                   meets it; false when both are readable and it does not; null when
     *                   either could not be read, which is a fault report and not a verdict.
     *                   NOTE for callers: null is FALSY, so a bare truthiness test still
     *                   rejects correctly - but it must never be stored or compared as a
     *                   verdict. See rememberBatchVerdict().
     */
    private function checkQualityIndex($qualityIndex): ?bool
    {
        if (!is_string($qualityIndex) || $qualityIndex === '') {
            return null;
        }

        $configIndex = $this->readableQualityIndexThreshold();
        if ($configIndex === null) {
            return null;
        }

        return $qualityIndex <= $configIndex;
    }

    /**
     * The configured quality bar, or null if it cannot be read as a grade - and the log line
     * that says so.
     *
     * THE SINGLE SITE OF THE THRESHOLD READABILITY RULE. Two callers ask, for two different
     * reasons, and neither repeats the test: checkQualityIndex() cannot judge a response against
     * a threshold it cannot read, and verifyMultipleAddresses() will not BUY a response whose
     * verdict is already settled (see its pre-flight guard). One rule, one in_array(), one
     * message.
     *
     * WHY THE TEST HAD TO EXIST AT ALL. checkQualityIndex() compares with <=, a STRING
     * comparison, and the dangerous value is not a blank threshold but an UNREADABLE one:
     * 'A' <= 'zzz' and 'E' <= 'zzz' are both TRUE on 8.3, so any text sorting above 'E' passed
     * EVERY address, including the worst AQI Loqate returns - a silent, total bypass of the
     * merchant's configured quality bar that no amount of guarding the response side detects.
     * (A blank threshold already failed closed: 'A' <= null and 'A' <= '' are both false.)
     * Rejecting is the only safe answer: the whole point of the setting is to bar addresses, and
     * a threshold nobody can read cannot be said to admit any of them.
     *
     * WHY IT LOGS FROM HERE. That line is the only signal a merchant has that their quality bar
     * is broken, so it must not sit anywhere a remembered verdict could skip it - and the batch
     * pre-flight does not: it runs before both memories and before payload assembly, so no cache
     * hit can skip it. It also now fires on a file that comes back ALL-EMPTY, which used to
     * produce zero threshold-broken lines while rejecting every row, because checkQualityIndex()
     * returned on the unreadable AQI before it ever read the threshold. What did change is the
     * frequency - once per verified batch rather than once per verdict - and the CHANGELOG says
     * so.
     *
     * WHY THE MESSAGE REPORTS THE CONFIGURATION AND NOT AN OUTCOME. It is written from what this
     * method actually knows - that the configured value is not a grade, and that nothing can
     * therefore clear the bar - and deliberately not as "rejecting the address", which is what it
     * used to say. That older sentence was true of the RESPONSE-side caller, which always has an
     * address in hand, and false of the batch pre-flight, which runs once per batch BEFORE any row
     * is decided: a batch whose every row is a captured address is answered entirely by the
     * captured guard, so the line was emitted while ZERO rows were rejected. Support reads this
     * out of a log file, so a sentence that names an outcome that did not happen sends them
     * looking for refused rows that do not exist. The wording holds at both call sites and at
     * neither does it assert more than the configuration.
     *
     * AND WHY IT IS NOT INSTEAD EMITTED ONLY WHEN A ROW IS ACTUALLY REFUSED. That would make a
     * CONFIGURATION diagnostic conditional on the composition of whatever batch happened to run:
     * the all-captured batch above would go silent, and a merchant would keep a broken quality bar
     * with nothing in the log until a row that is not captured happens along - the same shape of
     * hole as the all-empty-'Matches' file this branch just closed, arriving through a different
     * door. It would also need the readability rule to grow a second, non-logging entry point for
     * the pre-flight to consult first, which is exactly the single-siting this method exists to
     * hold. The fault is worth reporting on every batch that ran under it; only the sentence
     * needed correcting.
     *
     * REACHABLE FROM THE ADMIN UI SINCE LOQ-17148: etc/adminhtml/system.xml exposes
     * address_quality_index as a SELECT over Model\Config\Source\AddressQualityIndex, whose
     * option values are derived from self::VALID_QUALITY_INDEXES, so nothing a merchant can
     * choose in the form reaches the null branch. Not thereby redundant: the value is a plain
     * core_config_data row, and a data patch, a CLI config:set, direct SQL or an env.php
     * override can still put anything into it.
     *
     * resolveQualityIndexThreshold() still returns the value RAW and uncast, so the batch cache
     * key keeps fingerprinting exactly what was compared; this method narrows it to the five
     * grades or nothing, which is why what it returns is safe to compare with. One consequence,
     * so it is not mistaken for dead code: checkQualityIndex()'s "threshold unreadable" branch is
     * no longer reached THROUGH verifyMultipleAddresses(), which returns before the request. It
     * stays because a method that judges a response may not assume its caller checked the
     * configuration first.
     *
     * @return string|null One of self::VALID_QUALITY_INDEXES, or null when the configured value
     *                     is not one of them - which is a configuration fault report, never a
     *                     verdict about an address.
     */
    private function readableQualityIndexThreshold(): ?string
    {
        $configIndex = $this->resolveQualityIndexThreshold();

        if (is_string($configIndex) && in_array($configIndex, self::VALID_QUALITY_INDEXES, true)) {
            return $configIndex;
        }

        $this->logger->info(sprintf(
            'Loqate: address_quality_index is not a recognised quality index (%s of type %s); '
            . 'no address can pass a quality bar that cannot be read. Set it to one of %s.',
            var_export($configIndex, true),
            gettype($configIndex),
            implode(', ', self::VALID_QUALITY_INDEXES)
        ));

        return null;
    }

    /**
     * Resolve the address quality index threshold a batch verdict is judged against.
     *
     * Extracted from checkQualityIndex() for exactly the reason resolveComparerAvcString()
     * was extracted from checkAVCStatus(): the batch verdict cache key has to be
     * namespaced by the threshold that was actually APPLIED, and both the comparison and
     * the key MUST keep reading it through this one method, or the key can describe a
     * threshold the verdict was not judged against - a merchant tightening the AQI would
     * then keep being served verdicts earned under the looser one for the rest of every
     * live session.
     *
     * Returns the configured value RAW and uncast on purpose: checkQualityIndex() compares
     * it with <=, and under PHP 8's comparison rules 0 <= null (true, null coerced to 0)
     * and 0 <= '' (false, compared as strings) disagree - so casting here would change
     * verdicts rather than tidy them. buildBatchVerifyCacheKey() therefore fingerprints
     * the value's TYPE as well as its text. Deliberately not memoised, for the reason
     * given on resolveComparerAvcString().
     *
     * @return mixed The configured threshold, exactly as checkQualityIndex() compares it.
     */
    private function resolveQualityIndexThreshold()
    {
        return $this->helper->getConfigValue('loqate_settings/address_settings/address_quality_index');
    }

    /**
     * Compare an AVC code against either:
     *  - user-configured thresholds (when "show_advanced_avc_settings" = Yes), or
     *  - baked-in defaults from etc/config.xml (when = No).
     *
     * The threshold resolution itself lives in resolveComparerAvcString(), because the
     * verdict cache key has to be built from the SAME resolved values - see
     * buildVerifyCacheKey().
     */
    private function checkAVCStatus($avcCode): bool
    {
        $avc = new AVC($avcCode);

        return $avc->compareTo(new AVC($this->resolveComparerAvcString()))['overall'] == 'better';
    }

    /**
     * Resolve the AVC threshold an address is judged against, as the single comparer
     * AVC string checkAVCStatus() compares the API's AVC to.
     *
     * Extracted from checkAVCStatus() so the verdict cache key can be namespaced by the
     * thresholds that were actually APPLIED rather than by the raw configuration: when
     * "show_advanced_avc_settings" is off the eight configured fields are ignored
     * entirely, so hashing raw config would invalidate every cached verdict on a change
     * that changed nothing, and would miss the change of the toggle itself. Both the AVC
     * comparison and the cache key MUST keep going through this one method, or the key
     * can describe a threshold the verdict was not judged against.
     *
     * Deliberately not memoised: it is resolved a handful of times per verified address
     * (each cache read and write and each log line builds a key, plus the comparison
     * itself), every underlying read is an in-memory ScopeConfig lookup on config
     * Magento has already loaded, and a per-instance cache would make this class behave
     * differently from the per-request object Magento actually builds - a merchant saving
     * a threshold would then be invisible until the next request even in code that reads
     * it twice.
     *
     * @return string AVC string in the "P40-U00-P0-95" shape AVC::compareTo() expects.
     */
    private function resolveComparerAvcString(): string
    {
        $advancedToggle = $this->helper->getConfigValue(
            'loqate_settings/verify_threshold_settings/show_advanced_avc_settings'
        );
        $useAdvanced = ((int)$advancedToggle) === 1;

        $defaults = [
            'avc_verification_status'                 => 'P',
            'avc_post_match_level'                    => '4',
            'avc_pre_match_level'                     => '0',
            'avc_parsing_status'                      => 'U',
            'avc_lexicon_identification_match_level'  => '0',
            'avc_context_identification_match_level'  => '0',
            'avc_postcode_status'                     => 'P0',
            'avc_matchscore'                          => '95',
        ];

        // Base path where the threshold fields actually live:
        $base = 'loqate_settings/verify_threshold_settings';

        // Helper to read a path or fall back to default if empty/missing.
        $getOrDefault = function (string $key) use ($base, $defaults) {
            $val = $this->helper->getConfigValue($base . '/' . $key);
            $val = ($val === null || $val === '') ? $defaults[$key] : (string)$val;
            return $val;
        };

        if ($useAdvanced) {
            $avcVerificationStatus   = $getOrDefault('avc_verification_status');
            $avcPostMatchLevel  = $getOrDefault('avc_post_match_level');
            $avcPreMatchLevel  = $getOrDefault('avc_pre_match_level');
            $avcParsingStatus   = $getOrDefault('avc_parsing_status');
            $avcLexiconIdentificationMatchLevel  = $getOrDefault('avc_lexicon_identification_match_level');
            $avcContextIdentificationMatchLevel  = $getOrDefault('avc_context_identification_match_level');
            $avcPostcodeStatus  = $getOrDefault('avc_postcode_status');
            $avcMatchscore   = $getOrDefault('avc_matchscore');
        } else {
            // Hard use the defaults
            $avcVerificationStatus   = $defaults['avc_verification_status'];
            $avcPostMatchLevel  = $defaults['avc_post_match_level'];
            $avcPreMatchLevel  = $defaults['avc_pre_match_level'];
            $avcParsingStatus   = $defaults['avc_parsing_status'];
            $avcLexiconIdentificationMatchLevel  = $defaults['avc_lexicon_identification_match_level'];
            $avcContextIdentificationMatchLevel  = $defaults['avc_context_identification_match_level'];
            $avcPostcodeStatus  = $defaults['avc_postcode_status'];
            $avcMatchscore   = $defaults['avc_matchscore'];
        }


        return sprintf(
            '%s%s%s-%s%s%s-%s-%s',
            $avcVerificationStatus,
            $avcPostMatchLevel,
            $avcPreMatchLevel,
            $avcParsingStatus,
            $avcLexiconIdentificationMatchLevel,
            $avcContextIdentificationMatchLevel,
            $avcPostcodeStatus,
            $avcMatchscore
        );
    }

    /**
     * Check for captured addresses, so they should not be verified if already captured
     *
     * DEFENSIVE ABOUT THE STORE'S SHAPE, AT BOTH LEVELS (LOQ-16978). The store is read
     * from the bare Controller::CAPTURED_ADDRESSES_SESSION_KEY session attribute, which
     * this module does not own exclusively: another module, an older release or a
     * truncated session payload can leave a non-array THERE, or a non-string element
     * INSIDE it. Both are checked here rather than at the two call sites (verifyAddress()
     * and verifyMultipleAddresses()) so the single relation that grants the verify bypass
     * carries the single guard, and neither caller can be the one that forgot it.
     *
     * Neither shape may cost more than a missed bypass. This runs mid-checkout, so a
     * malformed store must fall through to a normal verify - never a fatal, and never a
     * warning either: under developer mode Magento's ErrorHandler promotes E_WARNING to
     * an exception, so "degrades to a skipped loop" is not a safe resting place here.
     *
     * @param $address
     * @param $storedAddresses Normally a list of serialised addresses, but see above.
     * @return bool
     */
    private function checkForCapturedAddress($address, $storedAddresses): bool
    {
        $candidateSignature = $this->buildAddressSignature($address);
        if ($candidateSignature === '') {
            return false;
        }

        if (!is_array($storedAddresses)) {
            // A truthy non-array whole attribute - both call sites only test the value for
            // truthiness before handing it over. foreach over a scalar is an E_WARNING and
            // an exception in developer mode, so it is rejected before the loop, not by it.
            return false;
        }

        foreach ($storedAddresses as $stored) {
            if (!is_string($stored)) {
                // Not something Controller::storeCapturedAddress() wrote. Skipped rather
                // than unserialised: the production serializer
                // (Magento\Framework\Serialize\Serializer\Json) hands its argument straight
                // to json_decode(), whose first parameter is declared `string $json`, so an
                // array or object element raises a TypeError - which is an \Error, not the
                // \InvalidArgumentException caught below. This is the mirror of the guard in
                // Helper\Controller::isSameCapturedAddress() (LOQ-16978); the two read the
                // same store and must survive the same values.
                continue;
            }

            try {
                $storedData = $this->serializer->unserialize($stored);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if (!is_array($storedData)) {
                continue;
            }

            if ($this->buildAddressSignature($storedData) === $candidateSignature) {
                return true;
            }
        }

        return false;
    }

    /**
     * The captured-address equality relation, exposed so the STORE and the MATCHER agree.
     *
     * WHY THIS IS PUBLIC AND STATIC (LOQ-16978 review). Two addresses are "the same
     * captured address" if and only if they project onto the same signature - that is the
     * relation checkForCapturedAddress() grants the verify bypass on. The store's writer,
     * Helper\Controller::storeCapturedAddress(), has to de-duplicate on the SAME relation,
     * or the two drift apart: it used to compare the exact serialised bytes of the six
     * ADDRESS_CAPTURE_MAPPING fields, so two captures differing only in letter case, in
     * whitespace, in ProvinceName (which this signature excludes entirely) or in ''-versus-
     * missing Line2 were ONE address for bypass purposes but TWO slots in a bounded store -
     * which is exactly the "cycling one address through the lookup fills every slot"
     * failure the de-duplication exists to prevent.
     *
     * Static because the projection is pure - it reads only self::CAPTURED_SIGNATURE_FIELDS
     * and normalises - so Controller can call it without constructing a Validator (which
     * would need five more collaborators and would build a billable API connector).
     *
     * NO PARAMETER TYPE DECLARATION, deliberately (LOQ-16978 review). The intended input is
     * an array in the Loqate field shape (ADDRESS_CAPTURE_MAPPING's keys, or
     * parseAddress()'s output - they overlap on the fields this projects), but the internal
     * callers reach here through buildAddressSignature() with whatever
     * checkForCapturedAddress()/buildVerifyCacheSignature() were handed, which is typed
     * `mixed`, and Helper\Controller guards its own call with is_array() only on the
     * deserialised path. Declaring `array` would convert today's graceful degradation into
     * a TypeError raised mid-checkout, which is the opposite of what every other reader in
     * this class does. Anything that is not an array degrades to '': `$address[$key] ?? null`
     * yields null for a scalar or null $address (a string index with a non-numeric key
     * likewise), null normalises to '', and an all-'' projection returns the ''
     * "nothing identifiable" sentinel - so an unusable input is simply kept out of every
     * comparison instead of matching something.
     *
     * @param mixed $address Normally an array in the Loqate field shape; any other value
     *                       degrades to the '' sentinel as described above.
     * @return string '' when the address carries nothing identifiable, which keeps it out
     *                of every comparison rather than matching other empty addresses.
     */
    public static function capturedAddressSignature($address): string
    {
        $parts = [];
        foreach (self::CAPTURED_SIGNATURE_FIELDS as $key) {
            $parts[] = self::normaliseSignatureValue($address[$key] ?? null);
        }

        if (trim(implode('', $parts)) === '') {
            return '';
        }

        return implode('|', $parts);
    }

    /**
     * Build a normalised, comparable signature for an address: the projection of the fields
     * Loqate Verify keys on. Used to match an address against the captured-address store,
     * and as the base the verify cache key is derived from.
     *
     * Its field list (self::CAPTURED_SIGNATURE_FIELDS) is fixed by the captured-address
     * store it is compared against (Helper\Controller::storeCapturedAddress() writes
     * exactly ADDRESS_CAPTURE_MAPPING's keys), so no field may be added here: the verify
     * cache key extends it in buildVerifySignature()/buildVerifyCacheSignature() instead.
     *
     * Region/province (self::COUNTY_FIELD) is excluded, and here that is FIXED BY THE
     * STORE, not by any judgement about the region: Helper\Controller::storeCapturedAddress()
     * writes ADDRESS_CAPTURE_MAPPING's keys and this projection is compared field for field
     * against them. It is also harmless, because the region label is routinely rewritten
     * between saves - capture.js selects the matching option in the region <select> from the
     * SDK's ProvinceName and fires a bubbling change event (mapRegionSelectValue(),
     * view/base/web/capture.js:7896-7940), and parseAddress() re-resolves 'region' from
     * region_id - and an address the Loqate lookup itself authored must not look new and
     * get re-verified over that, the LOQ-16969 symptom. Street, city (Address3), postcode
     * and country already identify it. '' means "nothing identifiable" and keeps an address
     * out of every comparison.
     *
     * THE VERIFY CACHE KEY DOES NOT INHERIT THAT EXCLUSION: it appends the region on top of
     * this projection, at the fidelity the request itself carries - see
     * buildVerifyCacheSignature().
     *
     * The body lives on self::capturedAddressSignature(), which
     * Helper\Controller::storeCapturedAddress() also calls so that the store de-duplicates
     * on the very relation this comparison grants the bypass on. This remains the name the
     * rest of this class - and the tests - use.
     *
     * @param $address
     * @return string
     */
    private function buildAddressSignature($address): string
    {
        return self::capturedAddressSignature($address);
    }

    /**
     * THE verify cache key's signature - the one every read and every write on both paths
     * goes through: the shared base plus the region, and nothing else, ever.
     *
     * THE REGION SEGMENT IS Address4, THE REGION VALUE ACTUALLY SENT TO LOQATE. That single
     * choice is the whole design, and everything else follows from it:
     *  - parseAddress() derives Address4 from region_id when a region_id is present, and
     *    from the raw 'region' string when it is not. Keying on Address4 therefore already
     *    IS the rule "region_id when present, normalised raw region when absent" - expressed
     *    as the resolved region NAME rather than as the install-local numeric id;
     *  - keying on the resolved NAME rather than on the region_id is deliberate and
     *    load-bearing. The name is EXACTLY what was billed, so two submissions share a
     *    verdict if and only if they asked Loqate the same question - no coarsening in
     *    either direction. A numeric region_id would be FINER than what was sent, and would
     *    split the quote-path shape ('region_id' => 100) from the POST-path shape
     *    ('region' => 'Greater London') of ONE address, re-billing a single checkout twice.
     *    Pinned by
     *    ValidatorVerifyCacheTest::testBothCheckoutCallPathShapesOfOneAddressAreBilledOnce();
     *  - because region_id -> name is deterministic, both required outcomes fall out with
     *    ZERO country-specific rules. Two different region records resolve to two different
     *    names, so 'County Dublin' and 'Dublin 1' are two keys and the second is verified in
     *    its own right; and the raw label variants that arrive around ONE region_id
     *    ('Meath', 'Co. Meath', 'County Meath' alongside region_id 55) are all overwritten
     *    by parseAddress() with that record's name, so they share one key - they were never
     *    distinct requests in the first place.
     * The install-local numeric region_id therefore never enters the key at all; the
     * resolved name does. These caches are session-scoped in any case, so an install-local
     * value would have been harmless - the choice is about fidelity to the billed request,
     * not about safety.
     *
     * ACCEPTED LIMIT: with no region_id - a free-text-region country - the region TEXT is
     * the region, so re-spelling it is a different key and costs one extra verification.
     * That is the safe direction, a re-bill and never a bypass, and it replaces the old
     * collapse of label spellings, which was a bypass.
     *
     * @param $address Parsed address, as returned by parseAddress().
     * @return string Empty when the address carries nothing identifiable, which keeps it out
     *                of the cache entirely (neither read nor written).
     */
    private function buildVerifyCacheSignature($address): string
    {
        $base = $this->buildVerifySignature($this->buildAddressSignature($address), $address);

        // Read off verifyCacheSignatureFields() rather than written here as
        // [self::COUNTY_FIELD], so the list that encodes the "every field sent to Loqate is
        // in the key" invariant is the list this builder actually uses. A field list nothing
        // calls enforces nothing. The slice is that one field, by that method's construction.
        $append = array_slice($this->verifyCacheSignatureFields(), count($this->verifySignatureFields()));

        return $this->appendSignatureFields($base, $address, $append);
    }

    /**
     * The SHARED BASE the region is appended to: the captured-address signature plus every
     * remaining field parseAddress() sends to Loqate except the region - today that is just
     * the full joined street, 'Address'.
     *
     * Not a cache key on its own: buildVerifyCacheSignature() appends the region, and that
     * is the only signature anything is read or written under.
     *
     * buildAddressSignature() only covers Address1/Address2, but parseAddress() sends
     * implode(', ', $streetLines) as 'Address', and Magento supports up to four street
     * lines (customer/address/street_lines). Without this, editing line 3 or 4 would
     * not change the key and the shopper would be served a verdict for an address
     * Loqate was never asked about. Safe for the cross-call-path equivalence the fix
     * rests on: 'Address' is derived from the same normalised street lines in both the
     * POST (array street) and quote (newline-string street) shapes, so it is identical
     * in both.
     *
     * The appended fields are DERIVED from verifySignatureFields(), i.e. ultimately from
     * ADDRESS_MAPPING, so a field added to the request cannot silently stay out of the
     * key - see the invariant documented in verifyAddress().
     *
     * @param string $signature Signature returned by buildAddressSignature().
     * @param $address Parsed address the signature was built from.
     * @return string Empty when $signature is empty, so it is neither read nor written.
     */
    private function buildVerifySignature(string $signature, $address): string
    {
        // Everything the shared base covers beyond what $signature already carries.
        $append = array_slice($this->verifySignatureFields(), count(self::CAPTURED_SIGNATURE_FIELDS));

        return $this->appendSignatureFields($signature, $address, $append);
    }

    /**
     * Ordered field list of THE verify cache key.
     *
     * The load-bearing invariant of LOQ-16969, expressed as code rather than as a
     * comment: as a SET this must equal array_values(self::ADDRESS_MAPPING) - every
     * field parseAddress() actually sends to Loqate - because a verdict may only be
     * replayed for the exact address Loqate judged. If a field reached the request but
     * not this list, editing it would replay a stale verdict: a wrong "invalid" the
     * shopper cannot clear (the checkout dead-end) or a wrong "valid" that lets an
     * unverified address through.
     *
     * It is therefore DERIVED, not hand-written: the captured base is fixed by the
     * captured-address store, the region is appended last (it is the one field the shared
     * base leaves out), and anything else in ADDRESS_MAPPING is folded in automatically. So
     * adding a mapping such as 'company' => 'Company' extends the key by construction
     * instead of quietly re-opening the defect.
     *
     * @return string[] Loqate field names, in the order they appear in the key.
     */
    private function verifyCacheSignatureFields(): array
    {
        return array_merge($this->verifySignatureFields(), [self::COUNTY_FIELD]);
    }

    /**
     * Ordered field list of the SHARED BASE the region is appended to: the key's list minus
     * the region. See verifyCacheSignatureFields() for why the extras are derived from
     * ADDRESS_MAPPING.
     *
     * The region is held back from this list because it is appended LAST, in a segment of
     * its own - which is what lets the middle of the list be derived by array_diff() over
     * ADDRESS_MAPPING without the region's position depending on that mapping's declaration
     * order. No key omits it.
     *
     * @return string[] Loqate field names, in the order they appear in the key.
     */
    private function verifySignatureFields(): array
    {
        $extras = array_values(array_diff(
            array_values(self::ADDRESS_MAPPING),
            self::CAPTURED_SIGNATURE_FIELDS,
            [self::COUNTY_FIELD]
        ));

        return array_merge(self::CAPTURED_SIGNATURE_FIELDS, $extras);
    }

    /**
     * Append the normalised values of $fields to an existing signature.
     *
     * Shared by the key builder and the base it is built on, so the '' sentinel and the
     * normalisation rules can only ever be defined once.
     *
     * @param string $signature Signature to extend.
     * @param $address Parsed address the signature was built from.
     * @param string[] $fields Loqate field names to append, in order.
     * @return string Empty when $signature is empty, so the key is neither read nor written.
     */
    private function appendSignatureFields(string $signature, $address, array $fields): string
    {
        if ($signature === '') {
            return '';
        }

        $parts = [$signature];
        foreach ($fields as $field) {
            $parts[] = self::normaliseSignatureValue($address[$field] ?? null);
        }

        return implode('|', $parts);
    }

    /**
     * Normalise one address field for use inside a signature: trimmed,
     * whitespace-collapsed and upper-cased, so trivial reformatting cannot change
     * the signature. Non-scalars (Magento's street array, objects) and missing
     * values normalise to '' rather than throwing.
     *
     * Static so that self::capturedAddressSignature() - the projection Helper\Controller
     * shares - can be static too. Every call site inside this class uses self:: rather
     * than $this->: both resolve, but $this-> on a static method reads as an oversight.
     *
     * @param $value
     * @return string
     */
    private static function normaliseSignatureValue($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        // '|' joins the signature parts, so it must not survive inside one: without
        // this, ['A', 'B|C'] and ['A|B', 'C'] would render the same signature and
        // two different addresses could share a verdict.
        //
        // Note this WIDENS the captured-address equality relation slightly (a typed
        // "1 High|St" now matches a captured "1 High St"). That is safe because the
        // substitution is applied to both sides of every comparison, so nothing that
        // matched before can stop matching; it only adds pairs that differ by a
        // character no postal address contains.
        $value = str_replace('|', ' ', (string)$value);

        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)));
    }

    /**
     * Read a previously stored verify verdict for the given address signature.
     *
     * Only the verdict flag and the key-scheme stamp are cached; the message is rebuilt here
     * so no Phrase ever passes through the serializer (its translation would be frozen at the
     * store view that cached it, and a serializer without object support would return an
     * unusable value). Defensive like checkForCapturedAddress(): an unexpected shape
     * degrades to "not cached" - one extra API call - and never throws in checkout.
     *
     * @param string $signature
     * @return array|null Verdict array, or null when nothing usable is cached.
     */
    private function getCachedVerifyResult(string $signature): ?array
    {
        $key = $this->buildVerifyCacheKey($signature);
        if ($key === '') {
            return null;
        }

        $store = $this->shopperSession->getData(self::VERIFY_CACHE_SESSION_KEY);
        if (!is_array($store) || !isset($store[$key]) || !is_string($store[$key])) {
            return null;
        }

        try {
            $verdict = $this->serializer->unserialize($store[$key]);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($verdict) || !isset($verdict['error'])) {
            return null;
        }

        // A verdict may only answer a lookup made under the key scheme it was written for. A
        // session outlives a deploy, so an entry carrying another stamp sits under a key that
        // no longer names the same address: discard it and verify once more. Compared
        // strictly, so a serializer that hands back "1" cannot pass for 1.
        if (($verdict['schema'] ?? null) !== self::VERIFY_KEY_SCHEMA_VERSION) {
            return null;
        }

        if (!$verdict['error']) {
            return ['error' => false];
        }

        return ['error' => true, 'message' => __('The provided address is invalid.')];
    }

    /**
     * Store a definitive verify verdict against the given address signature.
     *
     * Only the boolean verdict and self::VERIFY_KEY_SCHEMA_VERSION are stored, never a
     * message (see getCachedVerifyResult()). Kept in the customer session only (see
     * self::VERIFY_CACHE_SESSION_KEY) and bounded to self::VERIFY_CACHE_LIMIT
     * entries with FIFO eviction, so in practice the address currently being
     * checked out - the one replayed on every checkout call path - survives while
     * older verdicts age out.
     *
     * @param string $signature
     * @param bool $error Whether the API rejected the address.
     * @return void
     */
    private function storeVerifyResult(string $signature, bool $error): void
    {
        $key = $this->buildVerifyCacheKey($signature);
        if ($key === '') {
            return;
        }

        $store = $this->shopperSession->getData(self::VERIFY_CACHE_SESSION_KEY);
        if (!is_array($store)) {
            $store = [];
        }

        // Drop any existing entry first, then re-insert at the end.
        //
        // PHP keeps an existing key's insertion position on overwrite, and a write only
        // ever follows a cache MISS, so this is reachable in exactly one situation: the
        // key is present but unreadable (a corrupted or truncated session payload, a
        // serializer that throws, another module writing to the key), so the read missed
        // and the verdict is re-fetched under a key that is already there. In that case
        // it does two observable things: the refreshed verdict is treated as the newest
        // rather than keeping the age of the entry it replaces, and - because the store
        // shrinks by one before the eviction loop - refreshing an entry while the cache
        // is FULL no longer evicts an UNRELATED verdict that would then have to be
        // re-billed. Without the unset, replacing an existing key at the limit costs one
        // other address its cached verdict.
        unset($store[$key]);

        // Cache keys always contain '|' separators, so they are never numeric keys
        // and array_shift() cannot renumber them. The $store !== [] guard keeps this
        // terminating even if VERIFY_CACHE_LIMIT is ever set to 0 or below, where
        // shifting an already-empty array would otherwise spin forever inside a
        // checkout request.
        while ($store !== [] && count($store) >= self::VERIFY_CACHE_LIMIT) {
            array_shift($store);
        }

        // Stamped with the key scheme, so a session that spans a deploy changing the key
        // shape cannot be answered by an entry built under a different one.
        $store[$key] = $this->serializer->serialize([
            'error' => $error,
            'schema' => self::VERIFY_KEY_SCHEMA_VERSION,
        ]);
        $this->shopperSession->setData(self::VERIFY_CACHE_SESSION_KEY, $store);
    }

    /**
     * Namespace an address signature into its session cache key.
     *
     * A verdict is a function of the address AND of the AVC thresholds it was judged
     * against, so the key carries both, in two namespace segments ahead of the signature:
     *
     *  - the STORE VIEW. Every threshold field is showInStore="1" and read at SCOPE_STORE
     *    (Data::getConfigValue()), while ONE session can span store views (?___store=, a
     *    language switcher), so the store view is the exact scope the configuration behind
     *    a verdict is resolved at;
     *  - a FINGERPRINT of the resolved thresholds. Scoping by store view alone would leave
     *    live sessions holding verdicts judged against thresholds a merchant has since
     *    changed, and would hand an admin re-testing an address
     *    (Plugin\Admin\ValidateAddress.php:42) the verdict from before the change. The
     *    fingerprint is taken over the RESOLVED comparer values
     *    (resolveComparerAvcString()), not the raw configuration, so it tracks what was
     *    actually applied: changing the eight threshold fields while
     *    "show_advanced_avc_settings" is off changes nothing and correctly invalidates
     *    nothing, whereas flipping that toggle changes everything and correctly
     *    invalidates everything.
     *
     * Truncated to 12 hex characters: this only has to separate namespaces inside one
     * shopper's session, and the session payload should not carry 64 characters per entry.
     * Hex, so it can never contain the '|' the signature parts are joined with.
     *
     * @param string $signature
     * @return string Empty when $signature is empty, keeping the '' sentinel intact.
     */
    private function buildVerifyCacheKey(string $signature): string
    {
        if ($signature === '') {
            return '';
        }

        $thresholdFingerprint = substr(hash('sha256', $this->resolveComparerAvcString()), 0, 12);

        return $this->helper->getCurrentStore() . '|' . $thresholdFingerprint . '|' . $signature;
    }

    /**
     * Debug-log the outcome of one verify cache lookup, so the drop in billable
     * Cleansing requests can be reconciled without waiting for the Loqate invoice:
     * misses map one-to-one onto billable calls.
     *
     * One key means one token per address: the miss, and every hit that later replays the
     * verdict it paid for, are reported under the same hash and can be counted together.
     * Two addresses Loqate is asked about separately - including one address in two
     * different regions - get two tokens, which is what an operator counting misses should
     * expect to see.
     *
     * HOW TO TURN THIS ON: Logger/Handler.php pins $loggerType = Logger::INFO, so DEBUG
     * records are dropped and nothing is written by default. Lower that handler to
     * \Monolog\Logger::DEBUG (or attach a debug handler to Loqate\ApiIntegration\Logger
     * \Logger in etc/di.xml) and the lines appear in var/log/loqate_log_file.log.
     *
     * NEVER logs the address or the signature: both are customer PII, and a log file is
     * not the customer session - it is world-readable to anyone with server access, it
     * outlives the session and it is shipped to log aggregators. A truncated SHA-256 of
     * the namespaced key is enough to correlate the hits and misses of one address with
     * each other and with a request count, which is all the reconciliation needs.
     *
     * @param string $outcome 'hit' or 'miss'.
     * @param string $signature Signature the lookup was made with; never logged as-is.
     * @return void
     */
    private function logVerifyCacheOutcome(string $outcome, string $signature): void
    {
        $key = $this->buildVerifyCacheKey($signature);

        $this->logger->debug(sprintf(
            'Loqate verify cache %s [key=%s]',
            $outcome,
            $key === '' ? 'unkeyed' : substr(hash('sha256', $key), 0, 12)
        ));
    }

    /**
     * Remember one batch verdict - in BOTH memories, or in NEITHER (LOQ-17148).
     *
     * THE SINGLE GATE FOR "NEVER REMEMBER A VERDICT WE COULD NOT READ". It takes
     * checkQualityIndex()'s three-state answer, not a bool, so the decision is made where the
     * distinction still exists: null is a FAULT REPORT and not a verdict, so it is written
     * nowhere and the identical address is billed again on every occurrence, this run and every
     * later one. That cost is accepted because one connector fault, one "no match for this
     * address" or one bad credential must not brand every matching row in an import file invalid
     * for the rest of the run - see the ACCEPTED LIMITS on verifyMultipleAddresses().
     *
     * WHY THE RULE IS HERE AND NOWHERE ELSE. It used to be delegated to checkQualityIndex()
     * failing closed plus storeBatchVerifyResult() storing no failures: true at the time, but
     * true only as a SIDE EFFECT of failures never being remembered, so it stopped being true
     * the moment one lifetime started remembering them. Stating it once, at the only place both
     * memories are written, is what keeps the two lifetimes from drifting apart. The call site
     * holds no readability test, and neither does storeBatchVerifyResult(), which keeps its own
     * PASSES-ONLY guard for its own separate reason (see its docblock).
     *
     * THE TWO POLARITIES ARE ASYMMETRIC BY DESIGN: a readable rejection is remembered for the
     * RUN and never for the SESSION, measured on self::BATCH_VERIFY_CACHE_LIMIT.
     *
     * @param string $signature Signature the verdict was earned under.
     * @param bool|null $verdict checkQualityIndex()'s answer, passed through UNCOERCED.
     * @return void
     */
    private function rememberBatchVerdict(string $signature, ?bool $verdict): void
    {
        if ($verdict === null) {
            return;
        }

        $this->rememberRunScopedBatchVerdict($signature, $verdict);

        // Passes only, and its own guard decides that - see storeBatchVerifyResult().
        $this->storeBatchVerifyResult($signature, $verdict);
    }

    /**
     * Read this RUN's verdict for the given address signature, of either polarity.
     *
     * @param string $signature
     * @return bool|null The remembered verdict, or null when this run holds none - which is
     *                   the same "ask Loqate" answer getCachedBatchVerifyResult() gives, so
     *                   the two compose with a plain ?? at the call site.
     */
    private function getRunScopedBatchVerdict(string $signature): ?bool
    {
        $key = $this->buildBatchVerifyCacheKey($signature);
        if ($key === '') {
            // The '' sentinel: an address carrying nothing identifiable is kept out of every
            // memory rather than sharing one with every other unidentifiable address.
            return null;
        }

        $this->discardRunScopedVerdictsIfShopperChanged();

        return $this->runScopedBatchVerdicts[$key] ?? null;
    }

    /**
     * Remember one readable verdict for the rest of this run.
     *
     * Deliberately UNBOUNDED, unlike the session store. It holds one array entry per distinct
     * address in one import file and is never serialised, so on every runtime this module ships
     * on the cost is bounded by the file the merchant chose to import - whereas the session
     * store is re-read and re-written on every subsequent request of the browser session, which
     * is the whole reason THAT one has a limit (see self::BATCH_VERIFY_CACHE_LIMIT). What
     * "unbounded" costs under a long-lived worker, and why no eviction policy is shipped for
     * one, is stated on self::$runScopedBatchVerdicts.
     *
     * @param string $signature
     * @param bool $verdict Readable verdict of either polarity; nulls are stopped by
     *                      rememberBatchVerdict(), which is the only caller.
     * @return void
     */
    private function rememberRunScopedBatchVerdict(string $signature, bool $verdict): void
    {
        $key = $this->buildBatchVerifyCacheKey($signature);
        if ($key === '') {
            return;
        }

        $this->discardRunScopedVerdictsIfShopperChanged();

        $this->runScopedBatchVerdicts[$key] = $verdict;
    }

    /**
     * Discard this run's remembered verdicts unless the shopper-scoped stores have demonstrably
     * belonged to one identity ever since they were earned (LOQ-17148, LOQ-16978).
     *
     * NOTE THE POLARITY, because it is the safe one and it is easy to invert by accident: the
     * map is KEPT only on a positive answer from the guard - an unmoved ownership epoch - and
     * discarded in every other case, including the ones that flushed nothing. See
     * ShopperScopedSessionStores::ownershipGeneration() for what the epoch counts and why an
     * adoption (no marker recorded, so nothing to flush) advances it just as a flush does.
     *
     * The map is verdict data, so it has the OWNERSHIP lifetime of the session verdict stores
     * and not merely the request's: one Validator can outlive a mid-request identity change,
     * and a plain request-scoped map would then answer the new shopper out of the previous
     * shopper's verdicts while the guard had just flushed the seven stores beside it - reopening
     * exactly the leak ShopperScopedSessionStores exists to close, through a store that class
     * cannot see. Pinned by
     * ShopperScopedSessionStoresTest::testABatchVerdictDoesNotSurviveALogin().
     *
     * ASKING IS WHAT ENFORCES IT: ownershipGeneration() runs the ownership check itself before
     * reporting, so this is correct even on the import path, where $checkForCaptured is false
     * and the run map is consulted before any session attribute is touched. Enrolling the map in
     * the guard's own model, rather than comparing customer ids here, keeps ONE definition of
     * "the shopper changed".
     *
     * @return void
     */
    private function discardRunScopedVerdictsIfShopperChanged(): void
    {
        $generation = $this->shopperSession->ownershipGeneration();
        if ($generation === $this->runScopedVerdictsGeneration) {
            return;
        }

        // The first lookup of this instance (nothing to discard), or ownership re-established
        // since the last one - a flush, or an adoption after the session storage was emptied
        // under us. All are answered by starting from empty, which is why no special case is
        // needed for the null the property starts out holding.
        $this->runScopedBatchVerdicts = [];
        $this->runScopedVerdictsGeneration = $generation;
    }

    /**
     * Read a previously stored batch verdict for the given address signature.
     *
     * Only ever returns true or null, because only passing verdicts are stored: null means
     * "not cached, bill it". Defensive in the same way as getCachedVerifyResult() - any
     * unexpected shape degrades to "not cached", costing one extra address on the invoice,
     * and never throws in the middle of an import.
     *
     * The stored member is 'valid', where the single-address cache stores 'error'. That is
     * a second, independent guard on top of the separate session attribute: even if the two
     * stores were ever conflated, an entry written by storeVerifyResult() would fail the
     * shape check here and degrade to a miss rather than being read as a verdict.
     *
     * @param string $signature
     * @return bool|null true for a cached pass, null when nothing usable is cached.
     */
    private function getCachedBatchVerifyResult(string $signature): ?bool
    {
        $key = $this->buildBatchVerifyCacheKey($signature);
        if ($key === '') {
            return null;
        }

        $store = $this->shopperSession->getData(self::BATCH_VERIFY_CACHE_SESSION_KEY);
        if (!is_array($store) || !isset($store[$key]) || !is_string($store[$key])) {
            return null;
        }

        try {
            $verdict = $this->serializer->unserialize($store[$key]);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($verdict) || ($verdict['valid'] ?? null) !== true) {
            return null;
        }

        // Written under an older key scheme, so the key no longer names the address it was
        // derived from. Discarding re-verifies once; replaying would be a false ACCEPT,
        // because this store holds only passes. Same check as getCachedVerifyResult().
        if (($verdict['schema'] ?? null) !== self::VERIFY_KEY_SCHEMA_VERSION) {
            return null;
        }

        return true;
    }

    /**
     * Store a batch verdict against the given address signature - if, and only if, it
     * PASSED.
     *
     * NEVER CACHING A FAILURE IN THE SESSION is load-bearing, not tidiness. Three things rest
     * on it: an admin or an import can never be stranded on a replayed rejection in a LATER
     * REQUEST, because there is none to replay; the stored shape stays 'valid'-only, which is
     * the second guard against this store and the single-address one being read for each other
     * (see getCachedBatchVerifyResult()); and the bounded FIFO store keeps spending its 200
     * slots on the sparse PASSES, which is what makes it worth having at all on a default
     * install where most rows fail. self::BATCH_VERIFY_CACHE_LIMIT carries the measurements,
     * including why caching failures here would make a second run cost MORE. The guard
     * therefore lives HERE rather than at the call site, so a future caller cannot reintroduce
     * cached failures by forgetting it.
     *
     * NOTE THE SCOPE OF THAT WORD "NEVER": it is about THIS store. A readable rejection IS
     * remembered for the length of one import RUN, in self::$runScopedBatchVerdicts, which is
     * what LOQ-17148 delivers and what this store structurally could not - see
     * rememberBatchVerdict(), the one caller of this method.
     *
     * WHAT THIS GUARD NO LONGER RELIES ON, since the reasoning changed and the old note would
     * now be actively misleading. It used to lean on checkQualityIndex() failing closed: an
     * unreadable AQI became false, and a false was not cached, so unreadable answers were kept
     * out of this store as a side effect. That delegation is gone. checkQualityIndex() now
     * answers null for anything it could not read, and rememberBatchVerdict() drops a null
     * before either memory is touched - so "no unreadable verdict is ever remembered" is now an
     * explicit rule with one site, and this method's own guard means only what it says: this
     * store holds passes.
     *
     * Bounding and eviction mirror storeVerifyResult() exactly - unset-then-append so a
     * refreshed entry does not cost an unrelated address its verdict, FIFO array_shift()
     * under a $store !== [] guard so the loop terminates even if the limit is ever set to 0
     * or below, and only the boolean verdict is serialised so no Phrase ever reaches the
     * serializer.
     *
     * @param string $signature
     * @param bool $valid Whether the address passed the quality index check.
     * @return void
     */
    private function storeBatchVerifyResult(string $signature, bool $valid): void
    {
        if (!$valid) {
            return;
        }

        $key = $this->buildBatchVerifyCacheKey($signature);
        if ($key === '') {
            return;
        }

        $store = $this->shopperSession->getData(self::BATCH_VERIFY_CACHE_SESSION_KEY);
        if (!is_array($store)) {
            $store = [];
        }

        // Drop any existing entry first, then re-insert at the end - see storeVerifyResult()
        // for why this matters at the limit. The one path that reaches this with the key
        // already present is the same address appearing twice in ONE batch: both copies miss
        // the cache in the pre-flight pass, so both are billed and the second response row
        // refreshes the entry the first one wrote. That double billing is tracked as
        // LOQ-17015; de-duplicating it must not disturb the one-response-row-per-sent-item
        // assumption the count guard in verifyMultipleAddresses() depends on - see the
        // ACCEPTED LIMITS note on that method.
        unset($store[$key]);

        // Cache keys always contain '|' separators, so they are never numeric keys and
        // array_shift() cannot renumber them.
        while ($store !== [] && count($store) >= self::BATCH_VERIFY_CACHE_LIMIT) {
            array_shift($store);
        }

        // 'valid' rather than 'error' keeps the payload shape distinct from the single-address
        // cache's, which is the second guard against the two caches answering each other's
        // lookups. The schema stamp is a different guard, against a session that outlives a
        // deploy; see self::VERIFY_KEY_SCHEMA_VERSION for why this store needs it MORE than
        // the other one.
        $store[$key] = $this->serializer->serialize([
            'valid' => true,
            'schema' => self::VERIFY_KEY_SCHEMA_VERSION,
        ]);
        $this->shopperSession->setData(self::BATCH_VERIFY_CACHE_SESSION_KEY, $store);
    }

    /**
     * Namespace a batch address signature into its session cache key.
     *
     * Same shape as buildVerifyCacheKey() - store view, threshold fingerprint, signature -
     * for the same reasons, but over a DIFFERENT threshold: this path's verdicts come from
     * the address quality index (checkQualityIndex()), so the fingerprint is taken over
     * loqate_settings/address_settings/address_quality_index via
     * resolveQualityIndexThreshold(), NOT over resolveComparerAvcString(). Hashing the AVC
     * comparer here would namespace an AQI verdict by a threshold it was never judged
     * against: tightening the AQI would not invalidate anything, and a live session would
     * keep passing rows the merchant has just decided are too poor to accept.
     *
     * The store view segment is required for the same reason as over there: the threshold
     * is read at SCOPE_STORE (Data::getConfigValue()) while one session can span store
     * views (?___store=, a language switcher).
     *
     * Truncated to 12 hex characters, so it can never contain the '|' the signature parts
     * are joined with and the session payload does not carry 64 characters per entry.
     *
     * @param string $signature
     * @return string Empty when $signature is empty, keeping the '' sentinel intact.
     */
    private function buildBatchVerifyCacheKey(string $signature): string
    {
        if ($signature === '') {
            return '';
        }

        // TYPE and text, because the threshold is compared raw: null, '' and 0 behave
        // differently under <= in PHP 8 (see resolveQualityIndexThreshold()), so they must
        // not share a fingerprint. Non-scalars contribute their type alone - a non-scalar
        // threshold makes the comparison meaningless anyway, and (string) would throw.
        $threshold = $this->resolveQualityIndexThreshold();
        $thresholdSource = gettype($threshold) . ':' . (is_scalar($threshold) ? (string)$threshold : '');
        $thresholdFingerprint = substr(hash('sha256', $thresholdSource), 0, 12);

        return $this->helper->getCurrentStore() . '|' . $thresholdFingerprint . '|' . $signature;
    }

    /**
     * Debug-log the outcome of one batch verdict cache lookup, so the drop in billable
     * addresses on the admin/import path can be reconciled without waiting for the Loqate
     * invoice: misses map one-to-one onto billed addresses.
     *
     * A sibling of logVerifyCacheOutcome() rather than a call to it, for one concrete
     * reason: that method hashes buildVerifyCacheKey(), the AVC-namespaced key, which for
     * an address on this path names a cache entry that does not exist - the log line would
     * correlate with the wrong cache.
     *
     * ONE 'hit' TOKEN FOR BOTH MEMORIES (LOQ-17148). A verdict remembered for the run and one
     * replayed from the session store are logged identically, and deliberately so: what this
     * instrumentation is for is reconciling billed addresses against the invoice, misses map
     * one-to-one onto billed addresses either way, and splitting the token would break every
     * existing counter for a distinction no operator is counting. The two are still
     * distinguishable where it matters - a run-scoped hit leaves no entry in the session store,
     * which is what the tests read.
     *
     * The literal "family=strict" segment is a vestige of the single-address cache once
     * having had two key shapes. There is one signature builder now, so it distinguishes
     * nothing; it is left in place because this line's format is pinned by
     * ValidatorBatchVerifyCacheTest and existing log tooling parses it.
     *
     * Same PII rule, which is absolute: NEVER log the address or the raw signature. A log
     * file is not the customer session - it is readable by anyone with server access, it
     * outlives the session and it is shipped to log aggregators. A truncated SHA-256 of the
     * namespaced key correlates the hits and misses of one address with each other and with
     * a request count, which is all the reconciliation needs. See logVerifyCacheOutcome()
     * for how to turn DEBUG records on.
     *
     * @param string $outcome 'hit' or 'miss'.
     * @param string $signature Signature the lookup was made with; never logged as-is.
     * @return void
     */
    private function logBatchVerifyCacheOutcome(string $outcome, string $signature): void
    {
        $key = $this->buildBatchVerifyCacheKey($signature);

        $this->logger->debug(sprintf(
            'Loqate batch verify cache %s [family=strict, key=%s]',
            $outcome,
            $key === '' ? 'unkeyed' : substr(hash('sha256', $key), 0, 12)
        ));
    }
}
