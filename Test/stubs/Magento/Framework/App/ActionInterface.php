<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because the storefront Capture controllers implement it.
 */

namespace Magento\Framework\App;

if (!interface_exists(\Magento\Framework\App\ActionInterface::class, false)) {
    interface ActionInterface
    {
        /**
         * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
         */
        public function execute();
    }
}
