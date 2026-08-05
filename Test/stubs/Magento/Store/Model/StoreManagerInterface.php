<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Store\Model;

if (!interface_exists(\Magento\Store\Model\StoreManagerInterface::class, false)) {
    interface StoreManagerInterface
    {
        /**
         * Signature copied from the framework's, including the absence of a native return type
         * and both default values. It is NOT loosened for the tests' benefit: a stub that
         * diverges from the interface it stands in for lets a real incompatibility pass a green
         * suite, which is the one thing a stub must never do.
         *
         * @param bool $withDefault
         * @param bool $codeKey
         * @return \Magento\Store\Api\Data\StoreInterface[]
         */
        public function getStores($withDefault = false, $codeKey = false);
    }
}
