<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Observer\QuoteSubmitBefore takes this to exclude the admin area, where
 * Plugin\Admin\OrderSave already verifies the order's addresses in one batch call.
 * getAreaCode() is declared without a return type, exactly like the real one, so a
 * test double can also make it throw LocalizedException - the case the real method
 * hits when no area code has been set yet.
 */

namespace Magento\Framework\App;

if (!class_exists(\Magento\Framework\App\State::class, false)) {
    class State
    {
        /**
         * @return string
         * @throws \Magento\Framework\Exception\LocalizedException
         */
        public function getAreaCode()
        {
            return Area::AREA_GLOBAL;
        }
    }
}
