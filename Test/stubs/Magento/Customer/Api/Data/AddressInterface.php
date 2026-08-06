<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed only because Plugin\ChangeAddressDefaultCountry type-hints it as the subject of its
 * after-plugin on getCountryId(). That plugin reads the IP-country cache, which is the one
 * session attribute deliberately NOT enrolled in the shopper flush, so it is what a test of
 * that exclusion has to drive. Declares getCountryId() alone of the real interface's many
 * methods: a missing method can only make a test fail loudly, never pass falsely.
 */

namespace Magento\Customer\Api\Data;

if (!interface_exists(\Magento\Customer\Api\Data\AddressInterface::class, false)) {
    interface AddressInterface
    {
        /**
         * @return string|null
         */
        public function getCountryId();
    }
}
