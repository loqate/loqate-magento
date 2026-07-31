<?php

/**
 * Lightweight test stub for Magento's global translation function.
 *
 * Magento defines __() in vendor/magento/framework/functions.php (loaded through
 * Composer's "files" autoload), so this stub is a no-op inside a real Magento
 * instance. Outside one, Helper\Validator's error branches call __() to build
 * their messages, so the unit tests need it to exist. The real function returns
 * a Phrase; this returns the interpolated string, and both stringify the same,
 * so tests must compare messages with a (string) cast.
 */

if (!function_exists('__')) {
    function __($text, ...$args)
    {
        $result = (string)$text;
        foreach ($args as $index => $arg) {
            $result = str_replace('%' . ($index + 1), (string)$arg, $result);
        }

        return $result;
    }
}
