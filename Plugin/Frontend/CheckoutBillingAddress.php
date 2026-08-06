<?php

namespace Loqate\ApiIntegration\Plugin\Frontend;

use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Model\BillingAddressManagement;

/**
 * Class CheckoutBillingAddress
 */
class CheckoutBillingAddress extends AbstractPlugin
{
    /**
     * Validate billing address information
     *
     * @throws InputException
     */
    public function aroundAssign(
        BillingAddressManagement $subject,
        callable                      $proceed,
        $cartId,
        $address,
        $useForShipping = false
    ) {
        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return $proceed($cartId, $address, $useForShipping);
        }

        if ($billingAddress = $address->getData()) {
            $errors = [];

            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($billingAddress);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                if (isset($billingAddress['telephone'])) {
                    $errorMessage = $this->validatePhone($billingAddress['telephone'], $billingAddress['country_id']);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }

            // THE ONLY WRITER OF THE BILLING-ERROR GATE, in both directions, and therefore
            // the only thing that can ever clear it. Plugin\Frontend\PlaceOrder and
            // PlaceOrderGuest read it in a BEFORE plugin on
            // savePaymentInformationAndPlaceOrder(), so on any flow that submits the billing
            // address with the place-order call rather than in a separate request they throw
            // before this method runs. That is why the gate must be flushed when the shopper
            // changes rather than merely overwritten: an inherited true is otherwise
            // unrecoverable. Reached through AbstractPlugin::recordBillingAddressErrors()
            // since LOQ-17149; see Helper\ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY.
            if ($errors) {
                $this->recordBillingAddressErrors(true);
                throw new InputException(__(implode(PHP_EOL, $errors)));
            }
            $this->recordBillingAddressErrors(false);
        }

        return $proceed($cartId, $address, $useForShipping);
    }
}
