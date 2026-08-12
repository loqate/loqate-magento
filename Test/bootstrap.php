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

// A random_bytes() inside the module's Helper namespace, so that one guarantee - the contact
// digests failing CLOSED when the platform has no usable CSPRNG - can be observed at all.
// Required here rather than autoloaded for two reasons: Composer's PSR-4 does not autoload
// functions, and something that shadows a core PHP function for the whole suite belongs where a
// reader of the bootstrap will see it rather than arriving as a side effect of loading a test.
// It delegates to the real \random_bytes() unless a test explicitly switches it, and the switch
// is restored in a finally, so every other test in the suite runs against the real CSPRNG.
// See Test/Support/Csprng.php for the full argument.
require __DIR__ . '/Support/CsprngOverride.php';

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
