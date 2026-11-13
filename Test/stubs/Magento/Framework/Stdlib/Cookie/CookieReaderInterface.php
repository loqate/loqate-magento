<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Framework\Stdlib\Cookie;

if (!interface_exists(\Magento\Framework\Stdlib\Cookie\CookieReaderInterface::class, false)) {
    interface CookieReaderInterface
    {
        public function getCookie($name, $default = null);
    }
}
