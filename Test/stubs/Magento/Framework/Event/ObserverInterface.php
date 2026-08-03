<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Framework\Event;

if (!interface_exists(\Magento\Framework\Event\ObserverInterface::class, false)) {
    interface ObserverInterface
    {
        /**
         * @param Observer $observer
         * @return void
         */
        public function execute(Observer $observer);
    }
}
