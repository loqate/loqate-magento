<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Helper\StorefrontCaptureGate reads the time through it, which is what lets a
 * test move the clock into the next rate-limit window.
 */

namespace Magento\Framework\Stdlib\DateTime;

if (!class_exists(\Magento\Framework\Stdlib\DateTime\DateTime::class, false)) {
    class DateTime
    {
        /**
         * @param int|string|\DateTimeInterface|null $input
         * @return int|false
         */
        public function gmtTimestamp($input = null)
        {
            return time();
        }
    }
}
