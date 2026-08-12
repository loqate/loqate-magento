<?php

namespace Loqate\ApiIntegration\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Extra;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\CountryFactory;

/**
 * Pre-selects a country in the customer address form from the request's IP address.
 *
 * The IP-country cache is reached through Helper\ShopperScopedSessionStores' named
 * getIpCountry()/setIpCountry() pair since LOQ-17149, rather than through a raw session
 * getData()/setData() duplicated here and in Plugin\ChangeCheckoutDefaultCountry. That is not
 * because the attribute is shopper-scoped - it is NOT, and the seam's generic accessors refuse
 * it for that reason - but so that no class in this module holds a raw customer session it can
 * reach an arbitrary attribute through. getIpCountry() carries the argument for why this one
 * attribute is deliberately excluded from the shopper flush.
 */
class ChangeAddressDefaultCountry
{
    protected $countryFactory;
    private Extra $extra;
    private Data $helper;

    /**
     * @var ShopperScopedSessionStores Reached only for the IP-country cache, through the named
     *      accessors. The raw Session is deliberately not kept.
     */
    private ShopperScopedSessionStores $sessionStores;

    public function __construct(CountryFactory $countryFactory, Extra $extra, Data $helper, Session $session)
    {
        $this->countryFactory = $countryFactory;
        $this->extra = $extra;
        $this->helper = $helper;

        $this->sessionStores = new ShopperScopedSessionStores($session);
    }

    public function afterGetCountryId(AddressInterface $subject, $result)
    {
        if (!$this->helper->getConfigValue('loqate_settings/ipcountry_settings/enable_customer_account')) {
            return $result;
        }

        $countryResult = $this->sessionStores->getIpCountry();
        if (!$countryResult) {
            $countryResult = $this->extra->ipToCountry();
            $this->sessionStores->setIpCountry($countryResult);
        }

        if (isset($countryResult['Iso2']) && $countryResult['Iso2'] != null) {

            $countryCode = strtoupper($countryResult['Iso2']);

            if (empty($result)) {
                try {
                    $countryModel = $this->countryFactory->create()->loadByCode($countryCode);
                    if ($countryModel->getId()) {
                        return $countryModel->getCountryId();
                    }
                } catch (\Exception) {
                    return $result;
                }
            }
        }

        return $result;
    }
}
