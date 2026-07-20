<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Store\Model;

if (!interface_exists(\Magento\Store\Model\StoreManagerInterface::class, false)) {
    interface StoreManagerInterface
    {
    }
}
