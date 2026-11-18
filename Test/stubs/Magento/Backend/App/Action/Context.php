<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because the admin Capture controllers type-hint it in their constructors. The real
 * class extends Framework\App\Action\Context; this stub does not, because the Magento/Framework
 * stubs load after Magento/Backend and the controllers call nothing on it.
 */

namespace Magento\Backend\App\Action;

if (!class_exists(\Magento\Backend\App\Action\Context::class, false)) {
    class Context
    {
    }
}
