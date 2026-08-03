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
     * be added here (see buildAddressSignature()). The verify cache keys extend this
     * list instead - see strictSignatureFields() for the invariant that governs them.
     */
    private const CAPTURED_SIGNATURE_FIELDS = ['Address1', 'Address2', 'Address3', 'PostalCode', 'Country'];

    /**
     * The county/province field: present in the strict (rejection) key, absent from
     * the lossy (success) one. That asymmetry is the whole design of the SINGLE-address
     * cache, so the field is named once here rather than spelled out at each key builder.
     *
     * The asymmetry is NOT universal, and there is now a third KEY BUILDER that proves it:
     * buildBatchVerifySignature() (LOQ-16976) includes the county in a SUCCESS key. It is
     * not a third direct consumer of this constant - the only two of those are
     * strictSignatureFields() and verifySignatureFields(); the batch builder reaches the
     * county transitively, through buildStrictAddressSignature(). What it is a third
     * consumer of is the ASYMMETRY, which is what this note governs. Including the county in
     * a success key is not a violation of the rule above but a consequence of it - the
     * asymmetry exists only to stop a cached REJECTION becoming a dead-end, and the batch
     * cache never stores a rejection (storeBatchVerifyResult()), so it is free to key
     * successes strictly and does, because an AQI plausibly depends on the county. Read
     * the four numbered reasons on buildBatchVerifySignature() before assuming the
     * strict/lossy split should be mirrored onto any new consumer.
     */
    private const COUNTY_FIELD = 'Address4';

    /**
     * Session data key holding the verify verdict cache (LOQ-16969).
     *
     * A verdict is customer data, so it lives in the per-shopper customer session
     * and nowhere else: CacheInterface, Registry and static properties are all
     * process- or install-wide and would serve one shopper's verdict to another.
     * Entries are additionally namespaced per store view and per resolved AVC threshold,
     * see buildVerifyCacheKey().
     */
    const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /**
     * Maximum number of verdicts kept per session, oldest evicted first.
     *
     * Bounded so the cache cannot inflate the session payload the way the pre-existing
     * "captured_addresses" store does: that one grows without limit for the whole
     * session (Helper/Controller.php:179-194), which is tracked as LOQ-16978 and is
     * deliberately not replicated here.
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
     * lives in the per-shopper customer session and nowhere process- or install-wide.
     */
    const BATCH_VERIFY_CACHE_SESSION_KEY = 'loqate_verified_batch_addresses';

    /**
     * Maximum number of batch verdicts kept per session, oldest evicted first.
     *
     * Deliberately larger than self::VERIFY_CACHE_LIMIT because this path is BATCHED:
     * customer import verifies in chunks of 100 rows
     * (Plugin\Admin\ValidateImportAddress.php:50), so any limit below 100 could not hold
     * even one chunk - the eviction would discard a chunk's earliest rows before the
     * chunk finished, and re-running a file that FITS WITHIN THIS LIMIT would re-bill them.
     * 200 holds two full chunks while still bounding the session payload, which is the whole
     * point of having a limit at all (see self::VERIFY_CACHE_LIMIT and LOQ-16978).
     *
     * READ THAT PARAGRAPH AS A FLOOR ON THE CONSTANT, NOT AS A SAVING ON THE IMPORT PATH.
     * It is why >= 100 is asserted, and it holds only for a file whose whole working set
     * fits inside the limit; for a file LARGER than the limit it claims nothing, because
     * FIFO eviction leaves close to zero hits however the constant is chosen. The paragraph
     * below is the one to quote when crediting this cache with a saving.
     *
     * DO NOT MEASURE THIS AGAINST THE IMPORT PATH - state the consequence plainly so the
     * saving is not credited to the wrong place. Eviction is FIFO, so re-running a file
     * LARGER than this limit yields close to ZERO cache hits: chunk 1's entries are already
     * evicted by the time run 1 finishes, and re-fetching them during run 2 evicts exactly
     * what chunk 2 would have needed. Two further facts compound it: only PASSING verdicts
     * are cached at all (storeBatchVerifyResult()), and address_quality_index defaults to
     * 'A' (etc/config.xml:20), the strictest value, so on a default install most rows fail
     * and are therefore never cacheable in the first place. The practical dedupe on the
     * import path is consequently near nil, and raising this constant would not change that
     * - it is inherent to FIFO over a working set larger than the cache.
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

    /** @var Session $session */
    private $session;

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
     * @param Session $session
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
        $this->session = $session;
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
     * the canonical address signature and replayed from a session-scoped cache, so one
     * address costs one request (LOQ-16969). verifyMultipleAddresses() now has its own
     * equivalent guard (LOQ-16976), in its own session attribute and with its own key
     * shape, because its verdicts come from the AQI rather than the AVC - see
     * self::BATCH_VERIFY_CACHE_SESSION_KEY.
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
        if ($checkForCaptured && ($storedAddresses = $this->session->getData('captured_addresses'))) {
            if ($this->checkForCapturedAddress($requestArray, $storedAddresses)) {
                return ['error' => false];
            }
        }

        // Asymmetric keys: successes on the lossy signature (county excluded) so one
        // address is billed once across all checkout call paths; rejections on the
        // strict signature (county included) so a shopper who corrects a wrong county
        // is re-verified instead of locked out of checkout by a replayed rejection.
        // Cost: a rejection may be re-billed once per call path, which is fine
        // because a rejection blocks checkout, so later paths are rarely reached.
        //
        // Both verify keys are derived from the captured-address signature rather than
        // being it: of the street, buildAddressSignature() covers lines 1 and 2 only
        // (Address1/Address2), while the request sent to Loqate carries the FULL joined
        // street in 'Address',
        // so with customer/address/street_lines >= 3 an edit to line 3 or 4 would leave
        // the signature untouched and replay a verdict for an address Loqate never saw.
        // buildVerifySignature() folds 'Address' in, and must not be pushed down into
        // buildAddressSignature(), which is also compared against the captured-address
        // store whose entries (Helper\Controller::storeCapturedAddress() via
        // ADDRESS_CAPTURE_MAPPING) have no 'Address' key at all.
        //
        // THE INVARIANT THE WHOLE SCHEME RESTS ON: the STRICT key must project EVERY
        // field parseAddress() sends to Loqate - that is, every value in
        // ADDRESS_MAPPING - and the LOSSY key must be exactly the strict key minus the
        // county (self::COUNTY_FIELD). Only then is the asymmetry safe: a rejection is
        // recorded against the complete address Loqate actually judged, so correcting
        // ANY field re-verifies rather than replaying a rejection (no checkout
        // dead-end), while the lossy key ignores nothing but the churning county.
        // A field added to ADDRESS_MAPPING but left out of the keys would be sent to
        // Loqate yet invisible to the cache, silently re-opening BOTH the double
        // billing and the dead-end. The field lists are therefore DERIVED from
        // ADDRESS_MAPPING rather than written out by hand - see
        // strictSignatureFields() / verifySignatureFields().
        $signature = $this->buildAddressSignature($requestArray);
        $verifySignature = $this->buildVerifySignature($signature, $requestArray);
        $strictSignature = $this->buildStrictAddressSignature($verifySignature, $requestArray);

        // Strict (rejection) key first: the two key families are disjoint, so both
        // reads are cheap cache lookups and only one case changes - a shopper who
        // reverts to a county Loqate explicitly rejected now gets that rejection back
        // instead of being passed by the county-agnostic success entry.
        // ?? short-circuits, so the lossy key is only read when the strict one misses,
        // which is also what makes the reported key family below accurate.
        $strictResult = $this->getCachedVerifyResult($strictSignature);
        $cachedResult = $strictResult ?? $this->getCachedVerifyResult($verifySignature);
        if ($cachedResult !== null) {
            $this->logVerifyCacheOutcome(
                'hit',
                $strictResult !== null ? 'strict' : 'lossy',
                $strictResult !== null ? $strictSignature : $verifySignature
            );

            return $cachedResult;
        }

        // Every miss is followed by exactly one billable request (the call below), so
        // counting these lines counts the Loqate invoice.
        $this->logVerifyCacheOutcome('miss', 'none', $verifySignature);

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
            // A real AVC that fails the thresholds is a definitive verdict, so it
            // is cached - under the strict key only, see above.
            $this->storeVerifyResult($strictSignature, true);

            return ['error' => true, 'message' => __('The provided address is invalid.')];
        }

        // Accepted trade-off (LOQ-16969), stated at its true width: because the success
        // key excludes the county, once ANY county variant of this address is accepted,
        // every county variant Loqate has NOT explicitly rejected also passes from the
        // cache for the rest of the session (a rejected one is caught by the strict read
        // above, which runs first).
        //
        // This is accepted because keying successes strictly would re-bill precisely the
        // county churn this ticket fixes: capture.js populates the region <select> from
        // the SDK's ProvinceName and fires a bubbling change event
        // (view/base/web/capture.js:7932, retried with backoff at :7942-7960), and
        // parseAddress() additionally re-resolves 'region' from region_id, so the county
        // value is NOT stable across the call paths listed in this method's docblock.
        //
        // It is NOT the status quo, and must not be justified as parity with the
        // pre-existing captured_addresses guard: that guard is written only by
        // Helper\Controller::retrieve() -> storeCapturedAddress()
        // (Helper/Controller.php:179-194), so it only ever applied to addresses picked
        // from the Loqate lookup - addresses Loqate itself authored. Caching successes
        // county-blind extends that county-blindness to TYPED addresses, which is a
        // widening of the bypass. Tightening it (for instance by keying successes
        // strictly and canonicalising the county instead) is tracked as LOQ-16979,
        // "tighten county-blind verify verdict cache so unverified county variants
        // cannot pass".
        $this->storeVerifyResult($verifySignature, false);

        return ['error' => false];
    }

    /**
     * Verify multiple addresses using Loqate API
     *
     * Used by admin order create (Plugin\Admin\OrderSave.php:49) and customer import
     * (Plugin\Admin\ValidateImportAddress.php:53).
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
     *    is never cached. That is a fail-closed verdict, decided in checkQualityIndex()
     *    (Helper/Validator.php:841-843) and reached for a record whose 'Matches' list is
     *    present but empty - the row-count guard below cannot catch that shape, because
     *    array_column() PRESERVES such a record, see the attribution loop for the
     *    demonstration. It is a deliberate rejection, not a gap: it costs one
     *    re-attempt on a response we could not read, where the previous behaviour reported
     *    "no match found" as VALID.
     *  - the same address twice in ONE batch is billed twice: both copies miss the cache in
     *    the pre-flight pass, since nothing is written until the response comes back.
     *    Tracked as LOQ-17015. Whoever implements it MUST preserve the
     *    ONE-RESPONSE-ROW-PER-SENT-ITEM assumption the count guard below (:643-651) and the
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

        $storedAddresses = $checkForCaptured ? $this->session->getData('captured_addresses') : null;

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

            $signature = $this->buildBatchVerifySignature($parsedAddress);
            $cachedVerdict = $this->getCachedBatchVerifyResult($signature);
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
            // checkQualityIndex() now mirrors verifyAddress()'s AVC guard
            // (Helper/Validator.php:377); see its docblock for why the test is on the value's
            // shape rather than on truthiness, and for the deliberately-unchanged asymmetry on
            // the threshold side of the comparison.
            $isValid = $this->checkQualityIndex($qualityIndex);
            $result[$sentItem['key']] = $isValid;

            // NEVER CACHE A VERDICT WE COULD NOT READ still holds, and it no longer needs a
            // separate readability test here: an unreadable AQI is now a FALSE verdict, and
            // storeBatchVerifyResult() caches nothing but passes. One test, one place - the
            // previous arrangement had the readability rule stated twice (once for the verdict,
            // once for the cache) and could drift.
            $this->storeBatchVerifyResult($sentItem['signature'], $isValid);
        }

        // With the count guard in place this filter is a NO-OP by construction, and that is
        // worth stating rather than deleting: every reserved slot is now necessarily filled -
        // captured, cache hit, or sent and therefore answered, since a mismatched row count
        // returned above - so no null can survive to here. It is kept as the enforcement of the
        // documented return shape ("no null verdicts, ever"), so that a future edit which adds
        // a fourth way of leaving a slot unfilled degrades to a missing key, which callers
        // already read as "nothing to report", instead of shipping a null that
        // ValidateImportAddress would report as an invalid row. array_filter() preserves both
        // keys and order, so the return-shape guarantees above survive it either way.
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
     * unreadable AQI was answered VALID. The single call site
     * (the attribution loop, Helper/Validator.php:703) reads
     * $addressResponse[0]['AQI'] ?? null, which is null for a response record whose
     * 'Matches' list is present but EMPTY - i.e. Loqate saying "no match for this address",
     * the case where "valid" is the maximally wrong answer. See the comment at that call
     * site for why the row-count guard does not catch that shape.
     *
     * This mirrors verifyAddress()'s AVC guard (Helper/Validator.php:377), so both verify
     * paths now draw the "readable verdict" line in exactly the same place.
     *
     * KNOWN ASYMMETRY, documented rather than changed. The two halves of this comparison
     * fail closed for different reasons and with different strength:
     *  - the AQI side (this guard) fails closed DELIBERATELY, by explicit shape test;
     *  - the THRESHOLD side fails closed only INCIDENTALLY, out of comparison semantics:
     *    'A' <= null and 'A' <= '' are both false (verified on 8.3), so were
     *    address_quality_index ever unset, EVERY address would be rejected. That is left
     *    exactly as it is. It is not reachable in practice - etc/config.xml:20 supplies the
     *    default and the field is not exposed in etc/adminhtml/system.xml, so it cannot be
     *    blanked from the admin UI - and "fixing" it by treating an absent threshold as
     *    permissive would re-open a fail-open on the config side to close a hole that does
     *    not exist. resolveQualityIndexThreshold() also returns the value RAW and uncast on
     *    purpose; see its docblock before touching either side.
     *
     * @param $qualityIndex The AQI from the response row, of any type - it is unvalidated
     *                      connector output, so this method must not assume a string.
     * @return bool True only when the AQI is readable AND meets the configured threshold.
     */
    private function checkQualityIndex($qualityIndex): bool
    {
        if (!is_string($qualityIndex) || $qualityIndex === '') {
            return false;
        }

        $configIndex = $this->resolveQualityIndexThreshold();

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
     * @param $address
     * @param $storedAddresses
     * @return bool
     */
    private function checkForCapturedAddress($address, $storedAddresses): bool
    {
        $candidateSignature = $this->buildAddressSignature($address);
        if ($candidateSignature === '') {
            return false;
        }

        foreach ($storedAddresses as $stored) {
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
     * Build a normalised, comparable signature for an address: the canonical
     * projection of the fields Loqate Verify keys on. Used to match an address
     * against the captured-address store, and as the base both verify cache keys are
     * derived from.
     *
     * Its field list (self::CAPTURED_SIGNATURE_FIELDS) is fixed by the captured-address
     * store it is compared against (Helper\Controller::storeCapturedAddress() writes
     * exactly ADDRESS_CAPTURE_MAPPING's keys), so no field may be added here: the verify
     * keys extend it in buildVerifySignature()/buildStrictAddressSignature() instead.
     *
     * Region/province (self::COUNTY_FIELD) is deliberately excluded, because the county
     * is routinely rewritten between saves - capture.js populates the region <select>
     * from the SDK's ProvinceName and fires a bubbling change event
     * (view/base/web/capture.js:7932), and parseAddress() re-resolves 'region' from
     * region_id - and that must not make an identical address look new and get re-billed,
     * the LOQ-16969 symptom. Street, city (Address3), postcode and country already
     * identify it; rejections, which do need the county, use
     * buildStrictAddressSignature(). '' means "nothing identifiable" and keeps an address
     * out of both comparisons.
     *
     * @param $address
     * @return string
     */
    private function buildAddressSignature($address): string
    {
        $parts = [];
        foreach (self::CAPTURED_SIGNATURE_FIELDS as $key) {
            $parts[] = $this->normaliseSignatureValue($address[$key] ?? null);
        }

        if (trim(implode('', $parts)) === '') {
            return '';
        }

        return implode('|', $parts);
    }

    /**
     * Key successful verdicts are stored under (the LOSSY key): the captured-address
     * signature plus every remaining field parseAddress() sends to Loqate except the
     * county - today that is just the full joined street, 'Address'.
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
        // Everything the lossy key covers beyond what $signature already carries.
        $append = array_slice($this->verifySignatureFields(), count(self::CAPTURED_SIGNATURE_FIELDS));

        return $this->appendSignatureFields($signature, $address, $append);
    }

    /**
     * Strict variant of the verify signature (the STRICT key): the lossy verify
     * signature plus the normalised county/province.
     *
     * Rejections are keyed with this so that correcting only a wrong county
     * re-verifies the address instead of replaying a rejection that blocks
     * checkout. Built from the already-normalised signature, so the '' sentinel and
     * the normalisation rules stay identical to buildAddressSignature(). Appending one
     * more part also keeps the two key families disjoint by pipe count, which is why
     * reading the strict key first cannot shadow an unrelated success - and that stays
     * true however many fields ADDRESS_MAPPING grows, because both families gain the
     * same segments and the strict one always gains exactly one more.
     *
     * @param string $signature Signature returned by buildVerifySignature().
     * @param $address Parsed address the signature was built from.
     * @return string Empty when $signature is empty, so it is neither read nor written.
     */
    private function buildStrictAddressSignature(string $signature, $address): string
    {
        // Everything the strict key covers beyond the lossy one: the county, and by the
        // construction of strictSignatureFields() nothing else, ever.
        $append = array_slice($this->strictSignatureFields(), count($this->verifySignatureFields()));

        return $this->appendSignatureFields($signature, $address, $append);
    }

    /**
     * Ordered field list of the STRICT (rejection) cache key.
     *
     * The load-bearing invariant of LOQ-16969, expressed as code rather than as a
     * comment: as a SET this must equal array_values(self::ADDRESS_MAPPING) - every
     * field parseAddress() actually sends to Loqate - because a rejection may only be
     * replayed for the exact address Loqate judged. If a field reached the request but
     * not this list, editing it would replay a stale verdict: a wrong "invalid" the
     * shopper cannot clear (the checkout dead-end) or a wrong "valid" that lets an
     * unverified address through.
     *
     * It is therefore DERIVED, not hand-written: the captured base is fixed by the
     * captured-address store, the county is appended last (it is what the lossy key
     * drops), and anything else in ADDRESS_MAPPING is folded in automatically. So adding
     * a mapping such as 'company' => 'Company' extends both keys by construction instead
     * of quietly re-opening the defect.
     *
     * @return string[] Loqate field names, in the order they appear in the key.
     */
    private function strictSignatureFields(): array
    {
        return array_merge($this->verifySignatureFields(), [self::COUNTY_FIELD]);
    }

    /**
     * Ordered field list of the LOSSY (success) cache key: the strict list minus the
     * county. See strictSignatureFields() for why the extras are derived from
     * ADDRESS_MAPPING, and verifyAddress() for why the county is dropped here.
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
     * Shared by both verify key builders so the '' sentinel and the normalisation rules
     * can only ever be defined once.
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
            $parts[] = $this->normaliseSignatureValue($address[$field] ?? null);
        }

        return implode('|', $parts);
    }

    /**
     * Normalise one address field for use inside a signature: trimmed,
     * whitespace-collapsed and upper-cased, so trivial reformatting cannot change
     * the signature. Non-scalars (Magento's street array, objects) and missing
     * values normalise to '' rather than throwing.
     *
     * @param $value
     * @return string
     */
    private function normaliseSignatureValue($value): string
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
     * Only the verdict flag is cached; the message is rebuilt here so no Phrase ever
     * passes through the serializer (its translation would be frozen at the store
     * view that cached it, and a serializer without object support would return an
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

        $store = $this->session->getData(self::VERIFY_CACHE_SESSION_KEY);
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

        if (!$verdict['error']) {
            return ['error' => false];
        }

        return ['error' => true, 'message' => __('The provided address is invalid.')];
    }

    /**
     * Store a definitive verify verdict against the given address signature.
     *
     * Only the boolean verdict is stored, never a message (see
     * getCachedVerifyResult()). Kept in the customer session only (see
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

        $store = $this->session->getData(self::VERIFY_CACHE_SESSION_KEY);
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

        $store[$key] = $this->serializer->serialize(['error' => $error]);
        $this->session->setData(self::VERIFY_CACHE_SESSION_KEY, $store);
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
     * misses map one-to-one onto billable calls, and the key family shows whether a hit
     * came from the strict (rejection) or the lossy (success) entry.
     *
     * A miss is reported under the LOSSY key's hash on purpose: that key is the
     * county-blind one, so every event belonging to one address - including the county
     * variants the shopper churns through - shares a hash and can be counted together.
     * A strict hit is reported under the strict key's hash, which is why the family
     * matters when reading the log.
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
     * @param string $keyFamily 'strict', 'lossy', or 'none' for a miss.
     * @param string $signature Signature the lookup was made with; never logged as-is.
     * @return void
     */
    private function logVerifyCacheOutcome(string $outcome, string $keyFamily, string $signature): void
    {
        $key = $this->buildVerifyCacheKey($signature);

        $this->logger->debug(sprintf(
            'Loqate verify cache %s [family=%s, key=%s]',
            $outcome,
            $keyFamily,
            $key === '' ? 'unkeyed' : substr(hash('sha256', $key), 0, 12)
        ));
    }

    /**
     * Build the batch path's cache signature for one parsed address: the STRICT signature,
     * county (self::COUNTY_FIELD) INCLUDED.
     *
     * STRICT-ONLY, deliberately NOT the asymmetric strict/lossy pair verifyAddress() uses
     * (LOQ-16976). Four reasons, all of which have to stay true for this to remain correct:
     *  1. this cache never stores a failure (storeBatchVerifyResult()), so the
     *     rejection-replay checkout dead-end that FORCED the asymmetry over there - a
     *     cached "invalid" the shopper cannot clear by correcting the county - is
     *     structurally impossible here. A lossy key would buy nothing safety-wise;
     *  2. parseAddress() (Helper/Validator.php:737-740, the region_id branch) re-derives
     *     'region' from region_id on this exact path, canonicalising the county per
     *     region_id. That derivation is deterministic, so the county churn the lossy key
     *     exists to absorb is largely absent from admin order create and import;
     *  3. it could NOT be established that view/base/web/capture.js even loads on the
     *     admin order-create screen - the sales_order_create_customer_block layout handle
     *     is unverifiable without Magento core vendored - so the client-side
     *     county-rewriting premise behind the lossy key is unproven on this path;
     *  4. an AQI plausibly depends on the county, so a county-blind SUCCESS cache here
     *     would be a WIDER bypass than the one LOQ-16979 already tracks tightening.
     * ACCEPTED COST: if the county is rewritten between two submissions of the same
     * address, that address is billed once more. One request, once, per rewrite.
     *
     * Reuses the existing signature builders verbatim, so the '' sentinel, the
     * normalisation rules and the "every field sent to Loqate is projected" invariant
     * documented in verifyAddress() hold here without being restated in code.
     *
     * @param $address Parsed address, as returned by parseAddress().
     * @return string Empty when the address carries nothing identifiable, which keeps it
     *                out of the cache entirely (neither read nor written).
     */
    private function buildBatchVerifySignature($address): string
    {
        $signature = $this->buildAddressSignature($address);

        return $this->buildStrictAddressSignature(
            $this->buildVerifySignature($signature, $address),
            $address
        );
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

        $store = $this->session->getData(self::BATCH_VERIFY_CACHE_SESSION_KEY);
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

        return true;
    }

    /**
     * Store a batch verdict against the given address signature - if, and only if, it
     * PASSED.
     *
     * NEVER CACHING A FAILURE is load-bearing, not tidiness: it is reason 1 for the
     * strict-only key documented on buildBatchVerifySignature(). A cached rejection would
     * need the county in the key to avoid stranding an admin or an import on a verdict they
     * cannot clear by correcting a field; because no rejection is ever cached, the key can
     * be strict without introducing that dead-end. The guard therefore lives HERE rather
     * than at the call site, so a future caller cannot reintroduce cached failures by
     * forgetting it.
     *
     * WHAT THIS GUARD RELIES ON, and where that half lives: "$valid === true" only means
     * checkQualityIndex() said so, and this method cannot audit that - by the time it is
     * called the response value is gone. It is safe because checkQualityIndex() itself now
     * FAILS CLOSED on an AQI it cannot read (Helper/Validator.php:841-843): an unreadable
     * value can no longer arrive here as a genuine-looking pass to be replayed all session.
     * Do not relax that guard without adding a readability test at this method's call site
     * (the attribution loop, Helper/Validator.php:703-711), or unreadable responses start
     * being cached as passes again.
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

        $store = $this->session->getData(self::BATCH_VERIFY_CACHE_SESSION_KEY);
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

        $store[$key] = $this->serializer->serialize(['valid' => true]);
        $this->session->setData(self::BATCH_VERIFY_CACHE_SESSION_KEY, $store);
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
     * correlate with the wrong cache. The family is fixed at 'strict' because this cache
     * has exactly one key family (see buildBatchVerifySignature()).
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
