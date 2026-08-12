<?php

namespace Loqate\ApiIntegration\Helper;

use Loqate\ApiConnector\Client\Capture;
use Loqate\ApiConnector\Client\Extras;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Controller class
 */
class Controller
{
    const MAX_DATA_SETS_FIELDS = 20;

    /**
     * Session attribute holding the addresses picked from the Loqate Capture lookup.
     *
     * An ALIAS of ShopperScopedAddressStores::CAPTURED_ADDRESSES_SESSION_KEY, kept so that every
     * existing reference to Controller::CAPTURED_ADDRESSES_SESSION_KEY still resolves. The
     * name itself lives on ShopperScopedAddressStores because that class is what ENFORCES the
     * attribute's lifetime, and holding the name here instead made the dependency circular:
     * the guard's flush list pointed at this class while this class constructs the guard
     * (LOQ-16978 review). This class remains the store's only WRITER
     * (storeCapturedAddress()); Helper\Validator is its only reader.
     */
    const CAPTURED_ADDRESSES_SESSION_KEY = ShopperScopedAddressStores::CAPTURED_ADDRESSES_SESSION_KEY;

    /**
     * Maximum number of captured addresses kept per session, oldest evicted first.
     *
     * 50, the same bound as Validator::VERIFY_CACHE_LIMIT and for the same reasons, not
     * the 200 of Validator::BATCH_VERIFY_CACHE_LIMIT. That larger figure exists solely to
     * hold a customer-import chunk of 100 rows
     * (Plugin\Admin\ValidateImportAddress.php:50), and no import writes this store: its
     * only writer is Controller::retrieve(), i.e. a shopper or an admin PICKING an address
     * from the Capture lookup by hand. One interactive session picks a handful of
     * addresses - a shipping and a billing address, plus re-picks - so 50 distinct
     * addresses is already far beyond a realistic session while keeping the session
     * payload to a few kilobytes (one entry is a serialised six-field array).
     *
     * The two stores are also consulted for the SAME addresses by the same verify call
     * (Validator::verifyAddress() reads this one first, then the verdict cache), so a
     * different bound on each would only mean one of them holding entries the other has
     * long since evicted.
     */
    const CAPTURED_ADDRESSES_LIMIT = 50;

    /** @var Capture $apiConnector */
    private $apiConnector;

    /** @var ResultFactory $resultJsonFactory */
    protected $resultJsonFactory;

    /** @var RequestInterface $request */
    protected $request;

    /** @var Logger $logger */
    private $logger;

    /**
     * @var ShopperScopedAddressStores The captured-address store, behind the shopper-ownership
     *      guard. The raw customer session is deliberately NOT kept as well: keeping it
     *      would leave a way to reach the store without the guard - see
     *      ShopperScopedAddressStores.
     */
    private ShopperScopedAddressStores $shopperSession;

    /** @var string */
    private $version = null;

    private Data $helper;

    /** @var array */
    protected $enhancedFieldsValues;
    private SerializerInterface $serializer;

    /**
     * Find constructor
     *
     * @param ResultFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param Logger $logger
     * @param Session $session Wrapped in a ShopperScopedAddressStores and not kept raw, so the
     *                         captured-address store can only be reached through the
     *                         shopper-ownership guard.
     * @param ModuleListInterface $moduleList
     * @param Data $helper
     * @param SerializerInterface $serializer
     */
    public function __construct(
        ResultFactory $resultJsonFactory,
        RequestInterface $request,
        Logger $logger,
        Session $session,
        ModuleListInterface $moduleList,
        Data $helper,
        SerializerInterface $serializer
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->logger = $logger;
        $this->shopperSession = new ShopperScopedAddressStores($session);
        $this->helper = $helper;
        $this->serializer = $serializer;

        if ($apiKey = $this->helper->getConfigValue('loqate_settings/settings/api_key')) {
            $this->apiConnector = new Capture($apiKey);
        } else {
            $this->logger->info('No Api Key found! - Please configure Loqate plugin on Admin side!');
            return false;
        }
        $this->version = 'AdobeCommerce_v' . $moduleList->getOne('Loqate_ApiIntegration')['setup_version'];
    }

