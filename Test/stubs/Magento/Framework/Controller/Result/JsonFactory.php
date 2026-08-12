<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed only because Plugin\AbstractPlugin type-hints it in its constructor. The signature is
 * copied from Magento\Framework\Controller\Result\JsonFactory, defaults included, so the stub
 * cannot mask an incompatibility.
 */

namespace Magento\Framework\Controller\Result;

if (!class_exists(\Magento\Framework\Controller\Result\JsonFactory::class, false)) {
    class JsonFactory
    {
        /**
         * @param array $data
         * @return \Magento\Framework\Controller\Result\Json|null
         */
        public function create(array $data = [])
        {
            return null;
        }
    }
}
