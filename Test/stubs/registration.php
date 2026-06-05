<?php

/**
 * Pre-autoload stub for Magento's ComponentRegistrar.
 *
 * The module's composer "files" autoload eagerly runs registration.php whenever
 * vendor/autoload.php is required. Outside a Magento install that would fatal,
 * because ComponentRegistrar does not exist. We define a no-op stub *before*
 * the Composer autoloader runs so module registration becomes a harmless no-op
 * in the test environment. Guarded so the real class always wins inside Magento.
 */

namespace Magento\Framework\Component {
    if (!class_exists(\Magento\Framework\Component\ComponentRegistrar::class, false)) {
        class ComponentRegistrar
        {
            public const MODULE = 'module';
            public const LIBRARY = 'library';
            public const THEME = 'theme';
            public const LANGUAGE = 'language';
            public const SETUP = 'setup';

            public static function register($type, $componentName, $path): void
            {
            }
        }
    }
}
