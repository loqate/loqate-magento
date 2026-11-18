<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Helper\StorefrontCaptureGate::refusal() declares it as its return type.
 */

namespace Magento\Framework\Controller;

if (!interface_exists(\Magento\Framework\Controller\ResultInterface::class, false)) {
    interface ResultInterface
    {
        /**
         * @param int $httpCode
         * @return $this
         */
        public function setHttpResponseCode($httpCode);

        /**
         * @param string $name
         * @param string $value
         * @param bool $replace
         * @return $this
         */
        public function setHeader($name, $value, $replace = false);

        /**
         * @param \Magento\Framework\App\Response\HttpInterface $response
         * @return $this
         */
        public function renderResult($response);
    }
}
