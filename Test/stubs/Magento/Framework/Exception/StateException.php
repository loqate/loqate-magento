<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Plugin\Frontend\CheckoutShippingInformation throws this when the shipping step reports any
 * error, including one about the PENDING email address it verifies there. A test that pins
 * "the next shopper is never blocked by an address that is nowhere on their form" needs the
 * type to exist to assert that it was NOT thrown.
 */

namespace Magento\Framework\Exception;

// The parent has to be loaded explicitly: Test/bootstrap.php loads the stubs in sorted
// filename order - LocalizedException.php happens to sort before this file, but relying on that
// would make a stub added later a fatal - and no autoloader can resolve a Magento class in this
// harness. LocalizedException.php guards its own declaration, so loading it twice is safe.
require_once __DIR__ . '/LocalizedException.php';

if (!class_exists(\Magento\Framework\Exception\StateException::class, false)) {
    class StateException extends LocalizedException
    {
    }
}
