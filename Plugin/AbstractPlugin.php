<?php

namespace Loqate\ApiIntegration\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Loqate\ApiIntegration\Helper\Validator;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;

/**
 * Base class for the module's ten plugins, and - since LOQ-17149 - the only place in
 * Plugin\ that can reach the customer session at all.
 *
 * WHY $session IS PRIVATE, and why that was the point of the ticket. It used to be
 * `protected`, on the base class of TEN subclasses, which meant every one of them could read
 * or write any session attribute directly. Three of this module's session stores are reached
 * from here - the phone bypass list, the pending email address and the billing-error gate;
 * the email bypass list is reached through Helper\Validator::validateEmail() since LOQ-19188 -
 * and LOQ-17149 put them all behind Helper\ShopperScopedSessionStores so that they are
 * BOUNDED, HASHED where possible, and FLUSHED when the shopper changes. A protected raw
 * session on the base class is a way around every one of those guards, reachable from ten
 * classes, and it would be reachable from any third-party subclass as well. So the raw
 * session is private and the only ways out of this class are the small named accessors below:
 * every session attribute a plugin can reach now goes either through the enrolled, flushed,
 * asserted seam or through a named accessor that explains why it does not.
 *
 * WHAT THAT COSTS AND WHAT IT DOES NOT. Session stays a CONSTRUCTOR PARAMETER, in the same
 * position, so no DI wiring changes (etc/di.xml, etc/adminhtml/di.xml are untouched) and none
 * of the ten subclass constructors - none of which declares one - has to change. The seam is
 * built inline from it, exactly as Helper\Controller and Helper\Validator build theirs.
 * What DOES change is that a third party subclassing this class and using $this->session
 * breaks at compile time; that is recorded in CHANGELOG.md as a developer-facing change, and
 * it is the intended effect rather than a side effect - such a subclass was reaching the
 * stores without the guard.
 *
 * THE TWO NON-MODULE ATTRIBUTES, named so their accessors do not look like an oversight:
 * rememberCustomerFormData() and rememberAddressFormData() write Magento's own
 * 'customer_form_data' / 'address_form_data' through core's typed setters. They are CORE
 * attributes - core writes them on its own validation failures and core reads them back with
 * getCustomerFormData(true) / getAddressFormData(true), which clear them. They are not verify
 * bypasses, this module is not their only writer, and flushing an attribute whose lifetime core
 * manages would be this module deciding core's business, so they are not enrolled in
 * ShopperScopedSessionStores' flush and are documented here rather than there. That exclusion is
 * only sound while every caller is on a path where core really does read the attribute back,
 * which is why LOQ-17149 removed the one caller that was not: Plugin\Admin\OrderSave called
 * rememberCustomerFormData() with the whole admin order-create POST, and nothing in adminhtml
 * reads that attribute off the customer session at all - see the comment at that call site for
 * the enumeration.
 */
abstract class AbstractPlugin
{
    /** @var MessageManagerInterface */
    protected $messageManager;

    /** @var UrlInterface */
    protected $urlBuilder;

    /** @var RedirectFactory */
    protected $resultRedirectFactory;

    /** @var RedirectInterface */
    protected $redirect;

    /**
     * @var Session Raw customer session. PRIVATE since LOQ-17149 - see the class docblock -
     *      and used by nothing but the two form-data accessors, which write CORE attributes
     *      through core's own typed setters and therefore cannot go through the seam.
     */
    private Session $session;

    /**
     * @var ShopperScopedSessionStores The three session stores this class family owns, behind
     *      the shopper-ownership guard. Private for the same reason $session is: a protected
     *      seam would let any of the ten subclasses reach any of the eight enrolled stores,
     *      which is wider than any of them needs and wider than the named accessors below.
     */
    private ShopperScopedSessionStores $sessionStores;

    /** @var Validator */
    protected $validator;

    /** @var Data */
    protected $helper;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /**
     * AbstractPlugin constructor
     *
     * @param Context $context
     * @param UrlInterface $urlBuilder
     * @param Session $session Wrapped in a ShopperScopedSessionStores; the raw object is kept
     *                         private and only the form-data accessors touch it.
     * @param Validator $validator
     * @param Data $helper
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        UrlInterface $urlBuilder,
        Session $session,
        Validator $validator,
        Data $helper,
        JsonFactory $resultJsonFactory
    ) {
        $this->messageManager = $context->getMessageManager();
        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->redirect = $context->getRedirect();
        $this->urlBuilder = $urlBuilder;
        $this->session = $session;
        $this->validator = $validator;
        $this->helper = $helper;
        $this->resultJsonFactory = $resultJsonFactory;
        // $helper is passed on so contactDigest() can namespace by store view; see
        // ShopperScopedSessionStores::resolveStoreScope() for why the argument is optional
        // there and what happens when it is absent.
        $this->sessionStores = new ShopperScopedSessionStores($session, $helper);
    }

    /**
     * Has this session already been warned about this email address or phone number - and,
     * if not, record it so the next submission is accepted.
     *
     * @see ShopperScopedSessionStores::shouldVerify() for the reasoning; moved there so
     *      Helper\Validator::validateEmail() can apply the same gate outside a plugin.
     * @param string $field ShopperScopedSessionStores::VERIFIED_EMAIL_SESSION_KEY or
     *                      ::VERIFIED_PHONE_SESSION_KEY.
     * @param mixed $value The email address or phone number as it arrived from the request.
     * @return bool True when the caller must perform the billable verify.
     */
    protected function shouldVerify($field, $value)
    {
        return $this->sessionStores->shouldVerify($field, $value);
    }

