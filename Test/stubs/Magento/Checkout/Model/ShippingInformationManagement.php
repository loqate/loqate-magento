<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\Frontend\CheckoutShippingInformation type-hints it as the subject of
 * its around-plugin. That plugin is the only READER of the pending email address - it verifies
 * it billably and clears it on success - so the pending-address tests cannot be written
 * without it.
 */

namespace Magento\Checkout\Model;

if (!class_exists(\Magento\Checkout\Model\ShippingInformationManagement::class, false)) {
    class ShippingInformationManagement
    {
        /**
         * @param int $cartId
         * @param mixed $addressInformation
         * @return mixed
         */
        public function saveAddressInformation($cartId, $addressInformation)
        {
            return null;
        }
    }
}
