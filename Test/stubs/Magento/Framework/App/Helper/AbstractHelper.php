<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Exposes $scopeConfig from the Context exactly as the real AbstractHelper does, because
 * Helper\Data's config reads go through that inherited property. Without it, Data's own methods
 * could not be exercised at all and every test had to mock Data wholesale - which is how a
 * scope-blind config read stayed invisible to a green suite.
 */

namespace Magento\Framework\App\Helper;

if (!class_exists(\Magento\Framework\App\Helper\AbstractHelper::class, false)) {
    class AbstractHelper
    {
        /** @var \Magento\Framework\App\Config\ScopeConfigInterface|null */
        protected $scopeConfig;

        public function __construct(Context $context)
        {
            $this->scopeConfig = $context->getScopeConfig();
        }
    }
}
