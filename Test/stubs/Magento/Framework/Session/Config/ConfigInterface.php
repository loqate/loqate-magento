<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Only getName() is stubbed: Observer\QuoteSubmitBefore reads the session cookie name from it.
 */

namespace Magento\Framework\Session\Config;

if (!interface_exists(\Magento\Framework\Session\Config\ConfigInterface::class, false)) {
    interface ConfigInterface
    {
        public function getName();
    }
}
