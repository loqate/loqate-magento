<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * The guest counterpart of the PaymentInformationManagement stub, needed because
 * Plugin\Frontend\PlaceOrderGuest type-hints it as its subject. Its
 * savePaymentInformationAndPlaceOrder() takes the guest's email address as a second argument,
 * which is why the two readers of the billing-error gate cannot share one plugin.
 */

namespace Magento\Checkout\Model;

if (!class_exists(\Magento\Checkout\Model\GuestPaymentInformationManagement::class, false)) {
    class GuestPaymentInformationManagement
    {
        /**
         * @param int $cartId
         * @param string $email
         * @param mixed $paymentMethod
         * @param mixed $billingAddress
         * @return int|null
         */
        public function savePaymentInformationAndPlaceOrder($cartId, $email, $paymentMethod, $billingAddress = null)
        {
            return null;
        }
    }
}
