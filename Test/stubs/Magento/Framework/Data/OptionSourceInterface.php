<?php

/**
 * Lightweight test stub — defined only when the real interface is absent.
 * One type per file so Magento's DI PhpScanner can parse it.
 *
 * Needed so the admin-field source models under Model/Config/Source can be LOADED by a test
 * at all: every one of them declares `implements OptionSourceInterface`, so without this the
 * class cannot be autoloaded outside a Magento instance and nothing can assert what a
 * merchant is offered in the admin form. The method signature mirrors the framework's,
 * because Model\Config\Source\AddressQualityIndex::toOptionArray() is what
 * Test\Unit\Model\Config\Source\AddressQualityIndexTest reads the selectable address quality
 * indexes out of (LOQ-17148).
 */

namespace Magento\Framework\Data;

if (!interface_exists(\Magento\Framework\Data\OptionSourceInterface::class, false)) {
    interface OptionSourceInterface
    {
        /**
         * @return array
         */
        public function toOptionArray();
    }
}
