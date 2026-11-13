<?php

namespace Loqate\ApiIntegration\Observer;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates address and phone on quote submit, covering both Luma REST and Hyvä GraphQL checkout paths,
 * and the guest email in the frontend area (Hyvä Checkout), where nothing else checks it.
 * The sales_model_service_quote_submit_before event fires regardless of the checkout front-end used.
 */
class QuoteSubmitBefore implements ObserverInterface
{
    /** @var Data */
    private $helper;

    /** @var Validator */
    private $validator;

    /** @var Logger */
    private $logger;

    /** @var State */
    private $appState;

    /**
     * @param Data $helper
     * @param Validator $validator
     * @param Logger $logger
     * @param State $appState Appended, never reordered: the existing three positions are
     *                        wired positionally by every di.xml/test that builds this class.
     */
    public function __construct(
        Data $helper,
        Validator $validator,
        Logger $logger,
        State $appState
    ) {
        $this->helper  = $helper;
        $this->validator = $validator;
        $this->logger  = $logger;
        $this->appState = $appState;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        // Admin order create is verified by Plugin\Admin\OrderSave, which sends BOTH
        // addresses in ONE batch call (verifyMultipleAddresses()). This observer would then
        // add up to two further single-address calls for the same order, and the two paths
        // cannot share a verdict - one judges the AVC, the other the AQI - so the duplicate
        // billing can only be removed by not running here. That also means admin order create
        // is no longer AVC-checked at all and obeys only the *_create_order_admin toggles -
        // see isAdminArea() for what that changes and the one configuration it leaves unverified.
        // Resolved once, so an unresolvable area is logged at most once per submit.
        $areaCode = $this->areaCode();
        if ($this->isAdminArea($areaCode)) {
            return;
        }

        if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
            return;
        }

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getQuote();
        if (!$quote) {
            return;
        }

        $errors = [];

