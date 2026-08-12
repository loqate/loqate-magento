<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed only because Plugin\AbstractPlugin type-hints it in its constructor. Declares
 * getUrl() alone, of the real interface's many methods: nothing in this module calls anything
 * else on it, and a missing method can only make a test fail loudly, never pass falsely.
 */

namespace Magento\Framework;

if (!interface_exists(\Magento\Framework\UrlInterface::class, false)) {
    interface UrlInterface
    {
        /**
         * @param string|null $routePath
         * @param array|null $routeParams
         * @return string
         */
        public function getUrl($routePath = null, $routeParams = null);
    }
}
