<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Plugin\Frontend\CheckoutBillingAddress throws this to reject a billing address, on the same
 * request that records the billing-error gate, so a test covering the gate has to be able to
 * catch it. Extends the LocalizedException stub because the real class does, and because the
 * tests distinguish "rejected the address" from any other failure by the type.
 */

namespace Magento\Framework\Exception;

// The parent has to be loaded explicitly: Test/bootstrap.php loads the stubs in sorted
// filename order - this file before LocalizedException.php - and no autoloader can resolve a
// Magento class in this harness. LocalizedException.php guards its own declaration, so loading
// it here and again from the bootstrap scan is safe.
require_once __DIR__ . '/LocalizedException.php';

if (!class_exists(\Magento\Framework\Exception\InputException::class, false)) {
    class InputException extends LocalizedException
    {
    }
}
