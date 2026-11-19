<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed so a test can double the JSON result Helper\StorefrontCaptureGate returns for an
 * over-limit client.
 */

namespace Magento\Framework\Controller\Result;

use Magento\Framework\Controller\ResultInterface;

// The bootstrap loads stubs in path order, which puts this file before the interface it implements.
require_once __DIR__ . '/../ResultInterface.php';

if (!class_exists(\Magento\Framework\Controller\Result\Json::class, false)) {
    class Json implements ResultInterface
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
         * @param mixed $data
         * @param bool $cycleCheck
         * @param array $options
         * @return $this
         */
        public function setData($data, $cycleCheck = false, $options = [])
        {
            return $this;
        }
    }
}
