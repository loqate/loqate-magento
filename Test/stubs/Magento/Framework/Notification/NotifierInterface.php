<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Signatures mirror the framework's: Helper\Validator raises an admin inbox notice through
 * addMajor() when the email API reports an error.
 */

namespace Magento\Framework\Notification;

if (!interface_exists(\Magento\Framework\Notification\NotifierInterface::class, false)) {
    interface NotifierInterface
    {
        /**
         * @param int $severity
         * @param string $title
         * @param string|string[] $description
         * @param string $url
         * @param bool $isInternal
         * @return $this
         */
        public function add($severity, $title, $description, $url = '', $isInternal = true);

        /**
         * @param string $title
         * @param string|string[] $description
         * @param string $url
         * @param bool $isInternal
         * @return $this
         */
        public function addCritical($title, $description, $url = '', $isInternal = true);

        /**
         * @param string $title
         * @param string|string[] $description
         * @param string $url
         * @param bool $isInternal
         * @return $this
         */
        public function addMajor($title, $description, $url = '', $isInternal = true);

        /**
         * @param string $title
         * @param string|string[] $description
         * @param string $url
         * @param bool $isInternal
         * @return $this
         */
        public function addMinor($title, $description, $url = '', $isInternal = true);

        /**
         * @param string $title
         * @param string|string[] $description
         * @param string $url
         * @param bool $isInternal
         * @return $this
         */
        public function addNotice($title, $description, $url = '', $isInternal = true);
    }
}
