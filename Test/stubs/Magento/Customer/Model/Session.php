<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Customer\Model;

if (!class_exists(\Magento\Customer\Model\Session::class, false)) {
    class Session
    {
        public function getData($key = '', $clear = false)
        {
            return null;
        }

        public function setData($key, $value = null)
        {
            return $this;
        }

        /**
         * Identity of the logged-in shopper, null for a guest.
         *
         * Unlike getData()/setData(), the real Magento\Customer\Model\Session DECLARES
         * this method (it is @api there), so it has to be declared here too: the test
         * doubles only addMethods() what the class under test does not already declare,
         * and Helper\ShopperScopedAddressStores calls it on every session access to decide
         * whether the shopper-scoped caches still belong to the current shopper.
         *
         * @return int|string|null
         */
        public function getCustomerId()
        {
            return null;
        }
    }
}
