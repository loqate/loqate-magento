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
     * Every value address_quality_index is allowed to hold, worst ('E') to best ('A').
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
     * reached through ShopperScopedAddressStores, which flushes it whenever the logged-in
     * customer changes (LOQ-16978).
     *
     * An ALIAS of ShopperScopedAddressStores::VERIFY_CACHE_SESSION_KEY, kept so every existing
     * reference to Validator::VERIFY_CACHE_SESSION_KEY still resolves. The literal lives on
     * the guard because the guard is what enforces this attribute's lifetime, and holding
     * it here made the dependency circular - the guard's flush list pointed at this class
     * while this class constructs the guard.
     */
    const VERIFY_CACHE_SESSION_KEY = ShopperScopedAddressStores::VERIFY_CACHE_SESSION_KEY;

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
     * it is reached through ShopperScopedAddressStores so that it is flushed when the logged-in
     * customer changes (LOQ-16978) - subject to the ACCEPTED LIMITS on that class, which
     * name THIS attribute specifically: it is written only from adminhtml, where the
     * customer session carries no customer id, so the guard's flush is a no-op for it and
     * an admin-user swap inside one browser session is not covered.
     *
     * An ALIAS of ShopperScopedAddressStores::BATCH_VERIFY_CACHE_SESSION_KEY, for the same
     * dependency-direction reason as self::VERIFY_CACHE_SESSION_KEY above.
     */
    const BATCH_VERIFY_CACHE_SESSION_KEY = ShopperScopedAddressStores::BATCH_VERIFY_CACHE_SESSION_KEY;

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
     * WHAT DEDUPES THE IMPORT PATH IS NOT THIS STORE (LOQ-17148). An import run chunks at 100
     * rows and verifies every chunk INSIDE ONE PHP REQUEST, so the lifetime that matters there
     * is the RUN, not the session. That is served by the run-scoped verdict map on this
     * instance - see rememberBatchVerdict() and getRunScopedBatchVerdict() - which remembers
     * BOTH polarities and is unbounded by this limit. Measured on the file shapes that showed
     * the defect: 29% fewer billed addresses on a 1000-row file holding 700 distinct
     * addresses, and 58.8% fewer on 1000 rows holding 400. Before it, a REJECTED address was
     * re-sent - and so re-billed, the Cleansing API being billed per ADDRESS - in every chunk
     * it appeared in, because this store caches PASSES ONLY (storeBatchVerifyResult()) and
     * address_quality_index ships as 'A' (etc/config.xml), the strictest grade, so on a
     * default install most rows fail and were never cacheable here in the first place.
     *
     * THIS STORE IS DELIBERATELY UNCHANGED BY LOQ-17148 - passes only, FIFO, 200 - and the
     * measurements behind each rejected alternative are recorded here because every one of
     * them looks like an obvious improvement:
     *  - CACHING FAILURES HERE IS A REGRESSION on the target workload, not an omission.
     *    Eviction is FIFO and bounded, so failures crowd out the sparse passes the store
     *    currently manages to keep: for 1000 distinct rows at a 5% pass rate, a second run
     *    goes from 950 billed addresses to 1000, and for 500 rows at 5% from 475 to 500. It is
     *    never better for any file larger than this limit. (An earlier revision of this
     *    docblock promised LOQ-17148 would deliver exactly that. It was wrong, and this
     *    paragraph is the correction.) It would also strand a merchant: a rejection that
     *    outlived the request would be replayed after they corrected the file or loosened the
     *    threshold, with no request made and no way to clear it.
     *  - "RETAIN ON FULL" (freeze the store instead of evicting) is rejected: one Check Data
     *    click on a file of 200 rows or more would permanently fill the store for the rest of
     *    that admin's browser session - adminhtml never flushes it, see
     *    ShopperScopedAddressStores' ACCEPTED LIMITS - destroying the admin-order-create win
     *    below, which is the win LOQ-16976 actually delivered.
     *  - SIZING THE STORE TO THE FILE is rejected on measured cost: ~147 bytes per entry, so
     *    1000 entries is ~141 KB and 5000 is ~712 KB of raw session data. Magento reads and
     *    writes the WHOLE session on every request, so a 5000-entry store costs ~1.4 MB of
     *    session I/O on EVERY unrelated admin page load for the life of the session (~278 MB
     *    over 200 page loads) to serve a re-run the merchant may never perform.
     *
     * RESIDUAL EXPOSURE, stated plainly rather than left to be rediscovered from an invoice.
     * CROSS-RUN dedupe is not delivered for a file with more than this many distinct
     * addresses: a re-run in the same browser session (a second Check Data click, a second
     * import) re-bills essentially every row, because a sequential cyclic scan through a
     * smaller FIFO cache has a ~0% hit rate. For a programmatic, CLI or cron-driven import it
     * is 0% BY CONSTRUCTION and no cache size would change that - each process starts a fresh
     * session id, so nothing one run writes is ever found by the next. Raising this constant
     * does not fix either case; it is inherent to FIFO over a working set larger than the
     * cache, and to session-scoped storage outside a browser session.
     *
     * NOT THE SAME TICKET AS INTRA-CHUNK DUPLICATES, AND LOQ-17015 IS NOT CLOSED BY THIS WORK.
     * LOQ-17148 dedupes ACROSS the chunks of one run. Two copies of an address in ONE assembled
     * payload are still both billed on that address's FIRST appearance in the run, because
     * nothing is remembered until the response returns, so both copies miss; every LATER
     * appearance is answered from the run map, two copies in one batch included. That residue
     * is LOQ-17015 and it is riskier to fix - it changes the payload row arithmetic and must be
     * done TOGETHER with the count($response) !== count($sentItems) guard in
     * verifyMultipleAddresses(), never by comparing against the pre-dedupe count. See the
     * ACCEPTED LIMITS on that method for the boundary stated exactly.
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
     * @var ShopperScopedAddressStores The captured-address store and the two verdict caches,
     *      behind the shopper-ownership guard. The raw customer session is deliberately
     *      NOT kept as well: keeping it would leave a way to reach those stores without
     *      the guard - see ShopperScopedAddressStores.
     */
    private ShopperScopedAddressStores $shopperSession;

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
     * NEVER SERIALISED AND NEVER PERSISTED. It dies with the request, by construction: it is a
     * plain property, nothing writes it to the session, and it is deliberately NOT a static or
     * a Registry entry (either would serve one shopper's verdicts to another - see
     * self::VERIFY_CACHE_SESSION_KEY). That mortality is a FEATURE and is asserted: a rejection
     * must never outlive the request, or a merchant who corrects the file or loosens the
     * threshold keeps being told "invalid" with no request made and no way to clear it.
     *
     * KEYED ON THE KEY, NOT ON THE RAW SIGNATURE. buildBatchVerifyCacheKey() is where the
     * '' -"nothing identifiable" sentinel is honoured and where the store view and the AQI
     * threshold fingerprint enter, so keying on the signature would remember verdicts across a
     * threshold change and would file two unidentifiable addresses under one key.
     *
     * ONLY READABLE VERDICTS ARE IN HERE. An unreadable AQI or an unreadable threshold produces
     * a rejection that is a FAULT REPORT, not a verdict, and is remembered nowhere - see
     * rememberBatchVerdict(), which is the single gate for both lifetimes.
     *
     * @var array<string, bool> Batch cache key => verdict.
     */
    private array $runScopedBatchVerdicts = [];

    /**
     * The ShopperScopedAddressStores generation self::$runScopedBatchVerdicts was earned under,
     * or null before this instance has asked (LOQ-17148, LOQ-16978).
     *
     * The map holds the same kind of data as the session verdict stores - licences to skip a
     * billable verify - so it must have the same OWNERSHIP lifetime, not merely the same
     * request. A request-scoped map does NOT get that for free: one Validator can outlive a
     * mid-request identity change, and it would then answer the new shopper from the previous
     * shopper's verdicts while the guard had just flushed the three stores beside it. So the
     * map is enrolled in the guard's own ownership model rather than checked ad hoc at the call
     * site: see ShopperScopedAddressStores::ownershipGeneration() and
     * discardRunScopedVerdictsIfShopperChanged().
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
     * @param Session $session Wrapped in a ShopperScopedAddressStores and not kept raw, so the
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
        $this->shopperSession = new ShopperScopedAddressStores($session);
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
     *  - self::$runScopedBatchVerdicts, this INSTANCE's map, holding BOTH polarities. Its
     *    lifetime is one import RUN, because that is what one instance is here: an import file
     *    is chunked at 100 rows and every chunk is verified against the same Validator inside
     *    one PHP request. This is what stops a REJECTED address being re-billed in every chunk
     *    it appears in, which the session store structurally could not do, and it is unbounded
     *    by self::BATCH_VERIFY_CACHE_LIMIT so an evicted PASS is not re-billed mid-run either;
     *  - the SESSION store, holding PASSES ONLY and bounded, which is what survives to a LATER
     *    REQUEST (the re-submitted admin order, a re-run import that fits inside the limit).
     * Both are keyed by buildBatchVerifyCacheKey(), so one address is one key in both and a
     * threshold or store-view change invalidates both at once. A rejection is deliberately
     * asymmetric - remembered for the run, never for the session - and
     * self::BATCH_VERIFY_CACHE_LIMIT records the measurements behind that asymmetry, including
     * why widening the session store is a REGRESSION rather than the obvious next step.
     *
     * RETURN SHAPE - load-bearing in two ways, do not "simplify" either:
     *  - one entry per input address, under the INPUT's OWN KEY. OrderSave.php:51-57
     *    reports that key to the admin, and before LOQ-16977 the keys of a mixed batch
     *    were wrong: the original code recovered them with
     *    array_search(false, $addressesToCheck) over an array whose values were all
     *    truthy, so it always got false, coerced to key 0 - every response row overwrote
     *    $result[0], and the captured addresses' own verdicts were never merged into
     *    $result at all. The mapping is now an explicit parallel array, see $sentItems.
     *  - in the INPUT's OWN ORDER, because ValidateImportAddress.php:94 array_merge()s the
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
     * itself: Verify::verifyAddress() (vendor/lqt/api-connector/src/Client/Verify.php:50-52)
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
     * is_array() fails (vendor/lqt/api-connector/src/Client/Verify.php:50) and the connector
     * returns ['error' => true, 'message' => 'Unexpected error occurred.'] (same file, :57). The
     * second makes HttpClient::searchForError() report an error, so post() THROWS
     * (vendor/lqt/api-connector/src/Client/Http/HttpClient.php:53-55 over :72-74) and
     * Verify::verifyAddress() catches it into that same shape (Verify.php:53-55). Both were
     * therefore already answered by the isset($response['error']) branch below, before this
     * guard existed (all traced on PHP 8.3). Such an envelope does arrive as [] in two
     * corners, both of them just further instances of the class above rather than new ones,
     * and both because searchForError()'s return is tested for TRUTHINESS rather than for
     * false (HttpClient.php:53 over :72-74):
     *  - a FALSY 'Description' - '' and '0', but equally JSON null, 0 and false;
     *  - an ABSENT 'Description' next to a present 'Number', which HttpClient.php:73 reads
     *    UNGUARDED. This one is decided by the error handler in force, not by the body: PHP 8
     *    raises "Undefined array key" and evaluates the read to null, so with no throwing
     *    handler the return is falsy and the whole envelope reaches array_column() as [] -
     *    the count guard's branch. Under Magento's own handler
     *    (Magento\Framework\App\ErrorHandler, registered in Bootstrap::run() and throwing
     *    for any level error_reporting() reports) the same warning becomes an \Exception,
     *    which Verify::verifyAddress()'s catch (Throwable) converts into
     *    ['error' => true, ...] - the OTHER branch. So one identical body is classified two
     *    different ways depending on the entry point (a web request, which registers that
     *    handler, versus a context that never calls Bootstrap::run()). NOT on
     *    developer-versus-production mode: app/bootstrap.php sets error_reporting(E_ALL) and
     *    Bootstrap::run() registers the handler, both irrespective of MAGE_MODE. Either
     *    branch is safe - one returns false, the other returns false - which is why this is
     *    documented rather than defended against here.
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
     *  - a row present in the response but carrying no readable AQI is answered INVALID and
     *    is remembered NOWHERE - not in the session, and not for the rest of the run either.
     *    That is a fail-closed rejection, decided by checkQualityIndex() answering null, and
     *    reached for a record whose 'Matches' list is present but empty - the row-count guard
     *    below cannot catch that shape, because array_column() PRESERVES such a record, see
     *    the attribution loop for the demonstration. It is a deliberate rejection, not a gap:
     *    it costs one re-attempt on a response we could not read, where the previous behaviour
     *    reported "no match found" as VALID. The price of remembering it would be far higher -
     *    ONE connector fault or one bad credential would brand every matching row in the file
     *    invalid for the rest of the run, sending the merchant to edit rows Loqate never
     *    rejected.
     *  - LOQ-17015 IS NOT RESOLVED BY LOQ-17148, and here is the boundary stated exactly, so
     *    that ticket is neither closed on a partial fix nor re-implemented for work already
     *    done. VERIFIED against this implementation, not merely reasoned about: two copies of
     *    an address in one batch, on that address's FIRST appearance in the run, are BOTH
     *    billed - both copies miss both memories in the pre-flight pass, because nothing is
     *    remembered until the response comes back. What LOQ-17148 does change is every LATER
     *    appearance: once the run map holds a readable verdict for an address, every copy of it
     *    in every subsequent batch is answered from memory, including two copies inside the
     *    SAME batch. So the residue of LOQ-17015 is exactly "the first batch in which an
     *    address appears more than once", and it is bounded by ONE duplicate charge per
     *    distinct address per run rather than one per occurrence.
     *    Whoever implements the rest MUST preserve the
     *    ONE-RESPONSE-ROW-PER-SENT-ITEM assumption the row-count guard below and the
     *    positional attribution loop after it both depend on: collapsing duplicates into a
     *    single payload slot changes the row/address arithmetic, so that dedupe and this
     *    guard have to be changed TOGETHER. Sending N slots and fanning one row out to
     *    several caller keys, or sending N-k slots and re-deriving the expected row count
     *    from the de-duplicated payload rather than from the caller's address count, are both
     *    safe; silently comparing count($response) against the pre-dedupe count is not.
     *
     * @param $addresses
     * @param bool $checkForCaptured
     * @return array|false THREE shapes, and every caller has to handle all three - see
     *                     Plugin\Admin\ValidateImportAddress::afterValidateData():19-32:
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
            // The run map is asked first because it is free (no session read, no unserialise),
            // strictly newer than anything in the session store for the same key, and the only
            // one of the two that can answer a REJECTION. The log line stays a single 'hit'
            // token deliberately: it is what reconciles the drop in billed addresses against
            // the invoice, and an operator counting hits against misses does not care which
            // memory answered - see logBatchVerifyCacheOutcome().
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
            // Nothing left to ask, because every address was captured or cached (or the
            // batch was empty). Returning here is what makes an all-hit batch cost NO
            // request, rather than sending an empty 'Addresses' payload to a billable
            // endpoint and discarding the answer.
            return $result;
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

        // THE PRECONDITION OF POSITIONAL ATTRIBUTION, checked before a single verdict is read
        // or cached: exactly one answer per address sent. Everything below reads the Nth answer
        // as the Nth address's verdict, and the connector's array_column($response, 'Matches')
        // makes that unverifiable per row - it drops records with no 'Matches' key and
        // reindexes the rest, so a gap arrives as a SHORTER CLEAN LIST that is indistinguishable
        // from a truncated one and shifts every position after it. See the docblock above for
        // the full mechanism and the accepted trade-off. Bailing out is therefore the only safe
        // reading of a count mismatch, and it also gives the whole-response faults the connector
        // collapses to [] - any 200 body that is not a list of records carrying 'Matches', such as
        // {"Items": ...}, {"error":"Unauthorized"} or {} - a return value and a log line, where
        // before they were completely silent. An unparseable body and a top-level error envelope
        // are NOT among those: the connector reports both as ['error' => true, ...], so the branch
        // directly above already returns for them and always did. See the docblock.
        //
        // Returning false, not a partial array: the callers already treat false as "no verdict
        // for this batch" and fail closed on it - Plugin\Admin\ValidateImportAddress.php:55-75
        // reports one critical error and stops, Plugin\Admin\OrderSave.php:59-64 blocks the
        // order with a message.
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
            // DISJOINT faults - neither one subsumes the other:
            //  - the ROW-COUNT guard above catches records MISSING the 'Matches' key (the
            //    connector's array_column($response, 'Matches') DROPS those, so the list is
            //    shorter and the counts differ) plus the whole-response shapes that collapse
            //    to [];
            //  - checkQualityIndex()'s shape guard catches a record that IS PRESENT carrying
            //    an empty or otherwise unreadable 'Matches' - semantically "Loqate found no
            //    match for this address".
            //
            // The second case is FULLY REACHABLE past the count guard, and this is the
            // correction of an earlier claim in these comments that it was not. Verified on
            // PHP 8.3: array_column() only drops records missing the key; a record that HAS
            // 'Matches' whose value is [] SURVIVES as an empty element and the COUNT IS
            // PRESERVED. Three records whose middle one is ['Matches' => []] therefore yield a
            // three-element list that passes the count guard, position 1 arrives here as [],
            // $addressResponse[0]['AQI'] ?? null is null, and null <= 'A' is true - so before
            // this change that row was answered VALID. "No match found" being reported as
            // "valid" is the maximally wrong answer, so this is a genuinely reachable hole
            // being closed, not defence in depth.
            //
            // checkQualityIndex() now mirrors verifyAddress()'s AVC guard; see
            // checkQualityIndex()'s own docblock for why the test is on the value's shape
            // rather than on truthiness, for why an unrecognised THRESHOLD rejects rather
            // than - as it once did - passing every address, and for why it answers THREE
            // states rather than two.
            $verdict = $this->checkQualityIndex($qualityIndex);

            // THE STRICT BOOL COERCION IS LOAD-BEARING, AND THIS IS THE ONLY PLACE A null CAN
            // REACH $result (LOQ-17148). checkQualityIndex() answers null for "we could not
            // read this", which must still REJECT the row - it is only the REMEMBERING that
            // differs - so it is coerced here rather than assigned. Assigning the null instead
            // would leave the slot looking unfilled, the array_filter() below would DROP the
            // row, and Plugin\Admin\ValidateImportAddress's array_merge() would then renumber
            // every later row: an invalid row reported against a valid row's number, which is
            // exactly the mis-attribution LOQ-16977 was raised to fix. Pinned by
            // Test\Unit\Plugin\Admin\ValidateImportAddressRowAttributionTest.
            $isValid = $verdict === true;
            $result[$sentItem['key']] = $isValid;

            // NEVER REMEMBER A VERDICT WE COULD NOT READ, in ONE place for BOTH lifetimes:
            // rememberBatchVerdict() is the single gate, and it is handed the three-state
            // answer rather than the coerced bool precisely so that "unreadable" is still
            // distinguishable when the decision is made. No readability test here, none in
            // storeBatchVerifyResult(); one rule, one site.
            $this->rememberBatchVerdict($sentItem['signature'], $verdict);
        }

        // With the count guard in place this filter is a NO-OP by construction, and that is
        // worth stating rather than deleting: every reserved slot is now necessarily filled -
        // captured, remembered verdict (either memory), or sent and therefore answered, since a
        // mismatched row count returned above - so no null can survive to here. The one edit
        // that could have broken that claim is the readability split above, and it does not: an
        // unreadable answer is coerced to false before it is assigned, never left as null. It
        // is kept as the enforcement of the documented return shape ("no null verdicts, ever"),
        // so that a future edit which adds a further way of leaving a slot unfilled degrades to
        // a missing key, which callers already read as "nothing to report", instead of shipping
        // a null that ValidateImportAddress would report as an invalid row. array_filter()
        // preserves both keys and order, so the return-shape guarantees above survive it
        // either way.
        return array_filter($result, static fn ($verdict) => $verdict !== null);
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
     *  - true  - the AQI is readable, the threshold is readable, and the AQI meets it;
     *  - false - both are readable and the AQI MISSES the threshold. A VERDICT: Loqate judged
     *            this address and this merchant's quality bar refused it;
     *  - null  - the AQI could not be read, or the threshold could not be read. NOT A VERDICT
     *            but a FAULT REPORT, about the response or about the configuration.
     * The row is REJECTED for false and for null alike - the caller coerces null to false, see
     * the attribution loop in verifyMultipleAddresses() - so nothing about which rows an import
     * rejects changes. What the third state buys is the ability to remember the second one:
     * rememberBatchVerdict() remembers a readable verdict of either polarity and remembers a
     * null NOWHERE, so one connector fault, one "no match for this address" or one bad
     * credential cannot brand every matching row in a file invalid for the rest of the run.
     * Before the split, "readable rejection" and "unreadable, therefore rejected" were the same
     * false and could not be told apart at all.
     *
     * THE RULE LIVES IN ONE PLACE. This method decides READABILITY; rememberBatchVerdict()
     * decides what a null means for memory. Neither the call site nor storeBatchVerifyResult()
     * repeats the test, which is what the previous arrangement got wrong in the other direction
     * (it delegated the whole rule to this method's fail-closed guard, so "never remember an
     * unreadable verdict" was true only as a side effect of failures not being cached - and it
     * would have silently stopped being true the moment any failure was).
     *
     * FAILS CLOSED on an AQI it cannot read. The guard is on the value's SHAPE - "is this a
     * non-empty string" - and deliberately not on truthiness or emptiness, because the
     * comparison below is a STRING comparison in which 'A' is the strongest grade
     * (etc/config.xml:20 defaults the threshold to 'A' and 'A' <= 'A' is true). Anything
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
     * unreadable AQI was answered VALID. The single call site - the positional attribution
     * loop in verifyMultipleAddresses() - reads
     * $addressResponse[0]['AQI'] ?? null, which is null for a response record whose
     * 'Matches' list is present but EMPTY - i.e. Loqate saying "no match for this address",
     * the case where "valid" is the maximally wrong answer. See the comment at that call
     * site for why the row-count guard does not catch that shape.
     *
     * This mirrors verifyAddress()'s AVC guard, so both verify paths now draw the
     * "readable verdict" line in exactly the same place.
     *
     * BOTH SIDES ARE NOW GUARDED EXPLICITLY, and the threshold side had to be.
     *
     * An earlier revision guarded only the AQI side and reasoned about the threshold side
     * being unset: 'A' <= null and 'A' <= '' are both false on 8.3, so a blank threshold
     * rejects every address. True, and the safe direction - but it is the wrong case. The
     * dangerous value is not a blank threshold, it is an UNREADABLE one. Under the same
     * string comparison, 'A' <= 'zzz' and 'E' <= 'zzz' are both TRUE (verified on 8.3), so a
     * threshold of any text sorting above 'E' passes EVERY address, including the worst AQI
     * Loqate can return. That is a total bypass of the merchant's configured quality bar,
     * arrived at silently, and no amount of guarding the response side detects it.
     *
     * So the threshold is required to be one of self::VALID_QUALITY_INDEXES and anything
     * else fails closed and is logged - EVERY time, once per verdict, not once per run. That
     * log line is the only signal a merchant has that their quality bar is broken, so it is
     * emitted from here rather than hoisted anywhere a remembered verdict could skip it.
     * Rejecting is the only safe answer: the whole point of the setting is to bar addresses,
     * and a threshold nobody can read cannot be said to admit any of them.
     *
     * REACHABLE FROM THE ADMIN UI SINCE LOQ-17148: etc/adminhtml/system.xml now exposes
     * address_quality_index as a SELECT over Model\Config\Source\AddressQualityIndex, whose
     * option values are derived from self::VALID_QUALITY_INDEXES - so nothing a merchant can
     * choose in the form can reach the branch below. The guard is not thereby redundant, and
     * this is why it was written before the field existed: the value is a plain
     * core_config_data row, and a data patch, a CLI config:set, direct SQL or an env.php
     * override can still put anything into it. The guard costs one in_array() per verdict.
     *
     * resolveQualityIndexThreshold() still returns the value RAW and uncast, so the batch
     * cache key keeps fingerprinting exactly what was compared; see its docblock.
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

        $configIndex = $this->resolveQualityIndexThreshold();

        if (!is_string($configIndex) || !in_array($configIndex, self::VALID_QUALITY_INDEXES, true)) {
            $this->logger->info(sprintf(
                'Loqate: address_quality_index is not a recognised quality index (%s of type %s); '
                . 'rejecting the address. Set it to one of %s.',
                var_export($configIndex, true),
                gettype($configIndex),
                implode(', ', self::VALID_QUALITY_INDEXES)
            ));

            return null;
        }

        return $qualityIndex <= $configIndex;
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
     * checkQualityIndex()'s three-state answer, not a bool, precisely so that the decision is
     * made where the distinction still exists: null is an unreadable AQI or an unreadable
     * threshold, which is a FAULT REPORT and not a verdict, so it is written nowhere and the
     * identical address is billed again - within this run as well as in any later request. One
     * connector fault, one "no match for this address" or one bad credential must not brand
     * every matching row in an import file invalid for the rest of the run, reported to the
     * merchant as rows to go and fix with nothing on the server saying otherwise.
     *
     * WHY THE RULE IS HERE AND NOWHERE ELSE. It used to be delegated to checkQualityIndex()
     * failing closed plus storeBatchVerifyResult() storing no failures: true at the time, but
     * true only as a SIDE EFFECT of failures never being remembered, so it stopped being true
     * the moment one lifetime started remembering them. Stating it once, at the only place both
     * memories are written, is what keeps the two lifetimes from drifting apart - and it is
     * always the readability half that gets relaxed when they do. The call site holds no
     * readability test, and neither does storeBatchVerifyResult(), which keeps its own
     * PASSES-ONLY guard for its own separate reason (see its docblock).
     *
     * THE TWO POLARITIES ARE ASYMMETRIC BY DESIGN: a readable rejection is remembered for the
     * RUN and never for the SESSION. self::BATCH_VERIFY_CACHE_LIMIT records the measurements
     * behind that - caching failures in the session store is a regression on the target
     * workload, and a rejection that outlived the request would strand a merchant who had
     * corrected the file or loosened the threshold.
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
     * address in one import file, it is never serialised and it dies with the request, so the
     * cost is bounded by the file the merchant chose to import - whereas the session store is
     * re-read and re-written on every subsequent request of the browser session, which is the
     * whole reason THAT one has a limit (see self::BATCH_VERIFY_CACHE_LIMIT).
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
     * Discard this run's remembered verdicts if the shopper-scoped stores have been flushed
     * since they were earned (LOQ-17148, LOQ-16978).
     *
     * The map is verdict data, so it has the OWNERSHIP lifetime of the session verdict stores
     * and not merely the request's: one Validator can outlive a mid-request identity change,
     * and a plain request-scoped map would then answer the new shopper out of the previous
     * shopper's verdicts while the guard had just flushed the three stores beside it - reopening
     * exactly the leak ShopperScopedAddressStores exists to close, through a store that class
     * cannot see. Pinned by
     * ShopperScopedAddressStoresTest::testABatchVerdictDoesNotSurviveALogin().
     *
     * ASKING IS WHAT ENFORCES IT. ShopperScopedAddressStores::ownershipGeneration() runs the
     * ownership check itself and then reports the generation, so this is correct even on the
     * import path, where $checkForCaptured is false and the run map is consulted before any
     * session attribute is touched at all. Enrolling the map in the guard's own model, rather
     * than comparing customer ids at this call site, is what keeps ONE definition of "the
     * shopper changed" in the codebase.
     *
     * @return void
     */
    private function discardRunScopedVerdictsIfShopperChanged(): void
    {
        $generation = $this->shopperSession->ownershipGeneration();
        if ($generation === $this->runScopedVerdictsGeneration) {
            return;
        }

        // Either the first lookup of this instance (nothing to discard) or a genuine flush.
        // Both are answered by starting from empty, which is why no special case is needed for
        // the null the property starts out holding.
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
