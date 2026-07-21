<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 */

namespace Magento\Framework\Serialize;

if (!interface_exists(\Magento\Framework\Serialize\SerializerInterface::class, false)) {
    interface SerializerInterface
    {
        public function serialize($data);

        public function unserialize($string);
    }
}
