<?php

namespace Loqate\ApiIntegration\Helper;

use Magento\Customer\Model\Session;

/**
 * The single gateway to every store this module keeps in the customer session, and the one
 * place that enforces those stores belong to ONE shopper (LOQ-16978, LOQ-17149).
 *
 * WHY THIS EXISTS AT ALL. Seven session attributes decide whether a shopper's submission is
 * checked against Loqate at all, and every one of them is therefore a licence to SKIP a
 * verification:
 *  - self::CAPTURED_ADDRESSES_SESSION_KEY (the Capture bypass),
 *    self::VERIFY_CACHE_SESSION_KEY and self::BATCH_VERIFY_CACHE_SESSION_KEY (the
 *    LOQ-16969/LOQ-16976 address verdict caches);
 *  - self::VERIFIED_EMAIL_SESSION_KEY and self::VERIFIED_PHONE_SESSION_KEY (the
 *    email/phone bypass lists, LOQ-17149);
 *  - self::PENDING_EMAIL_SESSION_KEY (one email address awaiting a billable verify);
 *  - self::BILLING_ERRORS_SESSION_KEY (the gate that refuses to place an order).
 * A PHP session outlives a login: Magento calls session_regenerate_id() on login and on
 * logout, and that changes the session ID while PRESERVING every value in $_SESSION. So on
 * a shared browser - a family device, a public terminal, a click-and-collect kiosk - shopper
 * B could inherit shopper A's bypasses and check out data that was never verified against
 * B's own submission, and could inherit A's raw email address with them. Nothing in Magento
 * clears third-party session attributes on an identity change, so this class does it for
 * every attribute in self::SHOPPER_SCOPED_SESSION_KEYS.
 *
 * WHY THE NAME IS "SESSION STORES" AND NOT "ADDRESS STORES" ANY MORE, and why the broad name
 * that over-promised once does not over-promise now. This class began as ShopperScopedSession
 * and the LOQ-16978 review renamed it to ShopperScopedAddressStores precisely BECAUSE that
 * broad name lied: it guarded THREE of the module's SEVEN shopper-scoped session attributes,
 * all three of them address stores, while its name claimed the session. The name promised
 * coverage the class did not have, and a 120-line docblock walking that back is not a fix -
 * the name is what a reader sees at the call site. LOQ-17149 enrols the four siblings, so the
 * ratio is now SEVEN of SEVEN. That is the whole of the argument: the name is not a promise
 * about coverage that has to be qualified any more, it is a description of a complete set.
 * "Address stores" would be the new lie, because four of the seven hold no address.
 *
 * The module's ONE remaining session attribute of its own, self::IP_COUNTRY_SESSION_KEY, is
 * not a shopper's data at all - it is derived from the request IP (see getIpCountry()) - so it
 * is not part of that denominator, and it is reachable HERE, named and excluded with its
 * reason, rather than left as an unexplained direct session access in two other classes. That
 * is what makes this file the complete map of the module's session usage instead of a list with
 * a silent omission, and it is the second half of why the name is honest: nothing is missing
 * from it that a reader would expect to find.
 *
 * The rename is safe because the old name never shipped: neither ShopperScopedSession nor
 * ShopperScopedAddressStores exists in any tag up to and including v2.0.17 (the latest at the
 * time of writing), the class is never placed in DI, and every holder keeps it in a private
 * property - so no third party can be resolving it by name and no BC alias is kept. The
 * attribute VALUES are untouched by the rename, and Controller::CAPTURED_ADDRESSES_SESSION_KEY,
 * Validator::VERIFY_CACHE_SESSION_KEY and Validator::BATCH_VERIFY_CACHE_SESSION_KEY are
 * still ALIASES of the constants below, so no live session loses a store at deploy time.
 *
 * HOW. Ownership is recorded in a sibling attribute (self::SESSION_OWNER_KEY) holding the
 * customer id the stores belong to, with 0 (self::GUEST_OWNER_ID) standing for "not logged
 * in". Every read and every write checks the live identity against that marker first, so
 * BOTH transitions are covered - logged-in -> guest (logout) and guest -> logged-in
 * (login), as well as A -> B where one login immediately follows another. The check is
 * lazy rather than event-driven on purpose: an observer on customer_login/customer_logout
 * would have to be registered, would miss any path that swaps the identity without firing
 * those events (an admin "login as customer" hand-off, a session restored from a
 * persistent-cart cookie), and would leave the invariant enforced somewhere other than
 * where the data is read. Here it cannot be bypassed, because the raw session is private
 * to this class and no holder keeps a reference to it: Controller and Validator do not, and
 * Plugin\AbstractPlugin's own Session became private in LOQ-17149 for the same reason - it
 * was `protected` on the base class of TEN plugins, which is a raw session reachable from
 * any of them, and therefore a way around every guard in this file.
 *
 * WHY A COLLABORATOR RATHER THAN A COPY IN EACH CLASS, and why ONE collaborator rather than
 * one per family of stores. The flush has to be identical in all seven stores or it is
 * worthless - flushing six of them still leaves the seventh granting B a bypass earned by A
 * - and it is the kind of rule that rots when duplicated. That argument is also why
 * LOQ-17149 extended this class instead of adding a sibling seam for the contact stores:
 * two seams over one session would need either duplicated ownership logic or two owner
 * markers, and two markers can drift, at which point the flush is no longer ONE atomic
 * decision about who the session belongs to. It is constructed in the constructors of
 * Helper\Controller, Helper\Validator, Plugin\AbstractPlugin, Plugin\Frontend\PlaceOrder,
 * Plugin\Frontend\PlaceOrderGuest, Plugin\ChangeAddressDefaultCountry and
 * Plugin\ChangeCheckoutDefaultCountry from the customer Session those classes are already
 * given, rather than injected, so none of them needs a new REQUIRED constructor argument (a
 * break for anything extending them, and AbstractPlugin has ten subclasses) and no DI wiring
 * changes; Controller and Validator already build collaborators inline the same way
 * (new Capture(), new Verify()).
 *
 * WHY THE ATTRIBUTE NAMES LIVE HERE and are only aliased on Controller/Validator
 * (LOQ-16978 review). This class enforces the rule, so this class owns the list of names
 * the rule applies to. Holding them anywhere else made the dependency circular - the flush
 * list pointed at Controller:: and Validator::, while both of those classes construct this
 * one - and put the names in a class that has no way to enforce anything about them.
 * Controller::CAPTURED_ADDRESSES_SESSION_KEY, Validator::VERIFY_CACHE_SESSION_KEY and
 * Validator::BATCH_VERIFY_CACHE_SESSION_KEY are kept as ALIASES of the constants below, so
 * every existing reference to them still resolves - including
 * ShopperScopedSessionStoresTest's assertion on the flush list - and the attribute VALUES are
 * unchanged, so the test files that pin these names as literals keep passing and no live
 * session loses its stores at deploy time. The four names LOQ-17149 added have no aliases
 * anywhere: they were bare string literals at their call sites before this ticket, so there
 * is no older name to keep resolving.
 *
 * ADOPTION, stated so it is not mistaken for a hole - and so its reach is not understated,
 * which an earlier revision of this paragraph did (LOQ-17148 mutation review). When NOTHING
 * has been recorded yet, the current identity ADOPTS whatever is in the SESSION stores
 * instead of flushing them. Two things reach that branch, not one:
 *  - data written BEFORE this class existed. A session that was already live at deploy time,
 *    in which shopper A logged out before the module's first post-release access, hands A's
 *    stores to the guest that follows. Accepted rather than defended against, on three
 *    grounds: it is exactly the pre-change behaviour, so it is not a regression this ticket
 *    introduces; it is bounded to one release and to sessions mid-flight during it; and it
 *    closes the moment ANY identity change is observed after the marker is written. The
 *    alternative - flushing on first access - would buy nothing for any session started
 *    after the deploy and would throw away every live shopper's capture bypass at deploy
 *    time;
 *  - a session storage that is EMPTIED mid-flight, which erases the marker along with the
 *    stores it describes (Magento\Framework\Session\SessionManager::clearStorage(), the
 *    destroy() inside Magento\Customer\Model\Session::logout(), or another module). That is
 *    not bounded to a release: it is reachable at any time and the identity may have changed
 *    in the same breath. Harmless for the seven SESSION stores, which were emptied too -
 *    along with self::CONTACT_DIGEST_SALT_KEY, so no surviving contact digest could be
 *    matched even if one did survive - but NOT for data DERIVED from them and held elsewhere,
 *    which is why adoption opens a new ownership epoch; see enforceOwnership() and
 *    ownershipGeneration().
 *
 * ACCEPTED LIMITS, stated (the style of Validator::verifyMultipleAddresses()):
 *  - THE GUARD SCOPES BY CUSTOMER IDENTITY ONLY, and LOQ-17149 DECIDED to leave it there
 *    rather than inherit the LOQ-16978 reasoning. The marker tracks
 *    Magento\Customer\Model\Session::getCustomerId() and nothing else, so an ADMIN user swap
 *    inside one browser session is not covered. That is not academic for FIVE of the seven
 *    stores, counted rather than estimated:
 *      * self::CAPTURED_ADDRESSES_SESSION_KEY - Controller\Adminhtml\Capture\Retrieve, through
 *        Helper\Controller::retrieve(), which is the same helper method the storefront
 *        controller calls;
 *      * self::VERIFY_CACHE_SESSION_KEY - Plugin\Admin\ValidateAddress, through
 *        Validator::verifyAddress();
 *      * self::BATCH_VERIFY_CACHE_SESSION_KEY - written exclusively from adminhtml
 *        (Plugin\Admin\OrderSave, Plugin\Admin\ValidateImportAddress);
 *      * self::VERIFIED_EMAIL_SESSION_KEY and self::VERIFIED_PHONE_SESSION_KEY - from adminhtml
 *        as well as from the storefront (Plugin\Admin\OrderSave via validateEmail() and
 *        validatePhone(), Plugin\Admin\ValidateCustomer, Plugin\Admin\ValidateAddress).
 *    Only self::PENDING_EMAIL_SESSION_KEY and self::BILLING_ERRORS_SESSION_KEY are
 *    storefront-only. In adminhtml the customer session holds no customer id, so the owner
 *    there is permanently self::GUEST_OWNER_ID and the flush is a NO-OP on all five.
 *    WHAT THAT COSTS, QUANTIFIED, so nobody "fixes" a non-defect and nobody mistakes it for
 *    the shopper-facing defect this class exists to close:
 *      * WHO IS EXPOSED TO WHOM: admin to admin, on one shared browser, only. The admin area
 *        runs its OWN PHP session - Magento\Backend\Model\Session\AdminConfig gives it a
 *        distinct session name and a cookie path under the admin front name - so the
 *        Magento\Customer\Model\Session reached from adminhtml is a different storage from
 *        the storefront one. A shopper can therefore never inherit an admin's entries and an
 *        admin can never inherit a shopper's; there is no admin <-> shopper contamination to
 *        scope for.
 *      * WHAT REPLAYS: an identical admin order create (Plugin\Admin\OrderSave - one email
 *        and up to two phone numbers per submission, plus the batch address verdicts), an
 *        identical customer-email re-check (Plugin\Admin\ValidateCustomer), an identical
 *        address re-check (Plugin\Admin\ValidateAddress - the phone and the single-address
 *        verdict) and an address the first admin had already picked out of the Capture lookup
 *        (Controller\Adminhtml\Capture\Retrieve). The customer import replays batch verdicts
 *        only: it writes neither contact store.
 *      * WHAT THE MERCHANT PAYS: nothing extra. The second admin is NOT billed for a verify
 *        the first already paid for, which is what the caches are FOR. What is imprecise is
 *        the ATTRIBUTION of that one call between two admins, and the module has never
 *        claimed to attribute per admin user.
 *      * WHAT IS NOT ANOTHER ADMIN'S JUDGEMENT: only PASSES are stored in the batch cache
 *        (storeBatchVerifyResult() caches nothing else) and every entry is namespaced by
 *        store view and by a fingerprint of the configured threshold
 *        (Validator::buildBatchVerifyCacheKey()), so a replayed entry is a pure function of
 *        (address, store view, threshold) - exactly the verdict the second admin would have
 *        earned by submitting that address themselves. The contact stores replay a
 *        "warned once, allowed on resubmission" decision, which is a decision about a VALUE,
 *        not about a person.
 *      * WHAT THE TWO CONTACT STORES EXPOSE: nothing readable. This is what changed the
 *        calculus for them, and it is why (b) - record the residual - is defensible there
 *        where inheriting the LOQ-16978 paragraph would not have been: since LOQ-17149 they
 *        hold salted HMAC digests and not the values (see contactDigest()), under a salt that
 *        dies with the session, so their residual is a BILLING-ATTRIBUTION one - the same
 *        class of residual LOQ-16978 already accepted for the batch cache.
 *        THAT CLAIM IS ONLY WORTH MAKING BECAUSE IT HOLDS ON THE ERROR PATH, which is the only
 *        path that stores anything and the whole reason these stores exist ("warned once,
 *        submit again"). Plugin\Admin\OrderSave used to answer a failed check by handing the
 *        entire order-create POST - the account email address, every address's telephone, the
 *        names and the streets - to Session::setCustomerFormData(), raw, in the same request
 *        that had just stored the digest, which put the values straight back into the session
 *        the digest had kept them out of. LOQ-17149 REMOVED that call: nothing in adminhtml
 *        reads 'customer_form_data' off the customer session, so it re-populated no form. The
 *        enumeration behind that is at the old call site in Plugin\Admin\OrderSave.
 *      * WHAT IS STILL READABLE, so this is not read as more than it says. ALL THREE ADDRESS
 *        stores are readable, none of them is hashed, and none of them was in LOQ-17149's scope:
 *          - self::CAPTURED_ADDRESSES_SESSION_KEY holds, in full, the addresses the first admin
 *            picked out of the Capture lookup (bounded to
 *            Helper\Controller::CAPTURED_ADDRESSES_LIMIT entries);
 *          - self::VERIFY_CACHE_SESSION_KEY and self::BATCH_VERIFY_CACHE_SESSION_KEY hold only a
 *            boolean and a schema version in each ENTRY, but the address is in the KEY that entry
 *            sits under, in plaintext. Both key builders emit
 *            '<store view>|<threshold fingerprint>|<signature>' and only the THRESHOLD segment is
 *            hashed (Validator::buildVerifyCacheKey(), Validator::buildBatchVerifyCacheKey()); the
 *            signature is Validator::buildVerifyCacheSignature() for BOTH caches - the two street
 *            lines, the city, the postcode, the country, the full joined street and the region,
 *            upper-cased, whitespace-collapsed and joined with '|'. Validator's own logging rule
 *            states the consequence outright: it hashes that key before writing it to a log
 *            because the address and the signature "are customer PII"
 *            (Validator::logVerifyCacheOutcome()).
 *        None of the three is flushed on this path - the flush needs a CUSTOMER change and
 *        adminhtml never has one - so a second admin at a shared browser can read every address
 *        the first one verified, imported or looked up, and not merely the captured ones. That
 *        residual is LOQ-16978's, it is unchanged by this ticket in either direction, and it is
 *        stated at this length so the bullets above cannot be read as "an admin session holds
 *        nothing about a customer": what LOQ-17149 removed from this session is the readable
 *        EMAIL ADDRESS and PHONE NUMBER, not the readable addresses.
 *    THE PRICE OF CLOSING IT, which is why it is not closed: Magento\Backend\Model\Auth\Session
 *    would have to be read here, and this class is constructed on every frontend checkout
 *    request through Helper\Validator and Plugin\AbstractPlugin. Either it becomes a required
 *    constructor argument - which forces an edit to every one of the seven constructors that
 *    build this class inline, breaks anything extending them, and is a backend dependency in
 *    a frontend hot path - or it becomes an optional one that is null exactly where it would
 *    be needed. Neither buys anything but a second bill for a verdict that is identical by
 *    construction.
 *  - GUEST TO GUEST ON ONE BROWSER IS NOT COVERED EITHER, and it is recorded here rather than
 *    left to be discovered because it is the case this class's own opening paragraph names.
 *    The marker holds an IDENTITY, and two successive people who never sign in present the
 *    same one: both resolve to self::GUEST_OWNER_ID, the marker matches, and nothing is
 *    flushed. So on the public terminal and the click-and-collect kiosk - where nobody logs in
 *    at all - every one of the seven stores carries over from one person to the next, INCLUDING
 *    the bypass this class exists to stop: the second guest's first submission of a value the
 *    first was warned about is accepted with no verification and no warning. This is the same
 *    residual as the admin one above but WITHOUT its consolation, because
 *    self::PENDING_EMAIL_SESSION_KEY is the one enrolled store that stays RAW (a digest cannot
 *    be put on the wire), so the second guest can also inherit a stranger's actual email
 *    address. That matters MORE after LOQ-17149 than before it, not less: this ticket is what
 *    enrolled that attribute, and enrolling it is what makes the login case safe while leaving
 *    this one exactly as it was.
 *    WHY IT IS NOT CLOSED HERE: there is no signal to close it with. "A different person is at
 *    this browser now" is not an event the application can observe when neither of them
 *    authenticates - it is the same session, the same cookie and the same identity - so no
 *    check inside this class can tell the two apart, and the only behaviour that would be safe
 *    against it is flushing on every request, which is the same as having no stores at all.
 *    What actually bounds it is outside this class and is worth knowing: the session cookie's
 *    lifetime and Magento's session lifetime end the shared session, and each store is bounded
 *    (self::VERIFIED_CONTACT_LIMIT, Helper\Controller::CAPTURED_ADDRESSES_LIMIT,
 *    Validator::VERIFY_CACHE_LIMIT, Validator::BATCH_VERIFY_CACHE_LIMIT) so nothing accumulates
 *    without limit while it lasts. A merchant running a genuinely shared terminal should shorten
 *    that lifetime; the module cannot decide it for them.
 *  - ALL UNREADABLE CUSTOMER IDS COLLAPSE ONTO ONE OWNER, see resolveOwnerId(). Two
 *    successive identities that both present an unreadable id would share the stores. It
 *    cannot collide with a guest or with a real customer, which is the collision that
 *    mattered.
 *  - CONCURRENCY: reading the marker, flushing and rewriting it are not atomic, exactly as
 *    on the caches this protects (see Validator::verifyAddress()). Two concurrent
 *    requests straddling a login can both observe the old marker; the loser re-verifies,
 *    which costs a billable call and grants nothing.
 *
 * ADDING AN EIGHTH STORE: list it in self::SHOPPER_SCOPED_SESSION_KEYS. Reading or writing
 * an attribute through this class does NOT enrol it in the flush; only that list does, and
 * getData()/setData() REJECT any key missing from it so that an un-enrolled attribute
 * cannot quietly acquire the guard's appearance without its protection. Decide the eighth
 * store on its own merits before adding it - LOQ-17149 assessed its four one at a time and
 * they did not all get the same answer: the two LISTS are additionally BOUNDED
 * (self::VERIFIED_CONTACT_LIMIT) and HASHED (contactDigest()), while for the single scalar
 * and the single boolean bounding is meaningless and only the flush was needed.
 *
 * ADDING A STORE THAT IS NOT A SESSION ATTRIBUTE: use ownershipGeneration() (LOQ-17148).
 * Verdict data does not have to live in the session to belong to one shopper -
 * Validator::verifyMultipleAddresses() now also remembers batch verdicts in a PLAIN PHP MAP
 * on the Validator instance, for the length of one import run - and a store this class
 * cannot flush is a store that survives the flush, which re-opens exactly the hand-off the
 * class exists to close. Such a store is enrolled by asking ownershipGeneration() before it
 * is read or written and discarding its own contents when the answer has moved on: one
 * ownership model, two mechanisms, rather than a second identity check written at a call
 * site where it would rot independently of this one.
 *
 * WHAT ELSE IS IN THE CUSTOMER SESSION, named so the next reader does not have to grep for
 * it and does not conclude the count is wrong:
 *  - self::IP_COUNTRY_SESSION_KEY, this module's one attribute that is deliberately NOT
 *    enrolled. Reachable only through getIpCountry()/setIpCountry(), which say why;
 *  - self::SESSION_OWNER_KEY and self::CONTACT_DIGEST_SALT_KEY, which this class owns and
 *    writes itself and no caller can reach at all;
 *  - 'customer_form_data' and 'address_form_data', reached through Magento's own typed
 *    setters (Session::setCustomerFormData(), Session::setAddressFormData()) from
 *    Plugin\AbstractPlugin's rememberCustomerFormData()/rememberAddressFormData(). They are
 *    CORE attributes: core writes them on its own validation failures and core reads them
 *    back with getCustomerFormData(true), which clears them. They are not verify bypasses,
 *    this module is not their only writer, and flushing an attribute whose lifetime core
 *    manages would be this module deciding core's business - so they are out of scope here
 *    and named at their accessors instead. That reasoning is about the CALL SITES, not about
 *    the attribute names: it holds for the three storefront callers, whose redirect targets
 *    core renders from the value and clears in doing so, and it did NOT hold for the one
 *    adminhtml caller, which LOQ-17149 therefore removed rather than excused (see
 *    Plugin\Admin\OrderSave). A new caller has to be checked against that, not against this
 *    paragraph.
 */
