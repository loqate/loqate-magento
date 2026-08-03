<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Observer\QuoteSubmitBefore throws this to abort an order submission, and catches it
 * around State::getAreaCode(). The real constructor takes a Phrase; this one accepts
 * anything stringable, because the Test/stubs __() returns a plain string.
 */

namespace Magento\Framework\Exception;

if (!class_exists(\Magento\Framework\Exception\LocalizedException::class, false)) {
    class LocalizedException extends \Exception
    {
        /**
         * @param mixed $phrase
         * @param \Throwable|null $cause
         * @param int $code
         */
        public function __construct($phrase = '', ?\Throwable $cause = null, $code = 0)
        {
            parent::__construct((string)$phrase, (int)$code, $cause);
        }
    }
}
