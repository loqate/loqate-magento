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
         * and Helper\ShopperScopedSessionStores calls it on every session access to decide
         * whether the shopper-scoped caches still belong to the current shopper.
         *
         * @return int|string|null
         */
        public function getCustomerId()
        {
            return null;
        }

        /**
         * Magento's own typed setter for the rejected-form POST, and its address counterpart.
         *
         * Declared for the same reason getData()/setData() are: Plugin\AbstractPlugin calls
         * them (rememberCustomerFormData(), rememberAddressFormData()), so a test double has to
         * be able to answer them, and PHPUnit can only CONFIGURE a method the class declares -
         * on the real Magento class these are __call-forwarded to Session\Storage, which is why
         * Test\Unit\Plugin\ShopperSessionHarness splits its list by method_exists().
         *
         * They are here as well as in that double because the values they carry are a whole
         * submitted form: an assertion that the session holds no readable email address or
         * phone number is only worth anything if these two calls actually reach the session
         * payload the assertion searches (LOQ-17149).
         *
         * @param mixed $formData
         * @return $this
         */
        public function setCustomerFormData($formData = null)
        {
            return $this;
        }

        /**
         * @see self::setCustomerFormData()
         * @param mixed $formData
         * @return $this
         */
        public function setAddressFormData($formData = null)
        {
            return $this;
        }
    }
}
