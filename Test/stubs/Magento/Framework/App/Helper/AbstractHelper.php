<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Framework\App\Helper;

if (!class_exists(\Magento\Framework\App\Helper\AbstractHelper::class, false)) {
    class AbstractHelper
    {
        public function __construct(Context $context)
        {
        }
    }
}
