<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * The SEVERITY_* values mirror the real interface's, because
 * UnverifiedAdminOrderMessage::getSeverity() returns one of them and a test asserting the
 * severity would otherwise be asserting against a number this file invented.
 */

namespace Magento\Framework\Notification;

if (!interface_exists(\Magento\Framework\Notification\MessageInterface::class, false)) {
    interface MessageInterface
    {
        const SEVERITY_CRITICAL = 1;
        const SEVERITY_MAJOR = 2;
        const SEVERITY_MINOR = 3;
        const SEVERITY_NOTICE = 4;

        /**
         * @return string
         */
        public function getIdentity();

        /**
         * @return bool
         */
        public function isDisplayed();

        /**
         * @return string
         */
        public function getText();

        /**
         * @return int
         */
        public function getSeverity();
    }
}