class ShopperScopedSessionStores
{
    /**
     * Session attribute holding the addresses picked from the Loqate Capture lookup.
     *
     * Written only by Helper\Controller::storeCapturedAddress() and read only by
     * Helper\Validator; the name lives HERE because this class is what enforces the
     * attribute's lifetime, and Controller::CAPTURED_ADDRESSES_SESSION_KEY aliases it.
     */
    public const CAPTURED_ADDRESSES_SESSION_KEY = 'captured_addresses';

    /**
     * Session attribute holding the single-address verify verdict cache (LOQ-16969).
     *
     * Aliased as Validator::VERIFY_CACHE_SESSION_KEY, where the reasoning about WHAT it
     * holds and why it is namespaced per store view and AVC threshold lives.
     */
    public const VERIFY_CACHE_SESSION_KEY = 'loqate_verified_addresses';

    /**
     * Session attribute holding the BATCH verify verdict cache (LOQ-16976).
     *
     * Aliased as Validator::BATCH_VERIFY_CACHE_SESSION_KEY, where the reasoning for it
     * being a PHYSICALLY SEPARATE attribute from the single-address cache lives.
     */
    public const BATCH_VERIFY_CACHE_SESSION_KEY = 'loqate_verified_batch_addresses';

    /**
     * Session attribute holding the email addresses this session has already been warned
     * about (LOQ-17149).
     *
     * WHAT IT ACTUALLY GATES, which is more than billing. Written and read only by
     * Plugin\AbstractPlugin::shouldVerify(), and consulted only when
     * loqate_settings/email_settings/prevent_submit is OFF - which is the SHIPPED DEFAULT
     * (etc/config.xml). In that mode the module warns once ("Invalid email address. Submit
     * again to use this email address.") and accepts the value on the next submission, and
     * this attribute is how "the next submission" is recognised. A match therefore skips the
     * billable verifyEmail() AND suppresses the warning, so a stale entry does not merely
     * save a call: it lets an address through with no check and no warning at all. That is
     * why this is enrolled in the flush and not merely bounded.
     *
     * Since LOQ-17149 the entries are salted digests, never the addresses - see
     * contactDigest(). No alias exists anywhere: this was a bare 'loqate_email' literal at
     * its two call sites before this ticket.
     */
    public const VERIFIED_EMAIL_SESSION_KEY = 'loqate_email';

