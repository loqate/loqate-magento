<?php

namespace Loqate\ApiIntegration\Plugin\Frontend;

use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Magento\Checkout\Model\GuestPaymentInformationManagement;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Class PlaceOrderGuest
 *
 * The guest counterpart of Plugin\Frontend\PlaceOrder, and the same reasoning applies to it
 * unchanged - including the flush, which matters HERE too even though the caller is a guest:
 * the gate is inherited across the guest -> logged-in and logged-in -> guest transitions in
 * BOTH directions, and this is the reader that fires on the guest side of them.
 */
class PlaceOrderGuest
{
    /**
     * @var ShopperScopedSessionStores The billing-error gate, behind the shopper-ownership
     *      guard. The raw Session is deliberately not kept as well.
     */
    private ShopperScopedSessionStores $sessionStores;

    /**
     * PlaceOrderGuest constructor
     *
     * @param Session $session Wrapped in a ShopperScopedSessionStores and not kept raw. Still
     *                         a required Session parameter in the same position, so no DI
     *                         wiring changes.
     */
    public function __construct(Session $session)
    {
        $this->sessionStores = new ShopperScopedSessionStores($session);
    }

    /**
     * Because the billing address is resubmitted at place order, check again if the customer solved the errors if any
     *
     * @see Plugin\Frontend\PlaceOrder::beforeSavePaymentInformationAndPlaceOrder() for why a
     *      stale gate is an unrecoverable checkout denial rather than a bypass, and why the
     *      flush is what fixes it.
     * @throws CouldNotSaveException
     */
    public function beforeSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagement $subject,
        $cartId,
        $email,
        $paymentMethod,
        $billingAddress = null
    ) {
        if ($this->sessionStores->getData(ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY)) {
            throw new CouldNotSaveException(__('Please check the error again before continuing.'));
        }

        return [$cartId, $email, $paymentMethod, $billingAddress];
    }
}
