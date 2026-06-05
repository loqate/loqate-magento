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

require __DIR__ . '/../vendor/autoload.php';

// Magento framework stubs the unit tests depend on (skipped automatically when
// the real Magento classes are available). registration.php guards its own
// ComponentRegistrar call, so the Composer "files" autoload is safe here.
require __DIR__ . '/stubs/magento.php';