    /**
     * Session attribute holding the phone numbers this session has already been warned about.
     *
     * Exactly self::VERIFIED_EMAIL_SESSION_KEY's story with
     * loqate_settings/phone_settings/prevent_submit and verifyPhoneNumber() substituted, and
     * the same shipped default of OFF. A SEPARATE attribute rather than one map with two
     * sections, for the reason self::BATCH_VERIFY_CACHE_SESSION_KEY is separate from
     * self::VERIFY_CACHE_SESSION_KEY: two physically distinct attributes make it
     * structurally impossible for a phone entry to answer an email lookup, rather than
     * merely unlikely. (contactDigest() namespaces by field as well, so the two are disjoint
     * twice over - belt and braces, and the braces are the ones that survive somebody
     * "simplifying" the two attributes into one.)
     */
    public const VERIFIED_PHONE_SESSION_KEY = 'loqate_phone';

    /**
     * Session attribute holding ONE email address awaiting a billable verify (LOQ-17149).
     *
     * Written by Plugin\Frontend\AccountManagement::beforeIsEmailAvailable() when a guest
     * types an email into checkout; read, verified and cleared by
     * Plugin\Frontend\CheckoutShippingInformation::aroundSaveAddressInformation(). It is a
     * hand-off between two requests, not a cache.
     *
     * BOUNDING IT WOULD BE MEANINGLESS AND IS DELIBERATELY NOT DONE. It holds a single
     * scalar that each write overwrites, so it has exactly one slot already; inventing a
     * "limit of 1" would be a constant that documents PHP's assignment semantics.
     * self::VERIFIED_CONTACT_LIMIT does not apply to it and must not be applied to it.
     *
     * FLUSHING IT IS ESSENTIAL, and here is what goes wrong without it. The value survives
     * an abandoned checkout: it is cleared only on a SUCCESSFUL verify inside the
     * shipping-information save, so a guest who types an email and leaves leaves it behind.
     * Then, on a shared browser after a login or a logout, the next shopper's
     * shipping-information save reads shopper A's address and (a) spends a billable
     * verifyEmail() on it, attributed to B's checkout, and (b) if it fails, blocks B's
     * checkout with "Invalid email address. Submit again to use this email address." about
     * an address that appears nowhere on B's form - which B cannot fix by correcting
     * anything they can see. On top of that (c) A's raw email address sits in B's session.
     *
     * IT IS THE ONE STORE THAT MUST STAY RAW. CheckoutShippingInformation reads the value
     * back and passes it to Validator::verifyEmail(), i.e. onto the wire; a digest cannot be
     * verified. See contactDigest() for why the other two contact stores could be hashed and
     * this one could not.
     */
    public const PENDING_EMAIL_SESSION_KEY = 'loqate_email_to_validate';