    /**
     * Call capture find API endpoint using PHP library
     *
     * @return ResponseInterface|ResultInterface
     */
    public function find()
    {
        $resultJson = $this->resultJsonFactory->create(ResultFactory::TYPE_JSON);
        if ($this->apiConnector) {
            $searchText = $this->request->getParam('Text');
            $origin = $this->request->getParam('Origin');
            $container = $this->request->getParam('Container');
            
            $apiRequestParams = ['Text' => $searchText, 'source' => $this->version, 'IsMiddleware' => 'true'];
            if (!empty($origin)) {
                $apiRequestParams['Origin'] = $origin;
            }

            $countries = $this->helper->getConfigValue('loqate_settings/capture_settings/restrict_countries');
            if (!empty($countries)) {
                $apiRequestParams['Countries'] = $countries;
            }

            if (!empty($container)) {
                $apiRequestParams['Container'] = $container;
            }

            $result = $this->apiConnector->find($apiRequestParams);

            if (isset($result['error'])) {
                $this->logger->info($result['message']);
                return $resultJson->setData(
                    ['error' => true, 'message' => __('Error occurred while trying to process your request')]
                );
            }

            return $resultJson->setData($result);
        } else {
            return $resultJson->setData(['error' => true, 'message' => __('Object could not be initialized')]);
        }
    }

    /**
     * Call capture retrieve API endpoint use PHP library
     *
     * @return ResponseInterface|ResultInterface
     */
    public function retrieve()
    {
        $resultJson = $this->resultJsonFactory->create(ResultFactory::TYPE_JSON);
        if ($this->apiConnector) {
            $addressId = $this->request->getParam('Id');
            $apiRequestParams = ['Id' => $addressId, 'source' => $this->version];

            $enhancedDataSetsFields = $this->getEnhancedDataSetsFields();

            if (!empty($enhancedDataSetsFields)) {
                $apiRequestParams = array_merge($apiRequestParams, $enhancedDataSetsFields);
            }

            $result = $this->apiConnector->retrieve($apiRequestParams);

            if (isset($result['error'])) {
                $this->logger->info($result['message']);
                return $resultJson->setData(
                    ['error' => true, 'message' => __('Error occurred while trying to process your request')]
                );
            }

            if (is_array($result)) {
                $this->storeCapturedAddress($result[0]);
            }

            if (!empty($enhancedDataSetsFields)) {
                $result = $this->applyEnhancedFields($result);
            }

            return $resultJson->setData($result);
        } else {
            return $resultJson->setData(['error' => true, 'message' => __('Object could not be initialized')]);
        }
    }

