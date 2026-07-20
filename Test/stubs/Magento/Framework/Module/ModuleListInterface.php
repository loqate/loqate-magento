<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Framework\Module;

if (!interface_exists(\Magento\Framework\Module\ModuleListInterface::class, false)) {
    interface ModuleListInterface
    {
        public function getOne($name);
    }
}
