<?php

namespace Loqate\ApiIntegration\Plugin\Frontend;

use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\StateException;

/**
 * Class CheckoutShippingInformation
 */
class CheckoutShippingInformation extends AbstractPlugin
{
    /**
     * Validate shipping address information
     *
     * @throws StateException
     */
    public function aroundSaveAddressInformation(
        ShippingInformationManagement $subject,
        callable                      $proceed,
        $cartId,
        $addressInformation
    ) {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return $proceed($cartId, $addressInformation);
        }

        if ($shippingAddress = $addressInformation->getShippingAddress()->getData()) {
            $errors = [];
            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($shippingAddress);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                if (isset($shippingAddress['telephone'])) {
                    $errorMessage = $this->validatePhone($shippingAddress['telephone'], $shippingAddress['country_id']);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }

            // The pending address is read through the shopper-ownership guard since
            // LOQ-17149, so an address left behind by an abandoned checkout cannot be
            // verified - billably - inside the NEXT shopper's checkout, nor block them with a
            // message about an address that is nowhere on their form. Cleared only on a
            // successful verify, which is why the flush is what covers the abandoned case.
            if ($this->helper->getConfigValue('loqate_settings/email_settings/enable_checkout')
            && ($email = $this->pendingEmailAddress())) {
                $errorMessage = $this->validateEmail($email);
                if ($errorMessage) {
                    $errors[] = $errorMessage;
                } else {
                    $this->clearPendingEmailAddress();
                }
            }

            if ($errors) {
                throw new StateException(__(implode(PHP_EOL, $errors)));
            }
        }

        return $proceed($cartId, $addressInformation);
    }
}
