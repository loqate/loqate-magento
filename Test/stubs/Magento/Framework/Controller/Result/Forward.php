<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed so a test can double the forward-to-noroute result Helper\StorefrontCaptureGate
 * returns when storefront lookup is switched off.
 */

namespace Magento\Framework\Controller\Result;

use Magento\Framework\Controller\ResultInterface;

// The bootstrap loads stubs in path order, which puts this file before the interface it implements.
require_once __DIR__ . '/../ResultInterface.php';

if (!class_exists(\Magento\Framework\Controller\Result\Forward::class, false)) {
    class Forward implements ResultInterface
    {
        public function setHttpResponseCode($httpCode)
        {
            return $this;
        }

        public function setHeader($name, $value, $replace = false)
        {
            return $this;
        }

        public function renderResult($response)
        {
            return $this;
        }

        /**
         * @param string $action
         * @return $this
         */
        public function forward($action)
        {
            return $this;
        }
    }
}