    /**
     * Session attribute recording that the billing address failed validation (LOQ-17149).
     *
     * Written by Plugin\Frontend\CheckoutBillingAddress::aroundAssign() - true on failure,
     * false on success - and read by Plugin\Frontend\PlaceOrder and
     * Plugin\Frontend\PlaceOrderGuest, which throw
     * CouldNotSaveException('Please check the error again before continuing.') while it is
     * truthy. It exists because the billing address is resubmitted at place-order time.
     *
     * BOUNDING IT WOULD BE MEANINGLESS AND IS DELIBERATELY NOT DONE: it holds one boolean.
     *
     * THE ORDERING THAT MAKES THIS ATTRIBUTE DANGEROUS, stated once and relied on twice
     * below. The ONLY writer is CheckoutBillingAddress::aroundAssign(), i.e. a call to
     * Magento\Quote\Model\BillingAddressManagement::assign(). Both readers are BEFORE plugins
     * on savePaymentInformationAndPlaceOrder(), which assigns the billing address further
     * down that same call. So on any flow that submits the billing address WITH the
     * place-order call rather than in a separate request, a truthy value makes the reader
     * throw BEFORE the only writer that could clear it is reached.
     *
     * FLUSHING IT IS ESSENTIAL, AND THE CONSEQUENCE IS A DENIAL OF CHECKOUT, NOT A BYPASS.
     * Stated precisely because the two directions call for opposite fixes. A stale FALSE
     * grants nothing - it is the same as the empty default. A stale TRUE inherited across a
     * shopper change makes both place-order plugins throw at the NEXT shopper, on a checkout
     * where nothing is wrong, with a message naming an error they never saw and no field they
     * could correct; and by the ordering above they cannot resubmit their way out of it.
     * Flushing writes null, which is falsy, so the post-flush state is "not blocked" - the
     * same state as a fresh session, and the safe default in the one direction that cannot
     * let an unverified billing address through (the address itself is re-verified by
     * aroundAssign() whenever that runs).
     *
     * WHAT THE FLUSH DOES NOT FIX, so this docblock is not read as closing the whole defect:
     * the SAME shopper, unchanged, is still permanently blocked on those same flows. Their
     * first attempt sets the gate and throws; every attempt after it is refused by the gate
     * before assign() runs, so nothing they can do releases it. There is no identity change
     * for enforceOwnership() to see - one person, one session - so no flush fires and none
     * could. That is a live defect, tracked as LOQ-17195, NOT closed by LOQ-17149, and pinned
     * as behaviour by Test\Unit\Plugin\Frontend\BillingErrorGateTest. What clears it today is
     * a sign-in, a sign-out or the session ending.
     */
    public const BILLING_ERRORS_SESSION_KEY = 'loqate_billing_errors';

    /**
     * Session attribute caching the country resolved from the request's IP address.
     *
     * NOT in self::SHOPPER_SCOPED_SESSION_KEYS, and reachable only through getIpCountry() /
     * setIpCountry(), where the reasoning lives. Declared here so that this class is the
     * complete map of the module's session usage rather than a list with a silent omission.
     */
    public const IP_COUNTRY_SESSION_KEY = 'loqate_ipcountry';

    /**
     * Maximum number of contact digests kept per session in EACH of the two lists, oldest
     * evicted first (LOQ-17149).
     *
     * SMALLER THAN ITS SIBLINGS ON PURPOSE. Helper\Controller::CAPTURED_ADDRESSES_LIMIT and
     * Validator::VERIFY_CACHE_LIMIT are 50 and Validator::BATCH_VERIFY_CACHE_LIMIT is 200;
     * that largest figure exists solely to hold a 100-row customer-import chunk
     * (Plugin\Admin\ValidateImportAddress.php:50), and NO IMPORT WRITES THESE TWO STORES -
     * Plugin\Admin\ValidateImportAddress reaches neither validateEmail() nor validatePhone(),
     * so the "must hold one chunk" floor argument does not apply here at all and must not be
     * imported into it.
     *
     * WHY 25 IS ALREADY GENEROUS. One interactive checkout presents one email address and at
     * most two phone numbers (shipping and billing), plus a re-typed value after a warning.
     * The path with real volume is an admin working through several orders in one browser
     * session, and that is exactly the path where evicting the OLDEST entry is the right
     * answer: the value being checked right now was appended last, so it can never be the
     * one evicted.
     *
     * WHAT AN EVICTION COSTS, stated so the number is not defended as though it were free:
     * entries are NOT refreshed on a hit - a match returns without writing anything, which
     * is what keeps the common case free of session writes - so a value the shopper is still
     * using keeps its original age and can be pushed out by 25 newer distinct values. The
     * consequence is one extra billable verify and one extra warning, never a bypass, which
     * is the safe direction.
     *
     * WHY IT IS ONE CONSTANT FOR BOTH LISTS: they have the same shape, the same single writer
     * (Plugin\AbstractPlugin::shouldVerify()) and the same entry size, so two constants would
     * be two things to keep equal. Each list is bounded to this figure SEPARATELY, so the
     * session holds at most 2 x 25 x 64 characters of digest, about 3 kB.
     */
    public const VERIFIED_CONTACT_LIMIT = 25;

    /**
     * Session attribute recording which customer the stores below belong to.
     *
     * A SIBLING attribute rather than a member inside each store: the stores have several
     * different shapes (a list of serialised addresses, two key => serialised-verdict maps,
     * two lists of digests, a string, a boolean), every reader of them is defensive about
     * that shape, and wrapping them would mean changing all of those readers to unwrap an
     * owner they must never trust anyway. One attribute also means one place to compare and
     * one place to write.
     */
    private const SESSION_OWNER_KEY = 'loqate_session_cache_owner';

    /**
     * Session attribute holding the per-session salt the contact digests are keyed with.
     *
     * NOT enrolled in self::SHOPPER_SCOPED_SESSION_KEYS and not reachable through
     * getData()/setData(): it is written by resolveContactDigestSalt() and rotated by
     * enforceOwnership(), and nothing outside this class has any business reading it. It is
     * flushed WITH the stores it protects rather than by being in that list, because the
     * list is also the enrolment allowlist and the salt must never be reachable through the
     * generic accessors - see contactDigest() for what the rotation buys.
     */
    private const CONTACT_DIGEST_SALT_KEY = 'loqate_contact_digest_salt';

    /**
     * Bytes of CSPRNG output behind each session's contact-digest salt.
     *
     * 32 bytes / 256 bits, matching the SHA-256 block the HMAC is built on, and rendered as
     * 64 hex characters by bin2hex() so the value is safe to keep in a session payload that
     * is serialised as text.
     */
    private const CONTACT_DIGEST_SALT_BYTES = 32;

    /**
     * Length of a contact digest, and of the hex salt, in characters.
     *
     * hash_hmac('sha256', ...) returns 64 lowercase hex characters, and
     * bin2hex(random_bytes(32)) returns 64 as well. Used by isContactDigest() to recognise
     * this class's own output, and by resolveContactDigestSalt() to reject a truncated or
     * foreign salt rather than hashing under it.
     */
    private const CONTACT_DIGEST_LENGTH = 64;

    /**
     * Owner id standing for "no customer is logged in".
     *
     * A real id, not null, so that "guest" is a value the marker can hold and be COMPARED
     * against. Were guests left unmarked, logging out (customer -> guest) would look
     * identical to a session that has never been marked, and the logged-in -> guest
     * transition - the exact one that strands shopper A's bypasses in front of whoever
     * uses the browser next - would fall into the adoption branch instead of flushing.
     * Customer ids are positive auto-increment values, so 0 can never collide with one.
     */
    private const GUEST_OWNER_ID = 0;

