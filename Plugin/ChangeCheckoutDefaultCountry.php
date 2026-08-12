<?php

namespace Loqate\ApiIntegration\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Extra;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\CountryFactory;

/**
 * Pre-selects a country in the checkout address fieldsets from the request's IP address.
 *
 * @see Plugin\ChangeAddressDefaultCountry for why the IP-country cache goes through
 *      Helper\ShopperScopedSessionStores' named accessors and why that one attribute is
 *      deliberately NOT enrolled in the shopper flush (LOQ-17149).
 *
 * NOTE that unlike every other reader of the seam, this plugin runs while a PAGE is being
 * rendered. That is safe only because getIpCountry()/setIpCountry() do not run the ownership
 * check and therefore write nothing of their own - the warning on
 * ShopperScopedSessionStores::enforceOwnership() about not routing a cacheable path through
 * getData() is about the ENROLLED accessors, and this class does not use them.
 */
class ChangeCheckoutDefaultCountry
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

    public function afterProcess(
        LayoutProcessorInterface $subject,
        array $jsLayout
    ) {
        if (!$this->helper->getConfigValue('loqate_settings/ipcountry_settings/enable_checkout')) {
            return $jsLayout;
        }

        $countryResult = $this->sessionStores->getIpCountry();
        if (!$countryResult) {
            $countryResult = $this->extra->ipToCountry();
            $this->sessionStores->setIpCountry($countryResult);
        }

        if (isset($countryResult['Iso2']) && $countryResult['Iso2'] != null) {

            $countryCode = strtoupper($countryResult['Iso2']);

            try {
                $countryModel = $this->countryFactory->create()->loadByCode($countryCode);
                if ($countryModel->getId()) {
                    if(isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
                    ['children']['shippingAddress']['children']['shipping-address-fieldset']['children']['country_id'])){
                        $shippingAddressPath = &$jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
                        ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'];
                        $shippingAddressPath['country_id']['value'] = $countryCode;
                    }

                    if(isset($jsLayout['components']['checkout']['children']['steps']['children']
                    ['billing-step']['children']['payment']['children']
                    ['payments-list']['children']['checkmo-form']['children']
                    ['form-fields']['children']['country_id'])){
                        $billingAddressPath = &$jsLayout['components']['checkout']['children']['steps']['children']
                        ['billing-step']['children']['payment']['children']
                        ['payments-list']['children']['checkmo-form']['children']
                        ['form-fields']['children'];
    
                        $billingAddressPath['country_id']['value'] = $countryCode;
                    }
                }
            } catch (\Exception) {
                return $jsLayout;
            }
        }

        return $jsLayout;
    }
}
