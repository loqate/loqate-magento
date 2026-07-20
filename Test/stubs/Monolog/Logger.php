<?php

/**
 * Lightweight test stub — see Test/stubs/README notes in bootstrap.php.
 * Defined only when the real class is absent, so it is a no-op inside a real
 * Magento instance. One class per file so Magento's DI PhpScanner can parse it.
 */

namespace Monolog;

if (!class_exists(\Monolog\Logger::class, false)) {
    class Logger
    {
        public function __construct($name = 'test', array $handlers = [], array $processors = [])
        {
        }

        public function emergency($message, array $context = []): void
        {
        }

        public function alert($message, array $context = []): void
        {
        }

        public function critical($message, array $context = []): void
        {
        }

        public function error($message, array $context = []): void
        {
        }

        public function warning($message, array $context = []): void
        {
        }

        public function notice($message, array $context = []): void
        {
        }

        public function info($message, array $context = []): void
        {
        }

        public function debug($message, array $context = []): void
        {
        }

        public function log($level, $message, array $context = []): void
        {
        }
    }
}
