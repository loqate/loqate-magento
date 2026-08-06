<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * The address counterpart of the Index\Validate stub, needed because Plugin\Admin\ValidateAddress
 * type-hints it as its subject. That plugin writes the PHONE bypass list from adminhtml.
 *
 * @see \Magento\Customer\Controller\Adminhtml\Index\Validate for why getRequest() is untyped.
 */

namespace Magento\Customer\Controller\Adminhtml\Address;

if (!class_exists(\Magento\Customer\Controller\Adminhtml\Address\Validate::class, false)) {
    class Validate
    {
        /**
         * @return mixed
         */
        public function getRequest()
        {
            return null;
        }

        /**
         * @return mixed
         */
        public function execute()
        {
            return null;
        }
    }
}
