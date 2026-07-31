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
     * Session data key holding the verify verdict cache (LOQ-16969).
     *
     * A verdict is customer data, so it lives in the per-shopper customer session
     * and nowhere else: CacheInterface, Registry and static properties are all
     * process- or install-wide and would serve one shopper's verdict to another.
     * Entries are additionally namespaced per store view, see buildVerifyCacheKey().
     */
    const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /**
     * Maximum number of verdicts kept per session, oldest evicted first.
     *
     * Bounded so the cache cannot inflate the session payload the way the
     * pre-existing, unbounded "captured_addresses" store does.
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
     * The Cleansing API behind $this->apiConnector is billable per request, and a
     * single checkout replays the same address 3-5 times depending on Magento
     * version and checkout front-end (shipping-information POST, billing save,
     * place-order, then the QuoteSubmitBefore observer). Verdicts are therefore
     * de-duplicated on the canonical address signature and replayed from a
     * session-scoped cache, so one address costs one request (LOQ-16969).
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
        // being it: buildAddressSignature() covers Address1/Address2 only, while the
        // request actually sent to Loqate carries the FULL joined street in 'Address',
        // so with customer/address/street_lines >= 3 an edit to line 3 or 4 would leave
        // the signature untouched and replay a verdict for an address Loqate never saw.
        // buildVerifySignature() folds 'Address' in, and must not be pushed down into
        // buildAddressSignature(), which is also compared against the captured-address
        // store whose entries (Helper\Controller::storeCapturedAddress() via
        // ADDRESS_CAPTURE_MAPPING) have no 'Address' key at all.
        $signature = $this->buildAddressSignature($requestArray);
        $verifySignature = $this->buildVerifySignature($signature, $requestArray);
        $strictSignature = $this->buildStrictAddressSignature($verifySignature, $requestArray);

        // Strict (rejection) key first: the two key families are disjoint, so both
        // reads are cheap cache lookups and only one case changes - a shopper who
        // reverts to a county Loqate explicitly rejected now gets that rejection back
        // instead of being passed by the county-agnostic success entry.
        $cachedResult = $this->getCachedVerifyResult($strictSignature)
            ?? $this->getCachedVerifyResult($verifySignature);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

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

        // Accepted trade-off (LOQ-16969): because the success key excludes Address4,
        // once any county variant of this address is accepted, every county variant
        // that Loqate has NOT explicitly rejected also passes from cache for the rest
        // of the session (a rejected one is caught by the strict read above, which runs
        // first). Keying successes strictly would re-bill exactly what this ticket
        // fixes (capture.js rewrites the county - 'Meath' -> 'Co. Meath' - and
        // parseAddress() re-resolves region from region_id), and the pre-existing
        // captured_addresses guard above has always behaved this way, so the residual
        // bypass is deliberate, not an oversight.
        $this->storeVerifyResult($verifySignature, false);

        return ['error' => false];
    }

    /**
     * Verify multiple addresses using Loqate API
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
     */
    private function checkAVCStatus($avcCode): bool
    {
        $avc = new AVC($avcCode);
        $advancedToggle = $this->helper->getConfigValue('loqate_settings/verify_threshold_settings/show_advanced_avc_settings');
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


        $comparerAVCString = sprintf(
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
        return $avc->compareTo(new AVC($comparerAVCString))['overall'] == 'better';
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
     * Its field list is fixed by the captured-address store it is compared against
     * (Helper\Controller::storeCapturedAddress() writes exactly
     * ADDRESS_CAPTURE_MAPPING's keys), so no field may be added here: the verify keys
     * extend it in buildVerifySignature()/buildStrictAddressSignature() instead.
     *
     * Region/province (Address4) is deliberately excluded, because the county is
     * routinely rewritten between saves ("Meath" -> "Co. Meath", or re-resolved from
     * region_id by parseAddress()) and that must not make an identical address look
     * new and get re-billed - the LOQ-16969 symptom. Street, city (Address3),
     * postcode and country already identify it; rejections, which do need the
     * county, use buildStrictAddressSignature(). '' means "nothing identifiable" and
     * keeps an address out of both comparisons.
     *
     * @param $address
     * @return string
     */
    private function buildAddressSignature($address): string
    {
        $keys = ['Address1', 'Address2', 'Address3', 'PostalCode', 'Country'];

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = $this->normaliseSignatureValue($address[$key] ?? null);
        }

        if (trim(implode('', $parts)) === '') {
            return '';
        }

        return implode('|', $parts);
    }

    /**
     * Key successful verdicts are stored under: the captured-address signature plus
     * the full joined street ('Address') actually sent to Loqate.
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
     * @param string $signature Signature returned by buildAddressSignature().
     * @param $address Parsed address the signature was built from.
     * @return string Empty when $signature is empty, so it is neither read nor written.
     */
    private function buildVerifySignature(string $signature, $address): string
    {
        if ($signature === '') {
            return '';
        }

        return $signature . '|' . $this->normaliseSignatureValue($address['Address'] ?? null);
    }

    /**
     * Strict variant of the verify signature: the lossy verify signature plus the
     * normalised county/province.
     *
     * Rejections are keyed with this so that correcting only a wrong county
     * re-verifies the address instead of replaying a rejection that blocks
     * checkout. Built from the already-normalised signature, so the '' sentinel and
     * the normalisation rules stay identical to buildAddressSignature(). Appending one
     * more part also keeps the two key families disjoint by pipe count, which is why
     * reading the strict key first cannot shadow an unrelated success.
     *
     * @param string $signature Signature returned by buildVerifySignature().
     * @param $address Parsed address the signature was built from.
     * @return string Empty when $signature is empty, so it is neither read nor written.
     */
    private function buildStrictAddressSignature(string $signature, $address): string
    {
        if ($signature === '') {
            return '';
        }

        return $signature . '|' . $this->normaliseSignatureValue($address['Address4'] ?? null);
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

        // Drop any existing entry first so re-inserting moves the key to the end
        // and insertion order keeps reflecting age.
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
     * against, and every threshold field is showInStore="1" and read at SCOPE_STORE
     * (Data::getConfigValue()), while ONE session can span store views (?___store=, a
     * language switcher). Verdicts are therefore namespaced per store view - the exact
     * scope the configuration behind them is resolved at - so switching store view
     * mid-session can never serve a verdict computed under another view's thresholds.
     *
     * @param string $signature
     * @return string Empty when $signature is empty, keeping the '' sentinel intact.
     */
    private function buildVerifyCacheKey(string $signature): string
    {
        if ($signature === '') {
            return '';
        }

        return $this->helper->getCurrentStore() . '|' . $signature;
    }
}
