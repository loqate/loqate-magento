<?php

namespace Loqate\ApiIntegration\Helper;

use Magento\Customer\Model\Session;

/**
 * The single gateway to every ADDRESS store this module keeps in the customer session,
 * and the one place that enforces those stores belong to ONE shopper (LOQ-16978).
 *
 * WHY THIS EXISTS AT ALL. Three session attributes hold ADDRESS data that lets an address
 * SKIP a billable Loqate verify - self::CAPTURED_ADDRESSES_SESSION_KEY (the capture
 * bypass), self::VERIFY_CACHE_SESSION_KEY and self::BATCH_VERIFY_CACHE_SESSION_KEY (the
 * LOQ-16969/LOQ-16976 verdict caches).
 * A PHP session outlives a login: Magento calls session_regenerate_id() on login and on
 * logout, and that changes the session ID while PRESERVING every value in $_SESSION. So
 * on a shared browser - a family device, a public terminal, a click-and-collect kiosk -
 * shopper B could inherit shopper A's bypasses and check out an address that was never
 * verified against B's own submission. Nothing in Magento clears third-party session
 * attributes on an identity change, so this class does it for the three ADDRESS stores
 * listed in self::SHOPPER_SCOPED_SESSION_KEYS. It is NOT a general answer for every
 * session attribute this module writes - see ADDING A FOURTH STORE below for the siblings
 * it deliberately does not cover.
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
 * to this class and Controller/Validator no longer hold a reference to it.
 *
 * WHY A COLLABORATOR RATHER THAN A COPY IN EACH CLASS. The flush has to be identical in
 * all three stores or it is worthless - flushing two of them still leaves the third
 * granting B a bypass earned by A - and it is the kind of rule that rots when duplicated.
 * It is constructed in Helper\Controller's and Helper\Validator's constructors from the
 * customer Session they are already given, rather than injected, so neither class needs a
 * new REQUIRED constructor argument (a break for anything extending them) and no DI
 * wiring changes; both of those classes already build collaborators inline the same way
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
 * ShopperScopedAddressStoresTest's assertion on the flush list - and the attribute VALUES are
 * unchanged, so the four test files that pin these names as literals keep passing and no
 * live session loses its stores at deploy time.
 *
 * ADOPTION, stated so it is not mistaken for a hole. When NOTHING has been recorded yet,
 * the current identity ADOPTS whatever is in the stores instead of flushing it. That case
 * is reachable only for data written BEFORE this class existed, because from now on the
 * marker is written on the first access of a session, long before anything can be stored.
 * Adopted data was, by definition, written in THIS session - though not necessarily by
 * whoever is at the browser NOW: a session that was already live at deploy time, in which
 * shopper A logged out before the module's first post-release access, lands in the
 * adoption branch and hands A's stores to the guest that follows. That is accepted rather
 * than defended against, on three grounds: it is exactly the pre-change behaviour, so it
 * is not a regression this ticket introduces; it is bounded to one release and to sessions
 * that were mid-flight during it; and it closes the moment ANY identity change is observed
 * after the marker is written. The alternative - flushing on first access - would buy
 * nothing for every session started after the deploy and would throw away every live
 * shopper's capture bypass at deploy time.
 *
 * ACCEPTED LIMITS, stated (the style of Validator::verifyMultipleAddresses()):
 *  - THE GUARD SCOPES BY CUSTOMER IDENTITY ONLY. The marker tracks
 *    Magento\Customer\Model\Session::getCustomerId() and nothing else. An ADMIN user swap
 *    inside one browser session is therefore NOT covered, and that is not academic:
 *    self::BATCH_VERIFY_CACHE_SESSION_KEY is written exclusively from adminhtml
 *    (Plugin\Admin\OrderSave, Plugin\Admin\ValidateImportAddress), where the customer
 *    session normally holds no customer id at all - so the owner there is permanently
 *    self::GUEST_OWNER_ID and the flush is a no-op for that path.
 *    WHAT THAT ACTUALLY COSTS, stated precisely so nobody "fixes" a non-defect: it is a
 *    BILLING consequence, not a data-exposure one. If two admins share one browser session,
 *    the second can be served a batch verdict the first paid for. That verdict is not the
 *    first admin's judgement leaking into the second's: only PASSES are stored
 *    (storeBatchVerifyResult() caches nothing else) and Validator::buildBatchVerifyCacheKey()
 *    already namespaces every entry by store view and by a fingerprint of the configured AQI
 *    threshold, so a replayed entry is a pure function of (address, store view, threshold) -
 *    exactly the verdict the second admin would have earned by submitting that address
 *    themselves. What is shared is the billable call, and saving billable calls is what the
 *    cache is FOR. The backend auth session (Magento\Backend\Model\Auth\Session) is therefore
 *    deliberately NOT injected to scope it: doing so would drag a backend dependency into a
 *    helper that is constructed on every frontend checkout request, and the only thing it
 *    would change is that the merchant pays a second time for a verdict that is identical
 *    by construction. The shared-browser risk this ticket exists to close is a
 *    SHOPPER-facing one and is fully covered.
 *  - ALL UNREADABLE CUSTOMER IDS COLLAPSE ONTO ONE OWNER, see resolveOwnerId(). Two
 *    successive identities that both present an unreadable id would share the stores. It
 *    cannot collide with a guest or with a real customer, which is the collision that
 *    mattered.
 *  - CONCURRENCY: reading the marker, flushing and rewriting it are not atomic, exactly as
 *    on the two caches this protects (see Validator::verifyAddress()). Two concurrent
 *    requests straddling a login can both observe the old marker; the loser re-verifies,
 *    which costs a billable call and grants nothing.
 *
 * ADDING A FOURTH STORE: list it in self::SHOPPER_SCOPED_SESSION_KEYS. Reading or writing
 * an attribute through this class does NOT enrol it in the flush; only that list does, and
 * getData()/setData() now REJECT any key missing from it so that an un-enrolled attribute
 * cannot quietly acquire the guard's appearance without its protection.
 *
 * THE MODULE HAS OTHER SESSION ATTRIBUTES, and they are NOT enrolled here - named so the
 * next reader does not conclude there are only three. Out of scope for LOQ-16978, which is
 * about the ADDRESS stores; each needs its own assessment before it is added:
 *  - 'loqate_email' and 'loqate_phone', written by Plugin\AbstractPlugin::shouldVerify().
 *    Unbounded lists of raw email addresses and phone numbers that skip a billable
 *    verifyEmail()/verifyPhoneNumber(), so they are bypasses of the same kind AND hold PII;
 *  - 'loqate_email_to_validate' (Plugin\Frontend\AccountManagement,
 *    Plugin\Frontend\CheckoutShippingInformation) - one pending email address;
 *  - 'loqate_billing_errors' (Plugin\Frontend\CheckoutBillingAddress, read by
 *    Plugin\Frontend\PlaceOrder and PlaceOrderGuest) - a boolean gate on placing an order.
 */