    /**
     * Owner id standing for "the session answered a customer id we cannot read".
     *
     * Its own sentinel, and NEGATIVE, for exactly the reason GUEST_OWNER_ID is 0: customer
     * ids are positive auto-increment values and the guest is 0, so -1 cannot collide with
     * either. Mapping the unreadable case onto GUEST_OWNER_ID instead - which is what this
     * class did before the LOQ-16978 review - was a defect, not a conservative default: it
     * made customer-with-unreadable-id indistinguishable from a genuine guest, so a LOGOUT
     * out of that state did not flush and the guest that followed inherited every bypass
     * store, which is precisely the hand-off this class exists to stop.
     *
     * @see self::resolveOwnerId() for what remains uncovered, and why that is accepted.
     */
    private const UNREADABLE_OWNER_ID = -1;

    /**
     * Every session attribute owned by one shopper, flushed together on an identity change.
     *
     * All seven are verify BYPASSES or gates on one shopper's submission, which is why they
     * share a lifetime: a stale entry in any one of them lets data through without the
     * billable Loqate call that would have judged it for THIS shopper, or - in
     * self::BILLING_ERRORS_SESSION_KEY's case - refuses this shopper an order because of a
     * previous one's failure. This is also the ENROLMENT list, not merely a flush list -
     * getData()/setData() refuse any key that is not on it, so "reachable through this class"
     * and "flushed by this class" cannot drift apart.
     *
     * ORDER IS NOT SIGNIFICANT: the list is only ever iterated to flush, and every entry is
     * flushed on the same request.
     */
    private const SHOPPER_SCOPED_SESSION_KEYS = [
        self::CAPTURED_ADDRESSES_SESSION_KEY,
        self::VERIFY_CACHE_SESSION_KEY,
        self::BATCH_VERIFY_CACHE_SESSION_KEY,
        self::VERIFIED_EMAIL_SESSION_KEY,
        self::VERIFIED_PHONE_SESSION_KEY,
        self::PENDING_EMAIL_SESSION_KEY,
        self::BILLING_ERRORS_SESSION_KEY,
    ];

    /** @var Session Raw customer session; deliberately private, see the class docblock. */
    private $session;

    /**
     * @var Data|null Store-view resolver for contactDigest()'s namespacing, or null when the
     *      holder had none to give. OPTIONAL and TRAILING on purpose: making it required
     *      would force an edit to all seven constructors that build this class inline and
     *      break anything extending them. Only contactDigest() reads it, and only
     *      Plugin\AbstractPlugin computes digests, so the classes that pass nothing lose
     *      nothing - see resolveStoreScope() for how the null case degrades.
     */
    private ?Data $helper;

    /**
     * How many OWNERSHIP EPOCHS this instance has seen: the number of times ownership of the
     * stores has been established or re-established since it was constructed.
     *
     * NOT A FLUSH COUNT, and the distinction is the whole point (LOQ-17148 mutation review):
     * enforceOwnership() advances it whenever it WRITES the owner marker - on a flush, and
     * equally on an ADOPTION, where the marker was absent and nothing was flushed. The contract
     * a reader depends on is stated on ownershipGeneration(); the mechanism, and why adoption
     * has to count, at the increment in enforceOwnership().
     *
     * A COUNTER RATHER THAN A BOOLEAN OR AN OWNER ID: a reader has to tell "same epoch as when
     * I last looked" from "two epochs have passed", a flag cleared by its reader would be wrong
     * as soon as there were two readers, and an A -> B -> A cycle within one request returns to
     * the same owner id while the stores were genuinely flushed in between.
     *
     * Not persisted anywhere, deliberately. It describes THIS request's view of the stores,
     * which is the only lifetime the derived data it protects has.
     */
    private int $ownershipGeneration = 0;

    /**
     * @param Session $session The per-shopper customer session the stores live in.
     * @param Data|null $helper Config/store helper, used only to namespace contact digests
     *                          by store view. Omit it unless contactDigest() will be called.
     */
    public function __construct(Session $session, ?Data $helper = null)
    {
        $this->session = $session;
        $this->helper = $helper;
    }

    /**
     * Read one shopper-scoped attribute, after making sure it belongs to the shopper
     * making this request.
     *
     * @param string $key One of self::SHOPPER_SCOPED_SESSION_KEYS.
     * @return mixed The stored value, or null when nothing is stored or it has just been
     *               flushed. Every caller already treats null as "nothing usable cached".
     * @throws \InvalidArgumentException When $key is not enrolled in the flush.
     */
    public function getData(string $key)
    {
        $this->assertEnrolled($key);
        $this->enforceOwnership();

        return $this->session->getData($key);
    }

    /**
     * Write one shopper-scoped attribute, after making sure the stores belong to the
     * shopper making this request.
     *
     * The ownership check runs BEFORE the write, so a write performed on the first request
     * after an identity change stores its value into freshly flushed stores rather than
     * having it wiped a moment later.
     *
     * @param string $key One of self::SHOPPER_SCOPED_SESSION_KEYS.
     * @param mixed $value
     * @return void
     * @throws \InvalidArgumentException When $key is not enrolled in the flush.
     */
    public function setData(string $key, $value): void
    {
        $this->assertEnrolled($key);
        $this->enforceOwnership();

        $this->session->setData($key, $value);
    }

    /**
     * Enforce ownership NOW and report which OWNERSHIP EPOCH the caller is looking at, so data
     * DERIVED from these stores - held anywhere, not only in the session - can be discarded
     * the moment the stores stop demonstrably belonging to the identity that earned it
     * (LOQ-17148).
     *
     * WHAT THE NUMBER MEANS, stated first because it is the contract: it counts how many times
     * ownership has been ESTABLISHED, not how many flushes have happened. enforceOwnership()
     * advances it whenever it writes the owner marker - on an identity change, and equally on
     * an adoption, where no marker was recorded and so nothing was flushed. "Unchanged" is
     * therefore the strong statement (the stores have demonstrably belonged to one identity
     * throughout), and that is the direction that must be strong, because it is the answer
     * that licenses a caller to KEEP derived data.
     *
     * WHAT PROBLEM THIS SOLVES. Validator::verifyMultipleAddresses() remembers batch verdicts
     * for one import run in a plain map on the Validator instance, and that map holds the same
     * kind of data as self::BATCH_VERIFY_CACHE_SESSION_KEY - licences to skip a billable verify
     * - so it must have the same OWNERSHIP lifetime, which a plain request-scoped map does not
     * give it. See Validator::$runScopedBatchVerdicts and
     * Validator::discardRunScopedVerdictsIfShopperChanged() for that side of it.
     *
     * WHY A GENERATION RATHER THAN A CALLBACK OR A FLUSH LIST. This class holds no reference to
     * its holders and must not start holding one: it is constructed inside the constructors of
     * the seven classes listed in the class docblock, so reaching back into them would invert
     * that dependency and make the flush order matter. A counter inverts the responsibility
     * instead - the guard states a fact, each holder decides what its own derived data means
     * when that fact changes - and it composes, since no reader can consume the signal from
     * under another.
     *
     * ENFORCES OWNERSHIP ITSELF, and that is the load-bearing half. A caller that consulted its
     * own map BEFORE touching any session attribute - exactly what the run map does on the import
     * path, where the captured-address read is skipped - would otherwise read a generation from
     * before the flush and serve a stale verdict on the first address of the request.
     *
     * NO KEY, AND THEREFORE NO assertEnrolled() CALL: there is no attribute to enrol, this
     * reports on the whole flush unit named by self::SHOPPER_SCOPED_SESSION_KEYS.
     *
     * @return int The ownership epoch as of this call. Compare it against the value seen last
     *             time; ANY difference means ownership was re-established in between - by a
     *             flush or by an adoption - and anything derived from these stores must be
     *             discarded. A caller with nothing recorded yet must treat that as a
     *             difference too, which it gets for free by starting from null.
     */
    public function ownershipGeneration(): int
    {
        $this->enforceOwnership();

        return $this->ownershipGeneration;
    }

