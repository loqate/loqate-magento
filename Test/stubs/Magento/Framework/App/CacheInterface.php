<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Signatures mirror the framework's: Helper\Validator throttles its email-API-error admin
 * notice through load() (false or '' when absent) and save() with a lifetime.
 */

namespace Magento\Framework\App;

if (!interface_exists(\Magento\Framework\App\CacheInterface::class, false)) {
    interface CacheInterface
    {
        /**
         * @return mixed
         */
        public function getFrontend();

        /**
         * @param string $identifier
         * @return string|false
         */
        public function load($identifier);

        /**
         * @param string $data
         * @param string $identifier
         * @param array $tags
         * @param int|null $lifeTime
         * @return bool
         */
        public function save($data, $identifier, $tags = [], $lifeTime = null);

        /**
         * @param string $identifier
         * @return bool
         */
        public function remove($identifier);

        /**
         * @param array $tags
         * @return bool
         */
        public function clean($tags = []);
    }
}