    /**
     * Store captured address in session so verify is not performed if the address hasn't changed
     *
     * This is the ONLY writer of self::CAPTURED_ADDRESSES_SESSION_KEY, which is why that
     * bypass only ever applied to addresses picked from the Loqate lookup - addresses
     * Loqate itself authored - and not to typed ones (see Validator::verifyAddress()).
     *
     * SCOPE AND LIFETIME OF THE STORE (LOQ-16978):
     *  - BOUNDED to self::CAPTURED_ADDRESSES_LIMIT entries, evicted FIFO - oldest first -
     *    exactly as Validator::storeVerifyResult() and Validator::storeBatchVerifyResult()
     *    bound the two verdict caches. The address currently being checked out is the one
     *    just captured, so it is always the newest entry and can never be the one evicted;
     *  - re-capturing an address already in the store MOVES it to the newest position
     *    instead of adding a copy, so repeatedly picking one address cannot fill the store
     *    and evict every other address in it. "Already in the store" is decided by
     *    Validator::capturedAddressSignature(), the SAME relation
     *    Validator::checkForCapturedAddress() grants the bypass on, so for every address
     *    the matcher CAN identify the store holds one slot and the guarantee above
     *    actually holds - see the comment on the de-duplication below for what byte
     *    comparison missed. Addresses the matcher identifies as nothing at all (signature
     *    '', an entry with no identifiable content) are the documented exception: they
     *    never match anything, so they are collapsed only when they are byte-identical
     *    and a store can hold several of them - see isSameCapturedAddress();
     *  - lives in the per-shopper customer session and nowhere process- or install-wide,
     *    because "Loqate authored this address" is a statement about one shopper's
     *    lookup, and it dies with the session;
     *  - FLUSHED when the logged-in customer changes, in either direction (login, logout,
     *    or one login straight after another): the store is reached through
     *    ShopperScopedAddressStores, so shopper B on a shared browser cannot inherit the
     *    verify bypass shopper A earned. Read that class before adding a fourth store.
     *
     * @param $result
     */
    protected function storeCapturedAddress($result)
    {
        $storeArray = [];
        foreach (Validator::ADDRESS_CAPTURE_MAPPING as $key => $value) {
            $storeArray[$key] = $result[$value];
        }

        $capturedAddresses = $this->shopperSession->getData(self::CAPTURED_ADDRESSES_SESSION_KEY);
        if (!is_array($capturedAddresses)) {
            // Covers the empty session and, defensively, an attribute another module or a
            // truncated payload left in a shape this store cannot append to.
            $capturedAddresses = [];
        }

        $entry = $this->serializer->serialize($storeArray);
        $signature = Validator::capturedAddressSignature($storeArray);

        // Drop every existing copy of this address first, then re-append - the same move
        // Validator::storeVerifyResult() makes with unset($store[$key]), and for the same
        // two reasons: the refreshed entry becomes the newest rather than keeping the age
        // of the one it replaces, and the store shrinks before the eviction loop, so
        // re-capturing an address while the store is FULL does not cost an unrelated
        // address its bypass.
        //
        // "The same address" is decided by Validator::capturedAddressSignature(), NOT by
        // the serialised bytes (LOQ-16978 review). It has to be the matcher's own relation:
        // Validator::checkForCapturedAddress() grants the bypass on the normalised,
        // upper-cased, whitespace-collapsed projection of FIVE fields, while these entries
        // serialise SIX raw ones. Comparing bytes therefore counted two captures that
        // differ only in case, in whitespace, in ProvinceName - excluded from the signature
        // altogether, and rewritten routinely by capture.js, see buildAddressSignature() -
        // or in ''-versus-missing Line2 as TWO entries, though the store grants them ONE
        // bypass. That is precisely how re-picking a single address could still fill a
        // bounded store and evict every other address in it. De-duplicating on the
        // signature makes the store self-consistent with the bypass it grants: one slot for
        // each address the matcher can identify.
        //
        // The byte comparison is kept as the first test inside isSameCapturedAddress(): it
        // is free, and it is the only thing that can de-duplicate an entry with no
        // identifiable content, whose signature is '' and which the matcher never matches.
        // Those entries are therefore the one case where the store can hold several slots
        // the matcher does not distinguish - it distinguishes none of them, and collapsing
        // them onto one another would be asserting an equality nothing else in the module
        // grants. They cost slots and no bypass, which is the safe direction.
        $capturedAddresses = array_values(array_filter(
            $capturedAddresses,
            fn ($stored): bool => !$this->isSameCapturedAddress($stored, $entry, $signature)
        ));

        // FIFO eviction, mirroring the two verdict caches. Unlike theirs, the keys here are
        // integers, so array_shift() renumbers them - which is what a list wants. The
        // re-indexing above is a separate concern: array_filter() PRESERVES keys, so
        // without array_values() the store would keep a hole and the [] append below would
        // use max+1, leaving a sparse array that count() and the readers would still
        // process but that no longer round-trips as a JSON list. The
        // $capturedAddresses !== [] guard keeps the loop terminating even if the limit is
        // ever set to 0 or below, where shifting an already-empty array would otherwise
        // spin forever inside a Capture request.
        while ($capturedAddresses !== [] && count($capturedAddresses) >= self::CAPTURED_ADDRESSES_LIMIT) {
            array_shift($capturedAddresses);
        }

        $capturedAddresses[] = $entry;
        $this->shopperSession->setData(self::CAPTURED_ADDRESSES_SESSION_KEY, $capturedAddresses);
    }