        // --- Shipping address validation ---
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && !$quote->getIsVirtual()) {
            $addressData = $shippingAddress->getData();

            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                $telephone = $shippingAddress->getTelephone();
                $countryId = $shippingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($telephone, $countryId);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
        }

        // --- Billing address validation ---
        $billingAddress = $quote->getBillingAddress();
        if ($billingAddress) {
            $addressData = $billingAddress->getData();

            if ($this->helper->getConfigValue('loqate_settings/address_settings/enable_checkout')) {
                $response = $this->validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            if ($this->helper->getConfigValue('loqate_settings/phone_settings/enable_checkout')) {
                $telephone = $billingAddress->getTelephone();
                $countryId = $billingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($telephone, $countryId);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
        }

        // --- Customer email validation ---
        // Guests only, as on Luma, and only in 'frontend' (Hyvä Checkout's Magewire place order and
        // Luma's frontend review-page place orders). webapi_rest is covered by
        // CheckoutShippingInformation except virtual/downloadable-only carts (see CHANGELOG Known
        // limitations); graphql, CLI and unresolvable areas often keep no session,
        // so with Prevent Submit = No the guest would be warned on every submit. See CHANGELOG.md.
        // shortcut: area allowlist until LOQ-20299 (email verdict cache) and LOQ-20300 (quote-scoped warn-once for GraphQL).
        // customer_is_guest OR no customer id, because not every checkout sets the flag before this event.
        if ($areaCode === Area::AREA_FRONTEND
            && $this->helper->getConfigValue('loqate_settings/email_settings/enable_checkout')
            && ($quote->getCustomerIsGuest() || !$quote->getCustomerId())
        ) {
            $email = $quote->getCustomerEmail() ?: ($billingAddress ? $billingAddress->getEmail() : null);
            if ($email) {
                // false: an email API outage must not block the order, as validatePhone() below.
                $errorMessage = $this->validator->validateEmail($email, false);
                if ($errorMessage) {
                    $errors[] = $errorMessage;
                }
            }
        }

        if ($errors) {
            throw new LocalizedException(__(implode(PHP_EOL, $errors)));
        }
    }

    /**
     * Is this request being handled in the admin area?
     *
     * WHY THIS IS A RUNTIME CHECK AND NOT etc/frontend/events.xml. Moving the registration
     * into etc/frontend/ would look tidier and would be WRONG: this observer exists
     * precisely because Hyvä's GraphQL checkout does not use the REST
     * ShippingInformationManagement / BillingAddressManagement services the other plugins
     * intercept (see the comment in etc/events.xml), and 'graphql' and 'webapi_rest' are
     * separate area codes that do NOT inherit 'frontend'. A frontend-only registration
     * would therefore silently disable verification on the exact path this observer was
     * built for. The global registration is deliberate; only adminhtml is excluded, and
     * only here.
     *
     * WHAT THIS CHANGED FOR ADMIN ORDER CREATE, stated because it is a behaviour change and
     * not a refactor. Before this early return, an admin-created order had to pass BOTH
     * checks: the AQI, through Plugin\Admin\OrderSave (verifyMultipleAddresses()), AND the
     * AVC, through this observer. It now passes the AQI only. From here on, address, phone
     * and email verification of admin order create is governed SOLELY by the
     * loqate_settings/*_settings/enable_create_order_admin toggles, and the AVC check no
     * longer applies on that path at all.
     *
     * The sharp case: with loqate_settings/address_settings/enable_create_order_admin OFF and
     * loqate_settings/address_settings/enable_checkout ON, this observer used to be the ONLY
     * thing verifying an admin-created order's addresses - and now NOTHING does. The same
     * holds for the matching pair of phone toggles. The blanket early return is nevertheless
     * kept deliberately: "enable create order admin = No" is the merchant asking for admin
     * order create not to be verified, and honouring it through one setting is the intended
     * reading of those toggles, whereas the previous behaviour made an admin-side feature
     * switch silently overridable by a checkout-side one.
     *
     * That case is NOT documented only here, because a merchant does not read docblocks and
     * they are the only person who can act on it. Model\AdminNotification\
     * UnverifiedAdminOrderMessage raises an admin system message naming the affected settings
     * whenever it detects the combination, and CHANGELOG.md records it as an action-required
     * behaviour change. If the toggles or this early return are reworked, those two have to
     * move with it or the warning becomes a lie.
     *
     * HOW IT FAILS: State::getAreaCode() throws when no area code has been set yet, and
     * this method then answers "not admin", so verification RUNS. That direction is chosen
     * because the two failure modes are not symmetric: skipping verification on an unknown
     * area would silently let unverified addresses through checkout (a correctness and
     * compliance failure that no one would notice), whereas running it on an unknown area
     * costs at worst a duplicate billable call - visible on the invoice, and never a
     * blocked order or a broken checkout. The throwable is swallowed and logged rather
     * than propagated for the same reason: an exception out of this observer aborts the
     * order submission.
     *
     * The catch is \Throwable rather than just LocalizedException on purpose, and it is the
     * paragraph above that demands it: State is an interceptable @api class, so a plugin, a
     * broken DI compilation or a not-yet-initialised object manager can raise something that
     * is not a LocalizedException, and with a narrower catch that would propagate out of a
     * sales_model_service_quote_submit_before observer and KILL THE ORDER - a failure mode
     * that did not exist before this class took a State dependency. Real Magento only
     * documents LocalizedException here, so the wider catch costs nothing in practice; what
     * it buys is that resolving the area can never be the reason an order fails. Diagnosis is
     * preserved instead of traded away: the throwable is logged with its message, identically
     * for every type.
     *
     * @param string|null $areaCode From areaCode(); null when the area could not be resolved.
     * @return bool
     */
    private function isAdminArea(?string $areaCode): bool
    {
        return $areaCode === Area::AREA_ADMINHTML;
    }

    /**
     * The current area code, or null when it cannot be resolved - which every caller treats
     * as "verify". See isAdminArea() for why that direction, and why any throwable is caught.
     *
     * @return string|null
     */
    private function areaCode(): ?string
    {
        try {
            return $this->appState->getAreaCode();
        } catch (\Throwable $exception) {
            $this->logger->info(
                'Loqate could not resolve the application area on quote submit; '
                . 'verifying anyway: ' . $exception->getMessage()
            );

            return null;
        }
    }

    /**
     * @param string $phone
     * @param string|null $country
     * @return \Magento\Framework\Phrase|false
     */
    private function validatePhone(string $phone, ?string $country)
    {
        $response = $this->validator->verifyPhoneNumber($phone, $country);

        if (isset($response['error']) || isset($response['noKeyFound'])) {
            return false;
        }

        if (!$response) {
            return __('The provided phone number is invalid.');
        }

        return false;
    }
}
