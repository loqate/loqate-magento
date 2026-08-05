<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Signatures mirror the framework's, including the $scopeCode default of null, because
 * Helper\Data::getConfigValue() relies on omitting it and getConfigValueForStore() relies on
 * supplying it - and a test that cannot tell those two calls apart is what let a scope-blind
 * read ship.
 */

namespace Magento\Framework\App\Config;

if (!interface_exists(\Magento\Framework\App\Config\ScopeConfigInterface::class, false)) {
    interface ScopeConfigInterface
    {
        const SCOPE_TYPE_DEFAULT = 'default';

        /**
         * @param string $path
         * @param string $scope
         * @param null|int|string $scopeCode
         * @return mixed
         */
        public function getValue($path, $scope = self::SCOPE_TYPE_DEFAULT, $scopeCode = null);

        /**
         * @param string $path
         * @param string $scope
         * @param null|int|string $scopeCode
         * @return bool
         */
        public function isSetFlag($path, $scope = self::SCOPE_TYPE_DEFAULT, $scopeCode = null);
    }
}
