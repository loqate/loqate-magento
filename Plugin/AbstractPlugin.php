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
 * or write any session attribute directly. Four of this module's session stores are reached
 * from here - the two contact bypass lists, the pending email address and the billing-error
 * gate - and LOQ-17149 put all four behind Helper\ShopperScopedSessionStores so that they are
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
 * getCustomerFormData(true), which clears them - so they are not enrolled in this module's
 * flush; see the closing paragraph of ShopperScopedSessionStores' class docblock.
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
     * @var ShopperScopedSessionStores The four session stores this class family owns, behind
     *      the shopper-ownership guard. Private for the same reason $session is: a protected
     *      seam would let any of the ten subclasses reach any of the seven enrolled stores,
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
     * WHAT THIS ACTUALLY GATES. It is consulted only when the matching prevent_submit toggle
     * is OFF, which is the shipped default (etc/config.xml), and in that mode the module warns
     * once and accepts the value on resubmission. So answering false does not merely SAVE a
     * billable verifyEmail()/verifyPhoneNumber() - it accepts the value with no check and no
     * warning at all. That is why the two stores behind it are enrolled in the shopper flush
     * and not merely bounded: inheriting shopper A's list means B's first submission of that
     * value sails through unverified and unwarned.
     *
     * THREE PROPERTIES LOQ-17149 ADDED, each argued where it is enforced:
     *  - IDENTITY-SCOPED. Both stores are reached through ShopperScopedSessionStores, so they
     *    are flushed when the logged-in customer changes in either direction;
     *  - BOUNDED to ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT entries, FIFO, oldest
     *    first, mirroring Helper\Controller::storeCapturedAddress() and
     *    Validator::storeVerifyResult(). They were unbounded append-only lists for the whole
     *    session before;
     *  - HASHED. What is stored is a salted HMAC of the value, not the value; see
     *    ShopperScopedSessionStores::contactDigest() for the full-length/salt/equivalence
     *    reasoning and for the numeric-string phone collision this deliberately fixes.
     *
     * WHY A HIT WRITES NOTHING. A match returns immediately without moving the entry to the
     * newest position, unlike Controller::storeCapturedAddress()'s de-duplication. Refreshing
     * it would mean a session write on the hot path for a value that is already remembered,
     * and the cost of not refreshing is bounded and safe: an entry can age out while the
     * shopper is still using it, which costs one extra billable verify and one extra warning,
     * never a bypass. De-duplication on WRITE is unnecessary for the same reason it is
     * necessary there - a write only ever follows a miss, and a miss means the digest is not
     * in the list, so appending cannot create a duplicate.
     *
     * @param string $field ShopperScopedSessionStores::VERIFIED_EMAIL_SESSION_KEY or
     *                      ::VERIFIED_PHONE_SESSION_KEY. Kept as a parameter rather than
     *                      split into two methods because validateEmail() and validatePhone()
     *                      differ in nothing else, and the seam asserts the value is a store
     *                      it flushes.
     * @param mixed $value The email address or phone number as it arrived from the request.
     * @return bool True when the caller must perform the billable verify.
     */
    protected function shouldVerify($field, $value)
    {
        $digest = $this->sessionStores->contactDigest($field, $value);
        if ($digest === '') {
            // No comparable digest could be produced - a non-scalar value, or no CSPRNG for
            // the salt. Verify, and store nothing: the fail-closed direction, at the price of
            // a billable call. See ShopperScopedSessionStores::contactDigest().
            return true;
        }

        $storedData = $this->sessionStores->getData($field);
        if (!is_array($storedData)) {
            // Covers the empty session, a store just flushed to null by the ownership guard,
            // and - defensively - an attribute another module or a truncated payload left in
            // a shape this list cannot be appended to.
            $storedData = [];
        }

        // Drop everything that is not one of this class's digests, and re-index so the FIFO
        // eviction below and the [] append operate on a list. Two things are dropped: a RAW
        // email address or phone number written by a pre-LOQ-17149 release into a session
        // that was live at deploy time, and anything foreign. Both are inert - neither can
        // ever equal a digest - so no verdict changes; what this buys is that the last raw
        // contact details leave the session at the first write rather than waiting for
        // eviction or a shopper change. It also means such a session costs one extra billable
        // verify per value at deploy time, which is recorded in CHANGELOG.md.
        $storedData = array_values(array_filter(
            $storedData,
            fn ($entry): bool => $this->sessionStores->isContactDigest($entry)
        ));

        // STRICT, and it is worth a sentence because a LOOSE in_array() on this very line is
        // what LOQ-17149 replaced. Both needle and haystack are now 64-character hex strings,
        // so the two comparisons differ in only one family of values: a digest of the form '0e'
        // followed by 62 digits is a numeric string in scientific notation, and two of those
        // loose-compare EQUAL (both == 0), which would let one stored digest answer for a
        // different address. That is of the order of one pair in 10^15, and it is therefore NOT
        // pinned by a test - a real HMAC output cannot be forced into that shape from a test,
        // so removing the flag leaves the suite green - which is exactly why it is stated here.
        // The flag costs nothing and the direction it protects is the bypass one.
        if (in_array($digest, $storedData, true)) {
            return false;
        }

        // FIFO eviction, mirroring the address stores. The keys are integers, so array_shift()
        // renumbers them, which is what a list wants. The $storedData !== [] guard keeps the
        // loop terminating even if the limit is ever set to 0 or below, where shifting an
        // already-empty array would otherwise spin forever inside a checkout request.
        while ($storedData !== [] && count($storedData) >= ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT) {
            array_shift($storedData);
        }

        $storedData[] = $digest;
        $this->sessionStores->setData($field, $storedData);

        return true;
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
        $errorMessage = __('The provided email address is invalid.');
        if (!$this->helper->getConfigValue('loqate_settings/email_settings/prevent_submit')) {
            if (!$this->shouldVerify(ShopperScopedSessionStores::VERIFIED_EMAIL_SESSION_KEY, $email)) {
                return false;
            }
            $errorMessage = __('Invalid email address. Submit again to use this email address.');
        }

        $response = $this->validator->verifyEmail($email);

        if (isset($response['error'])) {
            return __('An unexpected error occurred while trying to validate your email address.');
        }

        if (!$response) {
            return $errorMessage;
        }

        if (isset($response['noKeyFound'])) {
            return false;
        }

        return false;
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
