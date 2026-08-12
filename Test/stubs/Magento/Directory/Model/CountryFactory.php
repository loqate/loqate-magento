<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because both IP-country plugins - Plugin\ChangeAddressDefaultCountry and
 * Plugin\ChangeCheckoutDefaultCountry - take one in their constructor to turn an ISO code into
 * a country model. create() is untyped so a test can return a double of the country model
 * without Magento\Directory\Model\Country having to exist too.
 */

namespace Magento\Directory\Model;

if (!class_exists(\Magento\Directory\Model\CountryFactory::class, false)) {
    class CountryFactory
    {
        /**
         * @param array $data
         * @return mixed
         */
        public function create(array $data = [])
        {
            return null;
        }
    }
}
