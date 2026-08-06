<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed only because Plugin\Frontend\CheckoutBillingAddress type-hints it as the subject of
 * its around-plugin, and that plugin is the ONLY writer of the billing-error gate, so no test
 * can cover the gate's two directions without being able to call aroundAssign(). Declares
 * assign() alone; the tests pass a callable of their own as $proceed and never reach the real
 * method.
 */

namespace Magento\Quote\Model;

if (!class_exists(\Magento\Quote\Model\BillingAddressManagement::class, false)) {
    class BillingAddressManagement
    {
        /**
         * @param int $cartId
         * @param mixed $address
         * @param bool $useForShipping
         * @return int|null
         */
        public function assign($cartId, $address, $useForShipping = false)
        {
            return null;
        }
    }
}
