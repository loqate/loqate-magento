<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Carries the scope config because that is the one collaborator this module's helpers reach
 * through it: AbstractHelper exposes it as $this->scopeConfig, and Helper\Data reads every
 * config value that way. The argument is optional so a bare Context still constructs.
 */

namespace Magento\Framework\App\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;

if (!class_exists(\Magento\Framework\App\Helper\Context::class, false)) {
    class Context
    {
        /** @var ScopeConfigInterface|null */
        private $scopeConfig;

        /**
         * @param ScopeConfigInterface|null $scopeConfig
         */
        public function __construct($scopeConfig = null)
        {
            $this->scopeConfig = $scopeConfig;
        }

        /**
         * @return ScopeConfigInterface|null
         */
        public function getScopeConfig()
        {
            return $this->scopeConfig;
        }
    }
}