    /**
     * The one-way digest under which an email address or phone number is remembered as
     * "already warned about" (LOQ-17149).
     *
     * WHY A DIGEST AT ALL. self::VERIFIED_EMAIL_SESSION_KEY and
     * self::VERIFIED_PHONE_SESSION_KEY are pure COMPARISON stores: their only reader,
     * Plugin\AbstractPlugin::shouldVerify(), asks "have I seen this value before?" and never
     * reads a stored value back out for any other purpose. A store that only has to compare
     * does not need to hold the value, and these values are the customer's email address and
     * phone number, held for the whole life of the session. Hashing is therefore the
     * strongest available reduction at essentially no cost, and it is the reason the
     * adminhtml residual in the ACCEPTED LIMITS above is a billing question rather than a
     * PII-retention one. self::PENDING_EMAIL_SESSION_KEY is the counter-example that proves
     * the rule: it is read back and put ON THE WIRE by Validator::verifyEmail(), a digest
     * cannot be verified, and so it stays raw.
     *
     * FULL LENGTH, 64 HEX CHARACTERS, AND DO NOT "MAKE IT CONSISTENT" WITH THE CACHE KEYS.
     * Validator::buildVerifyCacheKey() and logVerifyCacheOutcome() truncate their SHA-256 to
     * 12 hex characters, and that is correct THERE: those are namespace separators and log
     * correlation tokens inside one session, where a collision costs a re-verify. These are
     * different. 12 hex characters is 48 bits, and 48 bits of a hash of an EMAIL ADDRESS is
     * not a secret against anyone holding a candidate list - the whole point of hashing here
     * is that possession of the session payload must not yield the address, so the digest
     * must not be truncated to a width a wordlist can exhaust.
     *
     * SALTED, AND THE SALT DIES WITH THE SESSION. hash_hmac() is keyed with
     * resolveContactDigestSalt(), a fresh 256-bit CSPRNG value per session that is ROTATED
     * whenever ownership changes (enforceOwnership()) and never persisted anywhere but the
     * session. Two consequences, and only the first is a secret-keeping claim: an unsalted
     * digest of an email address is a global identifier - the same address digests
     * identically in every session on every installation, so digests could be matched across
     * sessions or against a precomputed table - while these cannot; and because the salt is
     * rotated with the flush, a digest that somehow survived a shopper change could not match
     * anything afterwards even if the flush itself were undone. NO module-lifetime or
     * config-persisted secret is used, deliberately: a long-lived key would restore exactly
     * the cross-session linkability the per-session salt removes, and would be one more
     * secret to protect.
     *
     * WHAT IT DOES NOT CLAIM. Anyone who can read the session PAYLOAD reads the salt as well
     * as the digests, so they can TEST a guess: "was this address used in this session?" is
     * answerable to someone who already has the address. What they can no longer do is READ
     * an address they did not already have. That is the reduction, at exactly its real
     * strength - and against the threat this ticket is about, the person at a shared browser,
     * even that oracle is unavailable, because they can reach the session only through this
     * module's code.
     *
     * THE EQUIVALENCE THIS MUST PRESERVE, and the one place it deliberately does not.
     * shouldVerify() used a LOOSE in_array($value, $storedData); comparing digests is
     * strict. The relation is pinned by the (string) cast below, and the change can only ever
     * be in one direction: two values produce the same digest if and only if their string
     * casts are identical, and OVER THE DOMAIN THAT REACHES THIS METHOD identical string casts
     * imply loose equality - so strict digest equality is a SUBSET of what loose in_array()
     * matched. A match can therefore become a miss - one extra billable verify and one extra
     * warning - and a miss can NEVER become a match, so no new bypass is reachable through this
     * change. The cast also keeps the two degenerate cases behaving exactly as before, because
     * (string)null === '' and null == '' was already true.
     *
     * THE DOMAIN IS NAMED RATHER THAN ASSUMED, because the implication is not universal and
     * this paragraph is the safety argument for the whole change. Every $value that reaches
     * here is an email address or a phone number taken straight out of the request - a string,
     * or null when the field was absent - and for strings and null the implication holds
     * outright. It does NOT hold for floats: at the default precision=14 two distinct doubles
     * can share a (string) cast while comparing unequal, which would make digest equality a
     * WIDENING there rather than a subset. That is unreachable from $_POST and no call site
     * constructs one, which is why the claim is safe; it is stated because "identical casts
     * imply loose equality", left unqualified, is a false sentence that a later reader could
     * carry somewhere it matters.
     *
     * The one case where the relation genuinely narrows is a FIX and is implemented
     * deliberately: PHP 8 compares two numeric strings NUMERICALLY, so in_array() treated
     * '0123456789' and '123456789' - and '+4412345' and '4412345', and '0044123' and '44123'
     * - as the same phone number, and the second of each pair skipped its billable
     * verifyPhoneNumber() and its warning on the strength of the first. They are different
     * numbers. Under the digest they are different entries, each verified once. Recorded in
     * CHANGELOG.md as a merchant-visible change, because in that edge case the merchant pays
     * for one verification they previously did not.
     *
     * NAMESPACED BY FIELD AND BY STORE VIEW. The field name is in the message so an email
     * digest can never satisfy a phone lookup even if the two attributes were ever merged
     * (they are separate today, so this is the second of two independent guards). The store
     * view is in the message because these two stores were NOT namespaced by store view at
     * all before LOQ-17149, unlike the address caches: one session can span store views
     * (?___store=, a language switcher), each store view can carry its own API key and its
     * own prevent_submit toggle, so a "warned once, now allowed" decision earned under one
     * store view's configuration used to replay under another's. See resolveStoreScope() for
     * how it degrades when no store can be resolved.
     *
     * @param string $field One of self::SHOPPER_SCOPED_SESSION_KEYS - in practice
     *                      self::VERIFIED_EMAIL_SESSION_KEY or
     *                      self::VERIFIED_PHONE_SESSION_KEY. Asserted as ENROLLED rather
     *                      than against those two specifically, because the property that
     *                      matters is the one assertEnrolled() checks: the digest is only
     *                      ever stored under $field, so $field must be a store this class
     *                      flushes.
     * @param mixed $value The email address or phone number as it arrived from the request.
     * @return string 64 lowercase hex characters, or '' when no digest can be produced -
     *                either because $value is not a scalar or because no salt could be
     *                generated. '' is the "do not cache this" sentinel, mirroring
     *                Validator::buildVerifyCacheKey()'s empty-signature sentinel;
     *                shouldVerify() answers it by verifying and storing nothing, which is
     *                the fail-closed direction.
     * @throws \InvalidArgumentException When $field is not enrolled in the flush.
     */
    public function contactDigest(string $field, $value): string
    {
        $this->assertEnrolled($field);

        // Runs the flush and any salt rotation BEFORE the salt is read, so the digest can
        // never be computed under a salt that this same request is about to discard. That
        // makes this method and getData() safe to call in either order, which matters
        // because shouldVerify() needs both and neither ordering is obviously correct.
        $this->enforceOwnership();

        if ($value !== null && !is_scalar($value)) {
            // An array or object reached a field that is meant to hold one contact detail -
            // a crafted `email[]=` POST does exactly this, and Plugin\Frontend\
            // CustomerAccountCreate and Plugin\Admin\OrderSave both hand $request['email']
            // straight through. Casting it to a string would raise an "Array to string
            // conversion" warning AND digest every array to the same value, so one
            // array-valued submission would grant a bypass to every other. Refused instead:
            // no digest, no store entry, and the billable verify runs.
            //
            // Stated precisely rather than as "same as before": such a value was never
            // MATCHED before either (an array is never loosely equal to a stored string), but
            // it WAS appended to the store. Not storing it is the only change, and it can only
            // cost a re-verify.
            return '';
        }

        $salt = $this->resolveContactDigestSalt();
        if ($salt === '') {
            return '';
        }

        // (string) is the whole of the equivalence with the loose comparison this replaces;
        // see the docblock. '|' is a safe separator because neither a store id nor a field
        // name from self::SHOPPER_SCOPED_SESSION_KEYS contains one, and because HMAC is not
        // vulnerable to the length-extension confusion a bare hash of concatenated fields
        // would be.
        return hash_hmac(
            'sha256',
            $field . '|' . $this->resolveStoreScope() . '|' . (string)$value,
            $salt
        );
    }

    /**
     * Is $value something contactDigest() produced?
     *
     * Used by Plugin\AbstractPlugin::shouldVerify() to drop anything else from the two
     * contact lists before comparing or appending. Two things reach those lists that are not
     * digests, and both should leave: a RAW email address or phone number written by a
     * release before LOQ-17149 into a session that was mid-flight at deploy time, and
     * anything another module put in an attribute that is, after all, a bare session key.
     * Legacy raw entries are inert either way - they can never equal a digest - so dropping
     * them changes no verdict; what it does is get the last raw contact details out of the
     * session at the first write instead of leaving them until eviction or a shopper change.
     *
     * Shape-based rather than provenance-based, because a session payload carries no
     * provenance. A 64-character lowercase hex string is what this class writes; the false
     * positive is a foreign value that happens to look exactly like one, which would be kept
     * and then never match anything.
     *
     * @param mixed $value
     * @return bool
     */
    public function isContactDigest($value): bool
    {
        return is_string($value)
            && strlen($value) === self::CONTACT_DIGEST_LENGTH
            && ctype_xdigit($value)
            && strtolower($value) === $value;
    }

