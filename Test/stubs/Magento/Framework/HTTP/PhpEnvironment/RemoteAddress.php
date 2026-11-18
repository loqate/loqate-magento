<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Helper\StorefrontCaptureGate identifies the client through it.
 */

namespace Magento\Framework\HTTP\PhpEnvironment;

if (!class_exists(\Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::class, false)) {
    class RemoteAddress
    {
        /**
         * @param bool $ipToLong
         * @return string|int|false|null
         */
        public function getRemoteAddress(bool $ipToLong = false)
        {
            return false;
        }
    }
}
