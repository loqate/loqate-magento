<?php

/**
 * Lightweight test stub — defined only when the real class is absent.
 * One class per file so Magento's DI PhpScanner can parse it.
 *
 * Needed because Plugin\Frontend\AccountManagement type-hints it as the subject of its
 * before-plugin on isEmailAvailable(). That plugin is the only WRITER of the pending email
 * address - the guest checkout's email field calls it on every keystroke-completed blur - so
 * the pending-address tests cannot be written without it.
 */

namespace Magento\Customer\Model;

if (!class_exists(\Magento\Customer\Model\AccountManagement::class, false)) {
    class AccountManagement
    {
        /**
         * @param string $customerEmail
         * @param int|null $websiteId
         * @return bool
         */
        public function isEmailAvailable($customerEmail, $websiteId = null)
        {
            return true;
        }
    }
}
