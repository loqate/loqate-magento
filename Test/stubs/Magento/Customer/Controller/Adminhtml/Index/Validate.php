<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\Admin\ValidateCustomer type-hints it as the subject of its
 * around-plugin. That plugin writes the email bypass list from ADMINHTML, where the customer
 * session carries no customer id and the shopper flush is therefore a documented no-op, so it
 * is one of the paths whose retained data has to be shown to be a digest rather than a
 * readable address.
 *
 * getRequest() is declared untyped so a test can hand back any object exposing
 * getPostValue(): the real request object is a Magento\Framework\App\RequestInterface, whose
 * stub here deliberately declares only what Helper\Controller uses.
 */

namespace Magento\Customer\Controller\Adminhtml\Index;

if (!class_exists(\Magento\Customer\Controller\Adminhtml\Index\Validate::class, false)) {
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