    /**
     * Read the country resolved from this request's IP address.
     *
     * WHY THIS ATTRIBUTE IS DELIBERATELY NOT ENROLLED IN THE SHOPPER FLUSH, and why it is
     * reachable here at all. It is the one session attribute this module writes that is NOT
     * one shopper's data: it is derived from the request's IP address
     * (Helper\Extra::ipToCountry() over Magento's RemoteAddress), and two shoppers sharing a
     * browser share the IP address, so the value that was correct for shopper A is correct
     * for shopper B by construction. Flushing it would not protect B from anything; it would
     * just make the module resolve the same answer again. It also grants no verify bypass:
     * it only pre-selects a country in a dropdown (Plugin\ChangeAddressDefaultCountry,
     * Plugin\ChangeCheckoutDefaultCountry), and the address is verified afterwards on its
     * merits whatever the dropdown said. The PII in it is an IP-derived country code, not the
     * IP address itself.
     *
     * ON WHAT A FLUSH WOULD COST, since that bears on the decision: the lookup behind it is
     * Loqate's /Extras/Web/Ip2Country/ endpoint
     * (vendor/lqt/api-connector/src/Utils/API.php), NOT one of the /Cleansing/,
     * /EmailValidation/ or /PhoneValidation/ verifies whose repeat-billing these stores exist
     * to prevent - so this attribute is not part of the LOQ-16969 saving at all and this
     * module cannot tell you what, if anything, a merchant's contract charges for it. The
     * decision does not rest on that: it rests on the value not being shopper-scoped.
     *
     * REACHED THROUGH A NAMED PAIR RATHER THAN THROUGH getData()/setData(), which still
     * REFUSE this key. The point is that there is no general raw-session escape hatch left
     * anywhere in the module: every session attribute a plugin or helper can reach goes
     * either through the enrolled, flushed, asserted accessors above or through a named
     * accessor like this one that has to explain itself. A method with no key parameter also
     * cannot be pointed at a different attribute by mistake, which is what assertEnrolled()
     * protects the generic accessors from.
     *
     * @return mixed The Ip2Country response as Helper\Extra returned it - normally an array
     *               with an 'Iso2' member - or null when nothing is cached. Both callers
     *               already treat any falsy value as "look it up".
     */
    public function getIpCountry()
    {
        return $this->session->getData(self::IP_COUNTRY_SESSION_KEY);
    }

    /**
     * Cache the country resolved from this request's IP address.
     *
     * @see self::getIpCountry() for why this attribute is not enrolled in the shopper flush.
     * @param mixed $value Ip2Country response, or null when the lookup failed.
     * @return void
     */
    public function setIpCountry($value): void
    {
        $this->session->setData(self::IP_COUNTRY_SESSION_KEY, $value);
    }

    /**
     * Refuse any attribute that is not enrolled in the flush.
     *
     * WHY THIS IS AN ASSERTION AND NOT A COMMENT. Without it, reading a new attribute
     * through this class LOOKS guarded - the ownership check runs, the call site is
     * identical to the ones that are protected - while the attribute is never actually
     * flushed, silently keeping the defect LOQ-16978 was written to close. That is the
     * same class of trap Validator::BATCH_VERIFY_CACHE_SESSION_KEY answers with two
     * physically separate attributes rather than a prefix: make it structurally
     * impossible rather than merely unlikely.
     *
     * WHY A THROW IS SAFE HERE, despite running inside checkout. It is unreachable for
     * correct code. Every production call site passes one of the constants declared on this
     * class, and no instance HELD BY THIS MODULE is reachable from outside it: every holder
     * keeps it in a PRIVATE property and it is never placed in DI. (The class and its
     * constructor are public, so anything can construct its OWN instance - that is a separate
     * object over the same session and cannot reach an unenrolled key through these guards
     * any more than this code can.) So the only way to trip this is a NEW call inside this
     * module passing an unenrolled key, which is a programming error that must fail at the
     * developer's first request rather than ship as a silent bypass.
     *
     * The two attributes that ARE deliberately unenrolled prove the point rather than
     * weakening it: self::IP_COUNTRY_SESSION_KEY is refused here and reached through
     * getIpCountry()/setIpCountry() instead, and self::CONTACT_DIGEST_SALT_KEY is refused
     * here and reachable from nowhere but this class. Neither has a key parameter that could
     * be pointed at a store, so neither can acquire the guard's appearance.
     *
     * Every path lets it out, including the import, and the enumeration behind that claim grew
     * with LOQ-17149. NINETEEN statements reach this assertion: the calls to getData(), setData()
     * and contactDigest(), which are the only three methods that make it - isContactDigest() and
     * the getIpCountry()/setIpCountry() pair do not. They break down as EIGHT in Helper\Validator,
     * TWO in Helper\Controller, SEVEN in Plugin\AbstractPlugin, and ONE each in
     * Plugin\Frontend\PlaceOrder and Plugin\Frontend\PlaceOrderGuest. None of them sits inside a
     * try. Count them again if you change this: a breakdown that does not reconcile with its own
     * total is worse than no breakdown, because it is the total the next reader checks.
     * The module's only broad catches are
     * Plugin\ChangeAddressDefaultCountry's and Plugin\ChangeCheckoutDefaultCountry's, which
     * enclose the CountryFactory lookup and start AFTER those classes' getIpCountry() /
     * setIpCountry() calls (and those two accessors do not reach this assertion in any case);
     * Plugin\Admin\ValidateImportAddress's, which sits after its own rethrow; and the two in
     * Observer\QuoteSubmitBefore and Model\AdminNotification\UnverifiedAdminOrderMessage, which
     * touch no store. ValidateImportAddress::afterValidateData() used to wrap its work in a
     * blanket catch (\Exception), which swallowed this throw and turned it into an import that
     * silently reported no address errors at all; that plugin now catches
     * \InvalidArgumentException separately and rethrows it, precisely so this assertion reaches
     * a developer. Do not restore the broad catch on the strength of an older revision of this
     * paragraph.
     *
     * Logging and continuing was the alternative and was rejected: the continue path IS the
     * unguarded access the assertion exists to prevent, so it would leave the defect in place
     * and merely record it - and this class holds no logger to record it with.
     *
     * @param string $key Attribute the caller is trying to reach.
     * @return void
     * @throws \InvalidArgumentException Naming the fix, so the message is the instruction.
     */
    private function assertEnrolled(string $key): void
    {
        if (in_array($key, self::SHOPPER_SCOPED_SESSION_KEYS, true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Session attribute "%s" is not enrolled in ShopperScopedSessionStores::'
            . 'SHOPPER_SCOPED_SESSION_KEYS, so it would be reached through the shopper-ownership guard '
            . 'without ever being flushed when the shopper changes (LOQ-16978, LOQ-17149). Add it to that '
            . 'list, or - if the attribute genuinely is not shopper-scoped - give it its own named accessor '
            . 'on this class the way getIpCountry() does, with the reason it is excluded.',
            $key
        ));
    }

