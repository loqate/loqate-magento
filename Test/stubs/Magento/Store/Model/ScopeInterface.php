<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * The constant VALUES matter and are the framework's own ('store', 'website'), not tokens
 * invented here: Helper\Data passes them straight to ScopeConfigInterface::getValue(), so a
 * test asserting the scope a config read used is asserting these strings. Inventing values
 * would let a wrong-scope read pass.
 */

namespace Magento\Store\Model;

if (!interface_exists(\Magento\Store\Model\ScopeInterface::class, false)) {
    interface ScopeInterface
    {
        const SCOPE_STORE = 'store';
        const SCOPE_STORES = 'stores';
        const SCOPE_WEBSITE = 'website';
        const SCOPE_WEBSITES = 'websites';
    }
}
