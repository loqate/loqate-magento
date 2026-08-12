<?php

namespace Loqate\ApiIntegration\Plugin\Frontend;

use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Magento\Checkout\Model\PaymentInformationManagement;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Class PlaceOrder
 *
 * Does NOT extend Plugin\AbstractPlugin - it needs no validator, no helper and no message
 * manager - so it builds its own ShopperScopedSessionStores from the injected Session, the
 * same inline pattern Helper\Controller, Helper\Validator and AbstractPlugin use. The point of
 * routing it through the seam (LOQ-17149) rather than reading the session directly is that
 * this class is the READER of the billing-error gate: if the gate is not flushed when the
 * shopper changes, this is the line that refuses the next shopper their order.
 */
class PlaceOrder
{
    /**
     * @var ShopperScopedSessionStores The billing-error gate, behind the shopper-ownership
     *      guard. The raw Session is deliberately not kept as well: keeping it would leave a
     *      way to read the gate without the flush, which is the whole defect.
     */
    private ShopperScopedSessionStores $sessionStores;

    /**
     * PlaceOrder constructor
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
     * THIS RUNS BEFORE THE ONLY THING THAT CAN CLEAR THE GATE. The gate is written solely by
     * Plugin\Frontend\CheckoutBillingAddress::aroundAssign(), i.e. on
     * BillingAddressManagement::assign(); this is a BEFORE plugin on
     * savePaymentInformationAndPlaceOrder(), which assigns the billing address further down
     * the same call. So on a flow that submits the billing address with the place-order call,
     * a truthy gate throws here every time and never reaches the code that would clear it.
     * That is a checkout DENIAL, not a bypass - and it is exactly why the gate has to be
     * flushed when the shopper changes rather than merely overwritten: an inherited true is
     * otherwise permanent, with a message naming an error this shopper never saw. Flushing
     * writes null, which is falsy here, so the post-flush state is "not blocked" - the same as
     * a fresh session. See Helper\ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY.
     *
     * @throws CouldNotSaveException
     */
    public function beforeSavePaymentInformationAndPlaceOrder(
        PaymentInformationManagement $subject,
        $cartId,
        $paymentMethod,
        $billingAddress = null
    ) {
        if ($this->sessionStores->getData(ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY)) {
            throw new CouldNotSaveException(__('Please check the error again before continuing.'));
        }

        return [$cartId, $paymentMethod, $billingAddress];
    }
}
