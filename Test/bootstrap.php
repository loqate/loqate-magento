<?php

/**
 * PHPUnit bootstrap for the module's isolated unit tests.
 *
 * The tests run without a full Magento installation. We autoload the module
 * (and PHPUnit) via Composer, then register lightweight stubs for the handful
 * of Magento framework classes the unit tests depend on.
 *
 * Scope: this bootstrap is for running the suite standalone (composer install in
 * the module directory, then vendor/bin/phpunit). Each stub guards itself with
 * class_exists($class, false) - autoloading disabled - so it only checks for a
 * class that is ALREADY loaded. It therefore does not detect a real Magento class
 * that Composer would merely be able to autoload: under this bootstrap the stub
 * wins and shadows it. Running the tests against the real framework classes needs
 * Magento's own dev/tests/unit bootstrap (which loads them first) rather than this
 * file; the test doubles are written to work either way, but this bootstrap alone
 * does not put the real classes in play.
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
