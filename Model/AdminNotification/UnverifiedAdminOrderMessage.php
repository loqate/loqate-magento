<?php

namespace Loqate\ApiIntegration\Model\AdminNotification;

use Loqate\ApiIntegration\Helper\Data;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Admin system message for the one toggle combination that verifies nothing.
 *
 * Observer\QuoteSubmitBefore returns early for the admin area, because Plugin\Admin\OrderSave
 * already verifies both addresses of an admin-created order in ONE batch call and running the
 * observer as well would bill up to two more single-address calls for the same order. The
 * consequence is that admin order create is governed SOLELY by the
 * loqate_settings/*_settings/enable_create_order_admin toggles.
 *
 * That is the intended reading of those toggles - "enable create order admin = No" is the
 * merchant asking for admin order create not to be verified - EXCEPT in one combination. With
 * enable_create_order_admin OFF and enable_checkout ON, the observer used to be the only thing
 * verifying an admin-created order, and now nothing is. A merchant who set those two toggles
 * that way believed they had verification switched on somewhere.
 *
 * This exists because that fact was previously recorded only in a docblock, which is not a
 * place a merchant looks. The check is evaluated per request rather than stored, so the notice
 * appears and clears as the configuration changes.
 */
class UnverifiedAdminOrderMessage implements MessageInterface
{
    /**
     * Identity of this message.
     *
     * Fixed rather than derived from the current configuration: Magento uses the identity to
     * remember that an admin dismissed the notice, so varying it per config state would make a
     * dismissal apply to only one combination and resurface the notice on the next change.
     */
    const MESSAGE_IDENTITY = 'loqate_unverified_admin_order_create';

    /**
     * Config groups whose enable_create_order_admin / enable_checkout pair this notice covers.
     *
     * Exactly the groups Observer\QuoteSubmitBefore gated on before its early return - address
     * and phone. Email is absent because that observer never verified email on this path, so
     * the early return took nothing away from it.
     */
    const AFFECTED_GROUPS = ['address_settings', 'phone_settings'];

    /** @var Data */
    private $helper;

    /** @var StoreManagerInterface */
    private $storeManager;

    /**
     * @param Data $helper
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Data $helper, StoreManagerInterface $storeManager)
    {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
    }

    /**
     * @return string
     */
    public function getIdentity()
    {
        return self::MESSAGE_IDENTITY;
    }

    /**
     * Show only when at least one group is in the combination that verifies nothing.
     *
     * No API-key check: the notice is about a configuration contradiction, and it is worth
     * correcting whether or not a key is currently installed.
     *
     * @return bool
     */
    public function isDisplayed()
    {
        return $this->affectedGroups() !== [];
    }

    /**
     * @return \Magento\Framework\Phrase|string
     */
    public function getText()
    {
        $groups = $this->affectedGroups();

        return __(
            'Loqate: admin order create is not being verified for %1. Those settings have '
            . '"Enable on Create Order (Admin)" set to No while "Enable on Checkout" is Yes, and '
            . 'admin order create obeys only the admin toggle - so no verification runs there at '
            . 'all. Set "Enable on Create Order (Admin)" to Yes to verify admin-created orders.',
            implode(', ', $groups)
        );
    }

    /**
     * Major: it reports addresses or phone numbers going unverified, which is a correctness
     * problem for the merchant's data, but it is a configuration choice they can act on rather
     * than a fault, so it is not critical.
     *
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * Human-readable names of the groups currently in the unverified combination.
     *
     * EVALUATED PER STORE VIEW, not once at the current scope, and that is not incidental.
     * enable_checkout is showInStore="1" (etc/adminhtml/system.xml) so a merchant can switch
     * it on for one store view over a default of off, while enable_create_order_admin is
     * showInDefault only. Data::getConfigValue() reads SCOPE_STORE with no store, which in the
     * admin area resolves to the DEFAULT store - so a single read would miss exactly the
     * configuration that produces the gap, and the notice would stay silent in the case it
     * exists for. One store view in the combination is one set of orders going unverified, so
     * any store tripping it raises the notice.
     *
     * @return string[]
     */
    private function affectedGroups(): array
    {
        $affected = [];

        foreach (self::AFFECTED_GROUPS as $group) {
            $adminPath = 'loqate_settings/' . $group . '/enable_create_order_admin';
            $checkoutPath = 'loqate_settings/' . $group . '/enable_checkout';

            foreach ($this->storeIds() as $storeId) {
                $adminEnabled = $storeId === null
                    ? $this->helper->getConfigValue($adminPath)
                    : $this->helper->getConfigValueForStore($adminPath, $storeId);
                $checkoutEnabled = $storeId === null
                    ? $this->helper->getConfigValue($checkoutPath)
                    : $this->helper->getConfigValueForStore($checkoutPath, $storeId);

                // Compared as ints because these are Magento yes/no selects, which arrive as
                // the strings '0' and '1' from core_config_data but as ints from a data patch.
                if ((int)$adminEnabled === 0 && (int)$checkoutEnabled === 1) {
                    $affected[] = $group === 'address_settings' ? 'Address Settings' : 'Phone Settings';
                    break;
                }
            }
        }

        return $affected;
    }

    /**
     * Store views to evaluate, or [null] meaning "read at the current scope".
     *
     * Falls back to the current scope when the store list cannot be read. getStores() touches
     * the database, and a system message is rendered on admin pages that must not be brought
     * down by it - including, in the worst case, the very pages an admin would use to fix a
     * broken store table. A missed notice is a far better failure than an unusable admin, and
     * the fallback still catches the default-scope case, which is the common one.
     *
     * @return array<int|null>
     */
    private function storeIds(): array
    {
        try {
            $storeIds = [];
            foreach ($this->storeManager->getStores() as $store) {
                $storeIds[] = (int)$store->getId();
            }
        } catch (\Exception $e) {
            return [null];
        }

        return $storeIds === [] ? [null] : $storeIds;
    }
}
