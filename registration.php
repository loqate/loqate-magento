<?php

use Magento\Framework\Component\ComponentRegistrar;

// Composer's "files" autoload runs this on every autoload, including in the
// unit-test environment where the Magento framework is not present. Guard the
// registration so it is a no-op outside Magento; inside Magento the class
// always exists and the module registers as normal.
if (class_exists(ComponentRegistrar::class)) {
    ComponentRegistrar::register(
        ComponentRegistrar::MODULE,
        'Loqate_ApiIntegration',
        __DIR__
    );
}
