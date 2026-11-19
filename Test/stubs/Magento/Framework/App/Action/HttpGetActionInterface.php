<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because the admin Capture controllers implement it. The real interface is an empty
 * marker extending Framework\App\ActionInterface; that interface has no stub, so this one
 * declares its execute() directly.
 */

namespace Magento\Framework\App\Action;

if (!interface_exists(\Magento\Framework\App\Action\HttpGetActionInterface::class, false)) {
    interface HttpGetActionInterface
    {
        /**
         * @return mixed
         */
        public function execute();
    }
}
