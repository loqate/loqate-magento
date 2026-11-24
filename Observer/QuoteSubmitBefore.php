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
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;

/**
 * Validates address and phone on quote submit, covering both Luma REST and Hyvä GraphQL checkout paths,
 * and the guest email in the frontend area (Hyvä Checkout), on REST place orders that carry
 * the storefront session cookie and, with Prevent Submit = Yes, on every GraphQL and REST place order.
 * The sales_model_service_quote_submit_before event fires regardless of the checkout front-end used.
 *
 * Settings, the API key and the Validator are those of the QUOTE's store view (LOQ-20327): a REST or
 * GraphQL place order without a store code runs in the default store view, so the current store is
 * not the cart's. This observer and the Validator must read the same prevent_submit, or a
 * session-less order would get a warn-once it cannot remember (LOQ-20301).
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

    /** @var SessionConfig */
    private $sessionConfig;

    /** @var CookieReaderInterface */
    private $cookieReader;

    /**
     * @param Data $helper
     * @param Validator $validator
     * @param Logger $logger
     * @param State $appState Appended, never reordered: the existing three positions are
     *                        wired positionally by every di.xml/test that builds this class.
     * @param SessionConfig $sessionConfig Appended for the same reason; names the session cookie.
     * @param CookieReaderInterface $cookieReader Appended for the same reason.
     */
    public function __construct(
        Data $helper,
        Validator $validator,
        Logger $logger,
        State $appState,
        SessionConfig $sessionConfig,
        CookieReaderInterface $cookieReader
    ) {
        $this->helper  = $helper;
        $this->validator = $validator;
        $this->logger  = $logger;
        $this->appState = $appState;
        $this->sessionConfig = $sessionConfig;
        $this->cookieReader = $cookieReader;
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

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getQuote();
        if (!$quote) {
            return;
        }

        // The quote's store, not the current one: see the class docblock (LOQ-20327).
        $storeId = (int)$quote->getStoreId();
        if (empty($this->helper->getConfigValueForStore('loqate_settings/settings/api_key', $storeId))) {
            return;
        }
        // A local copy, never assigned to $this: the injected Validator is a shared instance.
        $validator = $this->validator->forStore($storeId);

        $errors = [];

        // --- Shipping address validation ---
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && !$quote->getIsVirtual()) {
            $addressData = $shippingAddress->getData();

            if ($this->helper->getConfigValueForStore('loqate_settings/address_settings/enable_checkout', $storeId)) {
                $response = $validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            // !$errors: once the order is rejected the phone verdict can't change it, so don't bill the
            // check (as for email below); the shopper sees the phone error on the next attempt (LOQ-20326).
            if (!$errors
                && $this->helper->getConfigValueForStore('loqate_settings/phone_settings/enable_checkout', $storeId)
            ) {
                $telephone = $shippingAddress->getTelephone();
                $countryId = $shippingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($validator, $telephone, $countryId);
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

            if ($this->helper->getConfigValueForStore('loqate_settings/address_settings/enable_checkout', $storeId)) {
                $response = $validator->verifyAddress($addressData);
                if (!empty($response['error'])) {
                    $errors[] = $response['message'];
                }
            }

            // !$errors: once the order is rejected the phone verdict can't change it, so don't bill the
            // check (as for email below); the shopper sees the phone error on the next attempt (LOQ-20326).
            if (!$errors
                && $this->helper->getConfigValueForStore('loqate_settings/phone_settings/enable_checkout', $storeId)
            ) {
                $telephone = $billingAddress->getTelephone();
                $countryId = $billingAddress->getCountryId();
                if ($telephone) {
                    $errorMessage = $this->validatePhone($validator, $telephone, $countryId);
                    if ($errorMessage) {
                        $errors[] = $errorMessage;
                    }
                }
            }
        }

        // --- Customer email validation ---
        // Guests only, where a session persists: 'frontend' (Hyvä Checkout, Luma review-page orders)
        // and webapi_rest with the storefront session cookie (Luma's REST place order, incl. virtual
        // carts); a pass from Luma's shipping step is reused, not re-billed (LOQ-20299). Without a
        // session (cookie-less REST, graphql, CLI, unresolvable area) Prevent Submit = No would warn
        // on every submit, so graphql and cookie-less REST are checked only with Prevent Submit = Yes,
        // where an invalid email simply blocks and the pass cache just misses (LOQ-20300).
        // shortcut: graphql and cookie-less REST with Prevent Submit = No are not checked; add them
        // once a session-free warn-once exists (LOQ-20301).
        // customer_is_guest OR no customer id, because not every checkout sets the flag before this event.
        $hasSession = $areaCode === Area::AREA_FRONTEND
            || ($areaCode === Area::AREA_WEBAPI_REST
                && $this->cookieReader->getCookie($this->sessionConfig->getName()));
        $blocksWithoutSession = in_array($areaCode, [Area::AREA_GRAPHQL, Area::AREA_WEBAPI_REST], true)
            && $this->helper->getConfigValueForStore('loqate_settings/email_settings/prevent_submit', $storeId);
        // !$errors: a rejected order can't be changed by the email verdict, so don't bill the check;
        // this also closes the forced-address-failure probe.
        // shortcut: a deliverable address plus a probe email still returns the email verdict for one paid call,
        // as address and phone checks do; add reCAPTCHA/rate limiting on place order to close it (LOQ-20315).
        if (!$errors
            && ($hasSession || $blocksWithoutSession)
            && $this->helper->getConfigValueForStore('loqate_settings/email_settings/enable_checkout', $storeId)
            && ($quote->getCustomerIsGuest() || !$quote->getCustomerId())
        ) {
            $email = $quote->getCustomerEmail() ?: ($billingAddress ? $billingAddress->getEmail() : null);
            if ($email) {
                // false: an email API outage must not block the order, as validatePhone() below.
                $errorMessage = $validator->validateEmail($email, blockOnApiError: false, usePassCache: true);
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
     * The current area code, or null when it cannot be resolved. The admin guard treats null as
     * "verify" (see isAdminArea() for why, and why any throwable is caught); the email check
     * treats it as "do not verify", since such a request keeps no session.
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
     * @param Validator $validator The Validator pinned to the quote's store view (LOQ-20327).
     * @param string $phone
     * @param string|null $country
     * @return \Magento\Framework\Phrase|false
     */
    private function validatePhone(Validator $validator, string $phone, ?string $country)
    {
        $response = $validator->verifyPhoneNumber($phone, $country);

        if (isset($response['error']) || isset($response['noKeyFound'])) {
            return false;
        }

        if (!$response) {
            return __('The provided phone number is invalid.');
        }

        return false;
    }
}
