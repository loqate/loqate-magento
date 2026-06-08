<?php

namespace Loqate\ApiIntegration\Observer;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates address and phone on quote submit, covering both Luma REST and Hyvä GraphQL checkout paths.
 * The sales_model_service_quote_submit_before event fires regardless of the checkout front-end used.
 */
class QuoteSubmitBefore implements ObserverInterface
{
    /** @var Data */
    private $helper;

    /** @var Validator */
    private $validator;

    /** @var Logger */
    private $logger;

    public function __construct(
        Data $helper,
        Validator $validator,
        Logger $logger
    ) {
        $this->helper  = $helper;
        $this->validator = $validator;
        $this->logger  = $logger;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return;
        }

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getQuote();
        if (!$quote) {
            return;
        }

        $errors = [];

        // --- Shipping address validation ---
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && !$quote->getIsVirtual()) {
            $addressData = $shippingAddress->getData();

            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                $telephone = $shippingAddress->getTelephone();
                $countryId = $shippingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($telephone, $countryId);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
        }

        // --- Billing address validation ---
        $billingAddress = $quote->getBillingAddress();
        if ($billingAddress) {
            $addressData = $billingAddress->getData();

            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                $telephone = $billingAddress->getTelephone();
                $countryId = $billingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($telephone, $countryId);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
        }

        if ($errors) {
            throw new LocalizedException(__(implode(PHP_EOL, $errors)));
        }
    }

    /**
     * @param string $phone
     * @param string|null $country
     * @return \Magento\Framework\Phrase|false
     */
    private function validatePhone(string $phone, ?string $country)
    {
        $response = $this->validator->verifyPhoneNumber($phone, $country);

        if (isset($response['error']) || isset($response['noKeyFound'])) {
            return false;
        }

        if (!$response) {
            return __('The provided phone number is invalid.');
        }

        return false;
    }
}