    /**
     * The email address a guest typed into checkout and that has not been verified yet.
     *
     * Written by Plugin\Frontend\AccountManagement one request earlier; the reasoning about
     * the store's lifetime, about why it is the one contact store that must stay RAW, and
     * about what a missing flush does to the next shopper lives on
     * ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY.
     *
     * @return mixed The address, or null/'' when there is nothing pending. The caller tests
     *               it for truthiness, so a flushed null and a cleared '' behave identically.
     */
    protected function pendingEmailAddress()
    {
        return $this->sessionStores->getData(ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY);
    }

    /**
     * Remember an email address for the next request to verify.
     *
     * @see ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY
     * @param mixed $email
     * @return void
     */
    protected function rememberPendingEmailAddress($email): void
    {
        $this->sessionStores->setData(ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY, $email);
    }

    /**
     * Forget the pending email address, because it has now been verified.
     *
     * Writes '' rather than null, which is what this store's only clearer did before
     * LOQ-17149; both are falsy to pendingEmailAddress()'s caller, and keeping the written
     * value identical means a session that spans the deploy cannot be read differently.
     *
     * @see ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY
     * @return void
     */
    protected function clearPendingEmailAddress(): void
    {
        $this->sessionStores->setData(ShopperScopedSessionStores::PENDING_EMAIL_SESSION_KEY, '');
    }

    /**
     * Record whether the billing address just failed validation, which is what
     * Plugin\Frontend\PlaceOrder and PlaceOrderGuest refuse to place an order on.
     *
     * The consequence of this value outliving its shopper is a DENIAL of checkout rather than
     * a bypass, and it is stated in full on
     * ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY - including why this method being
     * the only clearer is the reason the stale-true case is unrecoverable without the flush.
     *
     * @param bool $hasErrors
     * @return void
     */
    protected function recordBillingAddressErrors(bool $hasErrors): void
    {
        $this->sessionStores->setData(ShopperScopedSessionStores::BILLING_ERRORS_SESSION_KEY, $hasErrors);
    }

    /**
     * Hand a rejected customer POST back to Magento so the form can be re-rendered with the
     * values the shopper typed.
     *
     * NOT one of this module's stores and deliberately NOT enrolled in the shopper flush.
     * 'customer_form_data' is a CORE attribute reached through core's own typed setter:
     * Magento writes it from its own validation failures and consumes it with
     * getCustomerFormData(true), which clears it on read. It grants no verify bypass, this
     * module is not its only writer, and flushing an attribute whose lifetime core manages
     * would be this module overriding core's behaviour. It does hold the submitted POST, so
     * it holds PII for as long as core leaves it there - identical to what core itself does
     * on a failed registration, and out of scope here.
     *
     * THAT ARGUMENT IS ABOUT THE CALLERS, NOT ABOUT THE ATTRIBUTE, and LOQ-17149 had to act on
     * the difference. "Core clears it on read" is only true where core reads it, and every
     * remaining caller is such a path: Plugin\Frontend\CustomerAccountCreate and
     * CustomerAccountEdit redirect to a page core renders from this value with
     * getCustomerFormData(true). Plugin\Admin\OrderSave was the exception - nothing in adminhtml
     * reads 'customer_form_data' off the CUSTOMER session, so there the POST was never read and
     * never cleared - and that call was removed rather than documented. Do not add an adminhtml
     * caller back without re-checking that enumeration; it is written out at the old call site.
     *
     * This is the only reason AbstractPlugin still holds the raw session at all.
     *
     * @param mixed $formData The POST as the plugin received it.
     * @return void
     */
    protected function rememberCustomerFormData($formData): void
    {
        $this->session->setCustomerFormData($formData);
    }

    /**
     * The address-form counterpart of rememberCustomerFormData(), with the same reasoning:
     * 'address_form_data' is core's attribute, cleared by core on read.
     *
     * @param mixed $formData The POST as the plugin received it.
     * @return void
     */
    protected function rememberAddressFormData($formData): void
    {
        $this->session->setAddressFormData($formData);
    }

    /**
     * Validate email address
     *
     * @param $email
     * @return false|Phrase
     */
    protected function validateEmail($email)
    {
        return $this->validator->validateEmail($email);
    }

    /**
     * Validate phone number
     *
     * @param $phone
     * @return false|Phrase
     */
    protected function validatePhone($phone, $country = null)
    {
        $errorMessage = __('The provided phone number is invalid.');
        if (!$this->helper->getConfigValue('loqate_settings/phone_settings/prevent_submit')) {
            if (!$this->shouldVerify(ShopperScopedSessionStores::VERIFIED_PHONE_SESSION_KEY, $phone)) {
                return false;
            }
            $errorMessage = __('Invalid phone number. Submit again to use this phone number.');
        }

        $response = $this->validator->verifyPhoneNumber($phone, $country);

        if (isset($response['error'])) {
            return __('An unexpected error occurred while trying to validate your phone number.');
        }

        if (!$response) {
            return $errorMessage;
        }

        if (isset($response['noKeyFound'])) {
            return false;
        }

        return false;
    }
}
