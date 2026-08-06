<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\Frontend\PlaceOrder type-hints it as the subject of its BEFORE plugin
 * on savePaymentInformationAndPlaceOrder(). The method is declared here because the ORDERING
 * matters to the tests: this is the call that assigns the billing address - and therefore the
 * only call that can clear the billing-error gate - and the plugin runs before it.
 */

namespace Magento\Checkout\Model;

if (!class_exists(\Magento\Checkout\Model\PaymentInformationManagement::class, false)) {
    class PaymentInformationManagement
    {
        /**
         * @param int $cartId
         * @param mixed $paymentMethod
         * @param mixed $billingAddress
         * @return int|null
         */
        public function savePaymentInformationAndPlaceOrder($cartId, $paymentMethod, $billingAddress = null)
        {
            return null;
        }
    }
}