    /**
     * Flush every shopper-scoped store if the logged-in identity is not the one they were
     * written for, then record the current identity as their owner and open a new OWNERSHIP
     * EPOCH.
     *
     * Costs TWO session reads on the hot path - the ownership marker here, and the
     * customer id inside resolveOwnerId() - and, when the marker matches, no writes at
     * all. That is cheap enough to run on EVERY access rather than once per request, which
     * is what makes it impossible to reach a store through a path that skipped the check.
     *
     * THREE OUTCOMES, and only the first is free. The marker matches, so nothing happens at
     * all. The marker disagrees, so the stores are flushed and the contact-digest salt is
     * rotated with them. Or the marker is ABSENT, so there is nothing to flush and the current
     * identity ADOPTS whatever is there. The last two both (re)establish ownership and both
     * therefore advance self::$ownershipGeneration; see the comment on the increment below for
     * why adoption has to count, and the class docblock's ADOPTION paragraph for what adoption
     * means for the session stores themselves.
     *
     * NOTE THAT THIS WRITES ON A READ PATH: getData() and contactDigest() reach here, and a
     * first access or an identity change makes it store the owner marker (and possibly seven
     * nulls). That is harmless today because these helpers and plugins are only reached from
     * POST and AJAX endpoints - checkout saves, the Capture retrieve controller, admin order
     * save and customer import - never from a cacheable GET rendered into full-page cache.
     * Plugin\ChangeCheckoutDefaultCountry DOES run on a rendered page, which is why it goes
     * through setIpCountry() and not through these accessors at all. Do not route a read-only
     * or cacheable path through getData() expecting it not to write.
     *
     * @return void
     */
    private function enforceOwnership(): void
    {
        $owner = $this->resolveOwnerId();
        $recorded = $this->session->getData(self::SESSION_OWNER_KEY);

        if (is_numeric($recorded) && (int)$recorded === $owner) {
            // Same shopper as last time: the overwhelmingly common case, and the only one
            // that writes nothing at all.
            return;
        }

        if ($recorded !== null) {
            // Either a genuine identity change, or a marker we cannot read (a corrupted
            // session payload, another module writing to the key). Both are answered the
            // same way, and that is deliberate: an unreadable marker cannot be shown to
            // belong to this shopper, and flushing costs at most a few re-verified
            // addresses, whereas trusting it risks handing one shopper another's bypass.
            //
            // Flushed by writing null rather than by unsetting: every reader of these
            // stores already degrades a non-array/falsy value to "nothing cached" or "not
            // blocked" (see Validator::getCachedVerifyResult() and its siblings,
            // Plugin\AbstractPlugin::shouldVerify(), Plugin\Frontend\PlaceOrder), and null
            // keeps this class to the two session methods - getData()/setData() - that
            // Magento\Customer\Model\Session forwards to its storage, instead of adding a
            // third that only this path would use.
            foreach (self::SHOPPER_SCOPED_SESSION_KEYS as $key) {
                $this->session->setData($key, null);
            }

            // Rotate the contact-digest salt with the stores it keys (LOQ-17149). Not part
            // of the list above because that list is also the enrolment allowlist and the
            // salt must stay unreachable through getData()/setData(). Discarding it means a
            // digest that somehow survived this flush could not be matched afterwards, so
            // the reduction does not rest on the flush alone; resolveContactDigestSalt()
            // mints a fresh one lazily on the next digest.
            $this->session->setData(self::CONTACT_DIGEST_SALT_KEY, null);
        }

        // A NEW OWNERSHIP EPOCH, counted for the flush branch above AND for the adoption that
        // falls straight through to here, because both end with ownership being (re)established
        // by a write of the marker. Counting only the flush was a defect (LOQ-17148 mutation
        // review): anything that EMPTIES the session storage mid-request - clearStorage(), the
        // destroy() inside Magento\Customer\Model\Session::logout(), another module - erases the
        // marker along with the stores, so the next access is an ADOPTION rather than a flush,
        // and a holder of derived data (see ownershipGeneration()) would read an unmoved
        // generation and go on serving the PREVIOUS identity's verdicts to the one that follows.
        // Measured on the batch path as one billable call where two are owed.
        //
        // DELIBERATELY OUTSIDE THE BRANCH ABOVE, unlike the salt rotation, which belongs inside
        // it: the salt is only stale when something was flushed under it, whereas the epoch is
        // about ownership being re-established, which the adoption does just as much as the
        // flush. Moving this line in with the rotation reinstates the defect.
        //
        // AND IT COSTS NOTHING IT SHOULD NOT: a holder that has recorded no generation yet resets
        // regardless of this counter, and what it holds then is empty. The only case that pays is
        // a storage wipe under an UNCHANGED identity, which re-earns some verdicts of the run in
        // progress - billing, not correctness, and the price the wiped stores already pay.
        $this->ownershipGeneration++;

        $this->session->setData(self::SESSION_OWNER_KEY, $owner);
    }

    /**
     * The per-session salt the contact digests are keyed with, minting one on first use.
     *
     * Generated with random_bytes(), PHP's core CSPRNG, so no new dependency is needed and
     * nothing has to be configured. Kept in its own session attribute so it lives and dies
     * with the digests it keys and is rotated with them (enforceOwnership()).
     *
     * REJECTS A SALT OF THE WRONG SHAPE rather than hashing under it: a truncated payload, an
     * empty string, or another module writing to the key would otherwise silently weaken
     * every digest in the session. Minting a replacement invalidates the digests already
     * stored, which costs one extra billable verify each and grants nothing - the safe
     * direction, and the same direction enforceOwnership() takes with an unreadable owner
     * marker.
     *
     * FAILS SOFT, NOT LOUD. random_bytes() throws when the platform has no usable CSPRNG.
     * That cannot happen on a host that can run Magento at all, but this code runs inside
     * checkout: turning a missing entropy source into a 500 on the shipping-information save
     * would be strictly worse than turning it into a cache miss. So the throw is caught and
     * '' is returned, contactDigest() propagates that sentinel, and shouldVerify() answers it
     * by performing the billable verify and storing nothing. Nothing is bypassed and nothing
     * is exposed; the merchant pays for verifications that would otherwise have been skipped.
     *
     * @return string 64 hex characters, or '' when no salt could be produced.
     */
    private function resolveContactDigestSalt(): string
    {
        $salt = $this->session->getData(self::CONTACT_DIGEST_SALT_KEY);
        if (is_string($salt) && strlen($salt) === self::CONTACT_DIGEST_LENGTH && ctype_xdigit($salt)) {
            return $salt;
        }

        try {
            $salt = bin2hex(random_bytes(self::CONTACT_DIGEST_SALT_BYTES));
        } catch (\Throwable $e) {
            // \Throwable rather than \Exception: random_bytes() answers a missing CSPRNG with
            // \Random\RandomException on PHP 8.2+ and with \Error on some earlier builds, and
            // the whole point here is not to take checkout down over it.
            return '';
        }

        $this->session->setData(self::CONTACT_DIGEST_SALT_KEY, $salt);

        return $salt;
    }

    /**
     * The store view a contact digest is namespaced by.
     *
     * Returns Helper\Data::getCurrentStore() as a string. That accessor already swallows
     * NoSuchEntityException and answers 0, so the only case left is this class having been
     * constructed WITHOUT a Data at all - which is the normal state for Helper\Controller,
     * Helper\Validator and the plugins that never compute a digest, since the argument is
     * optional precisely so they did not have to change.
     *
     * DEGRADES TO ITS OWN MARKER, not to a store id. '-' cannot be produced by
     * getCurrentStore(), which returns an int, so a digest computed with no resolvable store
     * cannot be matched by one computed under store view 0 or any other. That is the safe
     * direction: an unrecognised scope costs a re-verify and can never let a decision earned
     * under one store view's API key and prevent_submit toggle replay under another's.
     *
     * @return string A store id rendered as a decimal string, or '-' when none is available.
     */
    private function resolveStoreScope(): string
    {
        if ($this->helper === null) {
            return '-';
        }

        return (string)$this->helper->getCurrentStore();
    }

    /**
     * The customer id that owns the stores on THIS request.
     *
     * Normalised to an int so that the comparison in enforceOwnership() cannot be decided
     * by a type: Magento\Customer\Model\Session::getCustomerId() answers null for a guest
     * and, depending on how the id reached the session, an int or a numeric string for a
     * logged-in customer - and '5' !== 5 would flush a shopper's own caches on every
     * request.
     *
     * THREE DISJOINT OWNERS, and they must stay disjoint: a positive customer id, the
     * guest (self::GUEST_OWNER_ID, 0) and "unreadable" (self::UNREADABLE_OWNER_ID, -1).
     * Anything not numeric and not null is unreadable. It gets its OWN sentinel rather
     * than being folded into the guest, because folding it in made two genuinely different
     * identities compare equal: a customer whose id could not be read, followed by a
     * logout, produced owner 0 both times, so the stores were not flushed and the guest
     * inherited every bypass.
     *
     * RESIDUAL, stated honestly rather than dismissed: every unreadable id maps to the one
     * sentinel, so two successive unreadable identities would still share the stores.
     * Accepted, because getCustomerId() cannot produce one through Magento's own API - the
     * id is an int or a numeric string from the customer entity, or null - so reaching it
     * needs another module writing a non-numeric value into the customer session's
     * 'customer_id'. The collision that mattered, unreadable-versus-guest, is now
     * structurally impossible: -1 is not 0 and cannot be a positive auto-increment id.
     *
     * @return int A positive customer id, self::GUEST_OWNER_ID when nobody is logged in,
     *             or self::UNREADABLE_OWNER_ID when the session answered something else.
     */
    private function resolveOwnerId(): int
    {
        $customerId = $this->session->getCustomerId();

        if ($customerId === null) {
            return self::GUEST_OWNER_ID;
        }

        return is_numeric($customerId) ? (int)$customerId : self::UNREADABLE_OWNER_ID;
    }
}