    /**
     * Is $stored the same captured address as the one being stored?
     *
     * Same relation as Validator::checkForCapturedAddress(), which is the whole point - see
     * the comment in storeCapturedAddress(). Byte equality is tested first because it is
     * free and because it is the only thing that can collapse an entry whose signature is
     * '' (an address with nothing identifiable in it), which the matcher deliberately never
     * matches and which must therefore never be collapsed onto another empty-ish address.
     *
     * @param mixed $stored Entry already in the store: normally a serialised address, but
     *                      this attribute is a bare session key and may hold anything.
     * @param string $entry Serialised form of the address being stored.
     * @param string $signature Its captured-address signature, '' when unidentifiable.
     * @return bool
     */
    private function isSameCapturedAddress($stored, string $entry, string $signature): bool
    {
        if (!is_string($stored)) {
            // Not something this class wrote. Left in place rather than dropped: pruning
            // foreign values is not this method's job, and Validator::checkForCapturedAddress()
            // already skips anything it cannot read - it carries the mirror of THIS guard
            // (`if (!is_string($stored)) { continue; }`) ahead of its own unserialize(), for
            // the reason spelled out there: json_decode() is typed `string $json`, so handing
            // it an array element is a TypeError rather than the \InvalidArgumentException
            // that method catches. Keep the two in step; a value one reader survives and the
            // other fatals on is the asymmetry both guards exist to close.
            return false;
        }

        if ($stored === $entry) {
            return true;
        }

        if ($signature === '') {
            return false;
        }

        return $this->capturedEntrySignature($stored) === $signature;
    }

    /**
     * The captured-address signature of an entry already in the store.
     *
     * Defensive in exactly the way Validator::checkForCapturedAddress() is, and for the
     * same reason: this runs inside a Capture retrieve request, while the shopper is
     * picking their address, so an entry left by an older release, a truncated session
     * payload or another module must cost a de-duplication - never a fatal.
     *
     * @param string $stored Serialised entry from the store.
     * @return string '' when the entry cannot be read back as an address.
     */
    private function capturedEntrySignature(string $stored): string
    {
        try {
            $decoded = $this->serializer->unserialize($stored);
        } catch (\InvalidArgumentException $e) {
            return '';
        }

        return is_array($decoded) ? Validator::capturedAddressSignature($decoded) : '';
    }

    protected function getEnhancedDataSetsFields()
    {
        $data = [];

        for ($i = 1; $i <= self::MAX_DATA_SETS_FIELDS; $i++) {
            $fieldValue = $this->helper->getConfigValue("loqate_settings/enhanced_data_sets/field{$i}_format");
            if (!empty($fieldValue)) {
                $data["Field{$i}Format"] = "{{$fieldValue}}";
                $this->enhancedFieldsValues[$i] = $this->removeSpecialChars($fieldValue);
            }
        }

        return $data;
    }

    protected function applyEnhancedFields($result)
    {
        $enhancedFieldsToApply = ['ProvinceName', 'City', 'Line1', 'Line2', 'Line3', 'Line4', 'Line5'];
        $enhancedFieldsIndexes = array_combine($this->enhancedFieldsValues, array_keys($this->enhancedFieldsValues));

        if (isset($result[0])) {
            foreach ($enhancedFieldsToApply as $enhancedFieldToApply) {
                if (isset($result[0][$enhancedFieldToApply]) && isset($enhancedFieldsIndexes[$enhancedFieldToApply])) {
                    $enhancedFieldsIndex = $enhancedFieldsIndexes[$enhancedFieldToApply];
                    $result[0][$enhancedFieldToApply] = $result[0]["Field{$enhancedFieldsIndex}"];
                }
            }
        }

        return $result;
    }

    protected function removeSpecialChars($str)
    {
        $regex = '/[^A-Za-z0-9]/';

        $result = preg_replace($regex, '', $str);

        return $result;
    }
}
