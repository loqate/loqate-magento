<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\Admin\OrderSave type-hints it as the subject of its around-plugin.
 * OrderSave writes BOTH contact bypass lists - it validates the phone number on each address
 * and the account email - from adminhtml, where the customer session carries no customer id, so
 * it is the widest of the three admin writers and the one whose retained data most needs to be
 * shown to be a digest.
 *
 * @see \Magento\Customer\Controller\Adminhtml\Index\Validate for why getRequest() is untyped.
 */

namespace Magento\Sales\Controller\Adminhtml\Order\Create;

if (!class_exists(\Magento\Sales\Controller\Adminhtml\Order\Create\Save::class, false)) {
    class Save
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
