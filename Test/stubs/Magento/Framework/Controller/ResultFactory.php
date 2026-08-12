<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Helper\Controller type-hints this class in its constructor, so a test
 * that builds a Controller - and therefore anything covering the captured-address store
 * it owns - cannot even create a double for it without the type existing.
 */

namespace Magento\Framework\Controller;

if (!class_exists(\Magento\Framework\Controller\ResultFactory::class, false)) {
    class ResultFactory
    {
        const TYPE_JSON = 'json';

        const TYPE_RAW = 'raw';

        public function create($type, array $arguments = [])
        {
            return null;
        }
    }
}
