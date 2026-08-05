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
         * Declared without a return type on purpose: the real interface returns
         * StoreInterface[] and a narrower signature here would not be satisfiable by the
         * lightweight doubles the unit tests use.
         *
         * @param bool $withDefault
         * @param bool $codeKey
         * @return array
         */
        public function getStores($withDefault = false, $codeKey = false);
    }
}
