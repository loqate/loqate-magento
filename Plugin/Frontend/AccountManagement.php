<?php

namespace Loqate\ApiIntegration\Plugin\Frontend;

use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Customer\Model\AccountManagement as CoreAccountManagement;

/**
 * Class AccountManagement
 */
class AccountManagement extends AbstractPlugin
{
    /**
     * Store guest email address, so it can be later checked if valid
     *
     * Reached through AbstractPlugin::rememberPendingEmailAddress() since LOQ-17149 rather
     * than through a raw session write, so the address is flushed if the shopper changes
     * before Plugin\Frontend\CheckoutShippingInformation gets to verify it - see
     * Helper\ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY for what an inherited
     * pending address does to the next shopper's checkout.
     */
    public function beforeIsEmailAvailable(
        CoreAccountManagement $subject,
        $customerEmail,
        $websiteId = null
    ) {
        if ($this->helper->getConfigValue('loqate_settings/email_settings/enable_checkout')) {
            if ($customerEmail) {
                $this->rememberPendingEmailAddress($customerEmail);
            }
        }

        return [$customerEmail, $websiteId];
    }
}
