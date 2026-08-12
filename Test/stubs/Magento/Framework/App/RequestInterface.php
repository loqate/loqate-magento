<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Only the members Helper\Controller actually uses are declared: it reads request
 * parameters and nothing else. Adding the rest of Magento's RequestInterface here would
 * make the stub a maintenance liability without making any test stronger.
 */

namespace Magento\Framework\App;

if (!interface_exists(\Magento\Framework\App\RequestInterface::class, false)) {
    interface RequestInterface
    {
        public function getParam($key, $defaultValue = null);

        public function getParams();
    }
}
