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
//
// One class per file (mirroring PSR-4 layout) so that when the module is
// symlinked into a Magento instance, `bin/magento setup:di:compile` — whose
// PhpScanner resolves symlinked files by real path and therefore cannot exclude
// this Test/ directory — can parse each stub without mangling multi-namespace
// files. Loaded via a recursive scan so new stubs are picked up automatically.
$stubIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/stubs', FilesystemIterator::SKIP_DOTS)
);
$stubFiles = [];
foreach ($stubIterator as $stubFile) {
    if ($stubFile->isFile() && $stubFile->getExtension() === 'php') {
        $stubFiles[] = $stubFile->getPathname();
    }
}
sort($stubFiles); // deterministic load order
foreach ($stubFiles as $stubFile) {
    require $stubFile;
}
