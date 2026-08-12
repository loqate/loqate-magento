<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * This is the exception that DENIES a shopper their order: Plugin\Frontend\PlaceOrder and
 * PlaceOrderGuest throw it when the billing-error gate is set. A test that pins "a gate
 * inherited from the previous shopper must not deny the next one their checkout" needs the
 * type to exist to assert that it was NOT thrown.
 */

namespace Magento\Framework\Exception;

// The parent has to be loaded explicitly: Test/bootstrap.php loads the stubs in sorted
// filename order - this file before LocalizedException.php - and no autoloader can resolve a
// Magento class in this harness. LocalizedException.php guards its own declaration, so loading
// it here and again from the bootstrap scan is safe.
require_once __DIR__ . '/LocalizedException.php';

if (!class_exists(\Magento\Framework\Exception\CouldNotSaveException::class, false)) {
    class CouldNotSaveException extends LocalizedException
    {
    }
}
