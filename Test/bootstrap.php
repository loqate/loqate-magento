<?php

/**
 * PHPUnit bootstrap for the module's isolated unit tests.
 *
 * The tests run without a full Magento installation. We autoload the module
 * (and PHPUnit) via Composer, then register lightweight stubs for the handful
 * of Magento framework classes the unit tests depend on. The stubs are only
 * defined when the real classes are absent, so the exact same suite also runs
 * unchanged inside a real Magento instance (e.g. via dev/tests/unit).
 */

declare(strict_types=1);

// Must be loaded BEFORE the Composer autoloader: vendor/autoload.php eagerly
// runs the module's registration.php (a "files" autoload), which calls Magento's
// ComponentRegistrar. This stub turns that into a no-op outside Magento.
require __DIR__ . '/stubs/registration.php';

require __DIR__ . '/../vendor/autoload.php';

// Remaining Magento framework stubs the unit tests depend on.
require __DIR__ . '/stubs/magento.php';