class ShopperScopedAddressStores
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
     * Session attribute recording which customer the stores below belong to.
     *
     * A SIBLING attribute rather than a member inside each store: the three stores have
     * three different shapes (a list of serialised addresses, two key => serialised-verdict
     * maps), every reader of them is defensive about that shape, and wrapping them would
     * mean changing all three readers to unwrap an owner they must never trust anyway.
     * One attribute also means one place to compare and one place to write.
     */
    private const SESSION_OWNER_KEY = 'loqate_session_cache_owner';

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
     * out of that state did not flush and the guest that followed inherited all three
     * bypass stores, which is precisely the hand-off this class exists to stop.
     *
     * @see self::resolveOwnerId() for what remains uncovered, and why that is accepted.
     */
    private const UNREADABLE_OWNER_ID = -1;

    /**
     * Every session attribute owned by one shopper, flushed together on an identity change.
     *
     * All three are verify BYPASSES, which is why they share a lifetime: a stale entry in
     * any one of them lets an address through without the billable Cleansing call that
     * would have judged it for THIS shopper. This is also the ENROLMENT list, not merely a
     * flush list - getData()/setData() refuse any key that is not on it, so "reachable
     * through this class" and "flushed by this class" cannot drift apart.
     */
    private const SHOPPER_SCOPED_SESSION_KEYS = [
        self::CAPTURED_ADDRESSES_SESSION_KEY,
        self::VERIFY_CACHE_SESSION_KEY,
        self::BATCH_VERIFY_CACHE_SESSION_KEY,
    ];

    /** @var Session Raw customer session; deliberately private, see the class docblock. */
    private $session;

    /**
     * @param Session $session The per-shopper customer session the stores live in.
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
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
     * Refuse any attribute that is not enrolled in the flush.
     *
     * WHY THIS IS AN ASSERTION AND NOT A COMMENT. Without it, reading a new attribute
     * through this class LOOKS guarded - the ownership check runs, the call site is
     * identical to the three that are protected - while the attribute is never actually
     * flushed, silently keeping the defect LOQ-16978 was written to close. That is the
     * same class of trap Validator::BATCH_VERIFY_CACHE_SESSION_KEY answers with two
     * physically separate attributes rather than a prefix: make it structurally
     * impossible rather than merely unlikely.
     *
     * WHY A THROW IS SAFE HERE, despite running inside checkout. It is unreachable for
     * correct code. Every production call site passes one of the three constants declared
     * on this class - ten statements in all: Controller::storeCapturedAddress() reads and
     * writes the captured store, Validator::verifyAddress() and
     * Validator::verifyMultipleAddresses() read it, and Validator's four verdict-cache
     * accessors account for the remaining six. No instance HELD BY THIS MODULE is reachable
     * from outside it either: both holders keep it in a PRIVATE property and it is never
     * placed in DI. (The class and its constructor are public, so anything can construct
     * its OWN instance - that is a separate object over the same session and cannot reach
     * an unenrolled key through these guards any more than this code can.) So the only way
     * to trip this is a NEW call inside this module passing an unenrolled key, which is a
     * programming error that must fail at the developer's first request rather than ship as
     * a silent bypass. ONE PATH SOFTENS THAT, named so it is not read as a claim the code
     * does not honour: Plugin\Admin\ValidateImportAddress::afterValidateData() wraps its
     * work in catch (\Exception) and returns the result untouched, with no log - and that
     * catch sits OUTSIDE the chunk loop, so the throw abandons the remaining chunks and the
     * error-reporting loop with them. On that path the mistake therefore does not reach the
     * developer as an exception; it surfaces as an import that reports no address errors at
     * all. That is still a total failure of the import's address validation rather than an
     * unguarded read - no store is reached and no row is granted a bypass - so the assertion
     * is not a bypass anywhere, merely quieter here than the throw suggests. Logging and
     * continuing was the alternative and was rejected: the continue path IS the unguarded
     * access the assertion exists to prevent, so it would leave the defect in place and
     * merely record it - and this class holds no logger to record it with.
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
            'Session attribute "%s" is not enrolled in ShopperScopedAddressStores::'
            . 'SHOPPER_SCOPED_SESSION_KEYS, so it would be reached through the shopper-ownership guard '
            . 'without ever being flushed when the shopper changes (LOQ-16978). Add it to that list, or '
            . 'use the raw customer session if the attribute genuinely is not shopper-scoped.',
            $key
        ));
    }

    /**
     * Flush every shopper-scoped store if the logged-in identity is not the one they were
     * written for, then record the current identity as their owner.
     *
     * Costs TWO session reads on the hot path - the ownership marker here, and the
     * customer id inside resolveOwnerId() - and, when the marker matches, no writes at
     * all. That is cheap enough to run on EVERY access rather than once per request, which
     * is what makes it impossible to reach a store through a path that skipped the check.
     *
     * NOTE THAT THIS WRITES ON A READ PATH: getData() reaches here, and a first access or
     * an identity change makes it store the owner marker (and possibly three nulls). That
     * is harmless today because these helpers are only reached from POST and AJAX
     * endpoints - checkout saves, the Capture retrieve controller, admin order save and
     * customer import - never from a cacheable GET rendered into full-page cache. Do not
     * route a read-only or cacheable path through getData() expecting it not to write.
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
            // three stores already degrades a non-array/falsy value to "nothing cached"
            // (see Validator::getCachedVerifyResult() and its siblings), and null keeps
            // this class to the two session methods - getData()/setData() - that
            // Magento\Customer\Model\Session forwards to its storage, instead of adding a
            // third that only this path would use.
            foreach (self::SHOPPER_SCOPED_SESSION_KEYS as $key) {
                $this->session->setData($key, null);
            }
        }

        $this->session->setData(self::SESSION_OWNER_KEY, $owner);
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
     * inherited all three bypasses.
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
