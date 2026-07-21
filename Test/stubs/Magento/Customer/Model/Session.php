<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Customer\Model;

if (!class_exists(\Magento\Customer\Model\Session::class, false)) {
    class Session
    {
        public function getData($key = '', $clear = false)
        {
            return null;
        }

        public function setData($key, $value = null)
        {
            return $this;
        }
    }
}
