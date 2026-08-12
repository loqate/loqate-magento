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
     * the lossy (success) one. That asymmetry is the whole design, so the field is
     * named once here rather than spelled out at each key builder.
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
     * session (Helper/Controller.php:171-186), which is tracked as LOQ-16978 and is
     * deliberately not replicated here.
     */
    const VERIFY_CACHE_LIMIT = 50;

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
     * The Cleansing API behind $this->apiConnector is billable per request, and this
     * method is wired in from SIX call statements across five classes, every one of
     * them registered GLOBALLY - etc/di.xml and etc/events.xml carry no area scoping:
     *  - Plugin\Frontend\CheckoutShippingInformation.php:32 - shipping-information POST;
     *  - Plugin\Frontend\CheckoutBillingAddress.php:34 - billing save, which
     *    savePaymentInformation replays at place order, so this one statement can run
     *    twice in a checkout;
     *  - Observer\QuoteSubmitBefore.php:60 and :84 - shipping and billing on
     *    sales_model_service_quote_submit_before, a global event, so it also fires on
     *    admin order create and multishipping, not just in checkout;
     *  - Plugin\Frontend\CustomerAccountAddress.php:37 - customer address-book save;
     *  - Plugin\Admin\ValidateAddress.php:42 - admin customer-address validation,
     *    including an admin re-testing the same address repeatedly.
     * So one checkout of one address reaches this method 3-5 times depending on
     * Magento version and checkout front-end, and account saves and admin re-tests
     * replay the same address on top of that. Verdicts are therefore de-duplicated on
     * the canonical address signature and replayed from a session-scoped cache, so one
     * address costs one request (LOQ-16969). verifyMultipleAddresses() has no such
     * guard yet - LOQ-16976.
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

        // if (!isset($response[0][0]['AQI']) || !$this->checkQualityIndex($response[0][0]['AQI'])) {
        //     return ['error' => true, 'message' => __('The provided address is invalid.')];
        // }

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
        // (Helper/Controller.php:171-186), so it only ever applied to addresses picked
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
     * (Plugin\Admin\ValidateImportAddress.php:38). This path is NOT covered by the
     * verify verdict cache that verifyAddress() uses and still bills every address on
     * every submission: its verdicts come from the AQI (checkQualityIndex()), not the
     * AVC, so it needs its own key and its own verdict shape - tracked as LOQ-16976.
     *
     * @param $addresses
     * @param bool $checkForCaptured
     * @return array|false
     */
    public function verifyMultipleAddresses($addresses, $checkForCaptured = true)
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return ['noKeyFound' => true];
        }

        if ($checkForCaptured) {
            $storedAddresses = $this->session->getData('captured_addresses');
        }

        $requestArray = [];
        foreach ($addresses as $index => $address) {
            $parsedAddress = $this->parseAddress($address);
            if (isset($storedAddresses)
                && $storedAddresses
                && ($checkedAddress = $this->checkForCapturedAddress($parsedAddress, $storedAddresses))) {
                //store all the address keys in a new array, so we can preserve the original keys/identifiers
                //because we are not sending the original array for validation and we need them to display results
                $addressesToCheck[$index] = $checkedAddress;
                continue;
            }
            $requestArray[] = $parsedAddress;
        }


        if (!$requestArray && isset($addressesToCheck)) {
            return $addressesToCheck;
        }

        $response = $this->apiConnector->verifyAddress(['Addresses' => $requestArray, 'source' => $this->version]);
        if (isset($response['error'])) {
            $this->logger->info($response['message']);
            return false;
        }

        $result = [];
        if (isset($addressesToCheck)) {
            foreach ($response as $address) {
                // BROKEN, pre-existing, left alone deliberately: $addressesToCheck holds
                // the CAPTURED addresses (all truthy), so array_search(false, ...) can
                // only ever return false, and every response row is written to
                // $result[false] === $result[0]. Verdicts for a mixed batch are therefore
                // mis-attributed. Fixing it changes admin/import behaviour and needs its
                // own regression cover - tracked as LOQ-16977.
                $originalPos = array_search(false, $addressesToCheck);
                $result[$originalPos] = $this->checkQualityIndex($address[0]['AQI']);
            }
        } else {
            foreach ($response as $address) {
                $result[] = $this->checkQualityIndex($address[0]['AQI']);
            }
        }

        return $result;
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
     * Check if response quality index matches the quality customer has set
     *
     * @param $qualityIndex
     * @return bool
     */
    private function checkQualityIndex($qualityIndex): bool
    {
        $configIndex = $this->helper->getConfigValue('loqate_settings/address_settings/address_quality_index');

        return $qualityIndex <= $configIndex;
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
}
