<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Directory\Model;

if (!class_exists(\Magento\Directory\Model\RegionFactory::class, false)) {
    class RegionFactory
    {
        public function create(array $data = [])
        {
            return null;
        }
    }
}
