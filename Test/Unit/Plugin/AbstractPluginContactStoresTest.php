<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use ArrayObject;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Loqate\ApiIntegration\Test\Support\Csprng;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the two contact bypass stores Plugin\AbstractPlugin owns - 'loqate_email' and
 * 'loqate_phone' - and for the three properties LOQ-17149 gave them: BOUNDED, HASHED and
 * FLUSHED on a shopper change.
 *
 * WHAT THESE STORES ACTUALLY DO, because it decides what these tests have to assert. Both
 * prevent_submit toggles default to 0 (etc/config.xml), and on that shipped default
 * validateEmail()/validatePhone() consult the store BEFORE calling Loqate: a match returns
 * false, which means no billable verifyEmail()/verifyPhoneNumber() AND no warning. So an
 * entry is not merely a saving, it is a licence to submit an unverified email address or phone
 * number silently. Every test below is therefore driven through validateEmail()/validatePhone()
 * and counts the BILLABLE CALLS, not through shouldVerify()'s return value: a bypass is only
 * observable as a call that did not happen.
 *
 * EVERY FIXTURE GOES THROUGH THE REAL CONSTRUCTOR. AbstractPlugin builds its
 * ShopperScopedSessionStores inline from the injected Session, so a fixture that injected the
 * seam by reflection would be asserting the test's own wiring rather than production's - and
 * the wiring is precisely what LOQ-17149 changed. That is what the three new stubs under
 * Test/stubs (App\Action\Context, UrlInterface, Controller\Result\JsonFactory) exist for; none
 * of the values they carry is read.
 */
class AbstractPluginContactStoresTest extends TestCase
{
    // The session double, the configuration helper and the counting Validator, shared with the
    // plugin-level tests in this namespace rather than copied into each of them: three copies of
    // a double is three places for one of them to stop resembling the real Session.
    use ShopperSessionHarness;

    /** Session attribute the email bypass list lives under. */
    private const VERIFIED_EMAIL_SESSION_KEY = 'loqate_email';

    /** Session attribute the phone bypass list lives under. */
    private const VERIFIED_PHONE_SESSION_KEY = 'loqate_phone';

    /** An address that is not a numeric string, so the loose/strict distinction cannot bite. */
    private const EMAIL = 'shopper.a@example.com';

    /** A phone number, likewise well clear of the numeric-string collision below. */
    private const PHONE = '+44 20 7946 0000';

    /**
     * The largest number of contact digests per store this module could defend keeping.
     *
     * 50, which is Helper\Controller::CAPTURED_ADDRESSES_LIMIT's shipped value - the bound on
     * the OTHER store a shopper fills interactively - because
     * ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT's own docblock says it is deliberately
     * SMALLER than its siblings: no import writes these two lists, and one interactive checkout
     * presents one email address and at most two phone numbers. Asserted as "no larger than"
     * rather than as the exact figure, so tuning 25 up or down within what a session can carry
     * is not a test failure while abandoning the bound is.
     *
     * A LITERAL RATHER THAN THAT CONSTANT, deliberately. Deriving the ceiling from a sibling
     * constant means raising the SIBLING silently relaxes this one, and the two are not the same
     * decision: the captured-address store holds addresses a shopper picked from a lookup, these
     * hold digests of contact details. A ceiling that moves when something else moves is not a
     * ceiling. If Controller::CAPTURED_ADDRESSES_LIMIT and this figure should stay equal, that is
     * an argument for saying so here in prose - which this does - rather than for coupling them.
     */
    private const LARGEST_DEFENSIBLE_CONTACT_LIMIT = 50;

    /**
     * The fewest contact digests per store this module can actually function on.
     *
     * NOT DECORATION, AND NOT AN ARBITRARY FLOOR: below this the "warned once, submit again"
     * contract stops terminating, and it does so silently. ONE SUBMISSION CAN CARRY TWO DISTINCT
     * PHONE NUMBERS - Plugin\Admin\OrderSave loops every address on the order, and a checkout
     * writes the shipping number from Plugin\Frontend\CheckoutShippingInformation and the billing
     * one from CheckoutBillingAddress - so with a limit of 2 the store is exactly full after one
     * pass, and the moment a THIRD value is alive in the session (a corrected number, a second
     * order) each submission evicts the entry the next one needs. With both prevent_submit
     * toggles off, which is the shipped default, that is not a re-verify: the shopper is warned
     * about a value they have already resubmitted, every time, and can never get through. 3 is
     * the smallest figure that survives one correction; the shipped value is 25.
     */
    private const SMALLEST_WORKABLE_CONTACT_LIMIT = 3;

    /**
     * Characters one stored digest occupies: hash_hmac('sha256', ...) in lowercase hex.
     *
     * Used to state the worst case both stores can add to a session payload as a SIZE rather
     * than as an entry count, because the size is the thing that has a consequence - a PHP
     * session is read and written whole on every request that touches it.
     */
    private const DIGEST_CHARACTERS = 64;

    /**
     * The most the two contact stores may add to one session payload, in characters.
     *
     * 8 kB, which is generous against the ~3 kB the shipped limit actually costs
     * (2 stores x 25 entries x 64 characters) and is still far below the point at which the
     * session becomes a problem to carry. The figure is a ceiling on the DECISION, not a
     * prediction of the value.
     */
    private const LARGEST_DEFENSIBLE_CONTACT_PAYLOAD = 8192;

    /**
     * A stored email address must never be readable out of the session again.
     *
     * THE REASON THIS IS THE FIRST TEST. These two attributes are pure COMPARISON stores -
     * shouldVerify() only ever asks "have I seen this before?" - so they never needed to hold
     * the value, and what they were holding was an unbounded list of the customer's email
     * addresses and phone numbers for the whole life of the session. The assertion is over the
     * WHOLE session payload rather than over the one attribute, because a digest in the store
     * plus the raw value in a sibling attribute would be no reduction at all.
     */
    public function testNeitherTheEmailNorThePhoneIsEverStoredInTheSession(): void
    {
        $harness = $this->createPlugin();

        $harness['plugin']->checkEmail(self::EMAIL);
        $harness['plugin']->checkPhone(self::PHONE);

        $payload = json_encode(iterator_to_array($harness['session']));

        $this->assertStringNotContainsString(
            self::EMAIL,
            $payload,
            'The email address must not appear anywhere in the session. The store only ever COMPARES, so it '
            . 'has no reason to hold the value, and holding it kept the customer\'s address in the session '
            . 'for the whole of its life (LOQ-17149).'
        );
        $this->assertStringNotContainsString(
            self::PHONE,
            $payload,
            'The phone number must not appear anywhere in the session, for the same reason as the address.'
        );
        $this->assertNotSame(
            [],
            $harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? [],
            'Something must have been stored, or the two assertions above would pass on an empty store and '
            . 'prove nothing.'
        );
    }

    /**
     * The behaviour that must SURVIVE the hashing: one value is verified once, and the
     * resubmission is allowed without a second billable call.
     *
     * The mirror of the test above and just as load-bearing. A digest that never matched
     * anything would satisfy every PII assertion in this file while re-billing every
     * submission and re-warning every shopper - and it would do so with the suite green,
     * because "the bypass never fires" is invisible to a test that only looks for
     * over-sharing.
     */
    public function testTheSameEmailIsBilledOnceAndTheResubmissionIsAllowed(): void
    {
        $harness = $this->createPlugin();

        $this->assertFalse(
            $harness['plugin']->checkEmail(self::EMAIL),
            'The first submission is verified and the stub connector accepts it, so no error is reported.'
        );
        $this->assertSame(1, $this->emailCalls($harness), 'The first submission must be billed.');

        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertSame(
            1,
            $this->emailCalls($harness),
            'The resubmission of the same address must be recognised by its digest and skip the billable '
            . 'verify. If this fails, the hashing has broken the bypass LOQ-16969 and this module\'s '
            . '"submit again to use this address" behaviour both depend on.'
        );
    }

    /**
     * The one place the new comparison is deliberately NARROWER than the old one, and it is a
     * FIX rather than a regression.
     *
     * shouldVerify() compared with a LOOSE in_array(). PHP 8 compares two numeric strings
     * NUMERICALLY, so '0123456789' == '123456789' - and '+4412345' == '4412345', and
     * '0044123' == '44123' - were all true, which means the second number of each pair was
     * accepted with no verification and no warning on the strength of the first. They are
     * different phone numbers. The premise is asserted on the fixture rather than described,
     * so this test cannot pass for the wrong reason if PHP's comparison rules ever change.
     *
     * @param string $first Number the session has already been warned about.
     * @param string $second A DIFFERENT number PHP's loose comparison equated with it.
     */
    #[DataProvider('numericStringPhoneCollisionProvider')]
    public function testTwoDifferentPhoneNumbersPhpComparedAsEqualAreNowEachVerified(
        string $first,
        string $second
    ): void {
        $this->assertTrue(
            // @phpstan-ignore-next-line - the loose comparison IS the premise under test.
            $first == $second,
            'Fixture guard: these two numbers must be values PHP\'s loose comparison treats as EQUAL, or this '
            . 'test is not exercising the collision it exists for.'
        );
        $this->assertNotSame($first, $second, 'Fixture guard: they must be genuinely different numbers.');

        $harness = $this->createPlugin();

        $harness['plugin']->checkPhone($first);
        $harness['plugin']->checkPhone($second);

        $this->assertSame(
            2,
            $this->phoneCalls($harness),
            'Two different phone numbers must each be verified. Under the old loose in_array() the second was '
            . 'skipped - no billable call and, on the shipped default configuration, no warning either - '
            . 'because PHP compared two numeric strings as numbers. The digest compares the string, so this is '
            . 'now two entries. Merchant-visible: one extra billable verify in this edge case, recorded in '
            . 'CHANGELOG.md.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function numericStringPhoneCollisionProvider(): array
    {
        return [
            'a leading zero' => ['123456789', '0123456789'],
            'a country code written with and without +' => ['4412345', '+4412345'],
            'an international prefix written 00 and bare' => ['44123', '0044123'],
        ];
    }

    /**
     * The bound, and the direction eviction takes.
     *
     * Driven from the production constant, so raising or lowering it does not silently leave
     * this test asserting a number the module no longer uses. What is asserted is the
     * PROPERTY: the store never exceeds the limit, the newest entry always survives (it is the
     * value being submitted right now), and the OLDEST is what goes - so an eviction can only
     * ever cost a re-verify of a value the shopper has moved on from.
     */
    public function testTheBypassListIsBoundedAndEvictsTheOldestFirst(): void
    {
        $limit = $this->contactLimit();
        $harness = $this->createPlugin();

        // One more distinct address than the store can hold.
        for ($i = 0; $i <= $limit; $i++) {
            $harness['plugin']->checkEmail(sprintf('shopper+%d@example.com', $i));
        }

        $stored = $harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? [];

        $this->assertLessThanOrEqual(
            $limit,
            count($stored),
            sprintf(
                'The store must never exceed ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT (%d). It was '
                . 'an unbounded append-only list for the whole session before LOQ-17149.',
                $limit
            )
        );
        $this->assertSame(
            array_values($stored),
            $stored,
            'The store must stay a LIST: array_shift() renumbers, and a sparse array no longer round-trips '
            . 'through the session payload as a JSON list.'
        );

        // The newest value is still recognised - it can never be the entry evicted...
        $callsBefore = $this->emailCalls($harness);
        $harness['plugin']->checkEmail(sprintf('shopper+%d@example.com', $limit));
        $this->assertSame(
            $callsBefore,
            $this->emailCalls($harness),
            'The most recently submitted value must survive eviction: it is the one being checked out, so '
            . 'evicting it would re-bill and re-warn the shopper mid-checkout.'
        );

        // ...and the OLDEST is the one that went.
        $harness['plugin']->checkEmail('shopper+0@example.com');
        $this->assertSame(
            $callsBefore + 1,
            $this->emailCalls($harness),
            'Eviction must be FIFO - oldest first - matching Controller::storeCapturedAddress() and '
            . 'Validator::storeVerifyResult(). Evicting anything else would risk dropping the value the '
            . 'shopper is currently submitting.'
        );
    }

    /**
     * The bound has to BE a bound: one shopper's session can never carry more than a few
     * kilobytes of contact digests, whatever the limit is tuned to.
     *
     * WHY THE FIFO TEST ABOVE DOES NOT ALREADY SAY THIS, which is the whole reason this one
     * exists. That test reads VERIFIED_CONTACT_LIMIT from production and asserts the store
     * never exceeds it - so it is true of 25 and equally true of 100000, and a limit raised to
     * a number that is not a bound at all passes it (or, worse, exhausts the process's memory
     * while filling the store, which is a crash and not a verdict). What has to be pinned is
     * the DECISION that these two lists stay small, and the reason it was taken: they are
     * append-only for the life of a session, they are never refreshed on a hit, and the session
     * they live in is read and rewritten whole on every request that touches it. An unbounded
     * or effectively-unbounded list is what LOQ-17149 found and is what this asserts against.
     */
    public function testTheTwoContactStoresCannotGrowTheSessionPayloadWithoutBound(): void
    {
        $limit = $this->contactLimit();

        $this->assertLessThanOrEqual(
            self::LARGEST_DEFENSIBLE_CONTACT_LIMIT,
            $limit,
            sprintf(
                'Each contact store must hold no more entries than the captured-address store (%d). These two '
                . 'are the SMALLEST of the module\'s session stores by design: no import writes them, and one '
                . 'interactive checkout presents one email address and at most two phone numbers, so a limit '
                . 'above the address stores\' would be keeping more of the customer\'s contact details, for '
                . 'longer, than anything asks for.',
                self::LARGEST_DEFENSIBLE_CONTACT_LIMIT
            )
        );
        $this->assertLessThanOrEqual(
            self::LARGEST_DEFENSIBLE_CONTACT_PAYLOAD,
            2 * $limit * self::DIGEST_CHARACTERS,
            sprintf(
                'The two contact stores together must not be able to add more than %d characters to a session '
                . 'payload (2 stores x %d entries x %d characters). The session is read and rewritten whole on '
                . 'every request that touches it, so "the list is bounded" is only worth anything if the bound '
                . 'is a size a session can carry.',
                self::LARGEST_DEFENSIBLE_CONTACT_PAYLOAD,
                $limit,
                self::DIGEST_CHARACTERS
            )
        );

        // ...and the bound is real rather than arithmetic: filling one store past it leaves a
        // payload inside the ceiling asserted above.
        $harness = $this->createPlugin();
        for ($i = 0; $i <= $limit; $i++) {
            $harness['plugin']->checkEmail(sprintf('shopper+%d@example.com', $i));
            $harness['plugin']->checkPhone(sprintf('+44 20 7946 %04d', $i));
        }

        $stored = count((array)($harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? []))
            + count((array)($harness['session'][self::VERIFIED_PHONE_SESSION_KEY] ?? []));

        $this->assertLessThanOrEqual(
            2 * $limit,
            $stored,
            'Submitting more distinct values than the bound must not grow the stores past it. Each list is '
            . 'bounded SEPARATELY, so the session holds at most twice the limit in digests however the '
            . 'submissions are split between an email address and a phone number.'
        );
    }

    /**
     * No plugin can reach a shopper-scoped store without the ownership guard, whatever it
     * chooses to do with the session it is handed.
     *
     * THIS IS THE HOLE LOQ-17149 HAD TO CLOSE, and it is invisible to every behavioural test in
     * this file. Plugin\AbstractPlugin is the base class of TEN plugins, four of which reach
     * these stores; while its raw Magento\Customer\Model\Session was `protected`, any of those
     * ten - and any third-party subclass - could read or write 'loqate_email',
     * 'loqate_phone', 'loqate_email_to_validate' or 'loqate_billing_errors' directly, with no
     * flush on a shopper change, no bound and no hashing. The tests above would all still pass,
     * because they exercise the paths that DO go through the seam.
     *
     * Asserted on a plugin built through the REAL constructor, by VALUE rather than by declared
     * type, so it holds however the property is written: an untyped `protected $session`
     * re-introduced with a docblock is caught exactly as a typed one is. The seam itself is
     * covered by the same rule for the same reason - a protected ShopperScopedSessionStores
     * would hand all seven enrolled stores to ten subclasses, which is wider than any of them
     * needs and wider than the named accessors this class exposes.
     *
     * AND THE SAME RULE OVER METHODS, which is the half a property-only check misses. A
     * `protected function session(): Session` on AbstractPlugin re-opens the hole exactly as a
     * protected property does - the ten subclasses get the raw session back - while leaving
     * every property private and this test green. That is the same class of miss as the
     * survivor LOQ-17149 was written to remove, so it is closed here rather than left to be
     * found again: no non-private member of the class, of either kind, may hand out a Session
     * or a ShopperScopedSessionStores.
     */
    public function testNoPluginCanReachAShopperScopedStoreWithoutTheOwnershipGuard(): void
    {
        $harness = $this->createPlugin();
        $found = [];

        foreach ($this->declaredProperties($harness['plugin']) as $property) {
            $property->setAccessible(true);
            if (!$property->isInitialized($harness['plugin'])) {
                continue;
            }
            $value = $property->getValue($harness['plugin']);

            if (!is_a($value, Session::class) && !$value instanceof ShopperScopedSessionStores) {
                continue;
            }

            $found[] = is_a($value, Session::class) ? 'session' : 'seam';
            $this->assertTrue(
                $property->isPrivate(),
                sprintf(
                    '%s::$%s must be PRIVATE. It holds %s, and anything a subclass can reach is a way past the '
                    . 'shopper-ownership guard: ten plugins extend this class, four of them reach the contact '
                    . 'bypass lists, the pending email address or the billing-error gate, and a `protected` '
                    . 'one lets any of them - or a third-party subclass - read and write those attributes '
                    . 'with no flush when the shopper changes, no bound and no hashing. That is exactly the '
                    . 'state LOQ-17149 found and had to close.',
                    $property->getDeclaringClass()->getShortName(),
                    $property->getName(),
                    is_a($value, Session::class) ? 'the raw customer session' : 'the session-store seam'
                )
            );
        }

        $this->assertEqualsCanonicalizing(
            ['session', 'seam'],
            array_values(array_unique($found)),
            'Fixture guard: the plugin must really hold both a raw customer session and a '
            . 'ShopperScopedSessionStores, or the visibility assertions above passed over an empty list and '
            . 'proved nothing. Canonicalizing because declaredProperties() walks in DECLARATION order, and '
            . 'swapping the two property declarations on AbstractPlugin is not a defect - failing here for '
            . 'that would report a hole in the guard that does not exist.'
        );

        $this->assertSame(
            [],
            $this->membersHandingOutTheSession($harness['plugin']),
            'A non-private METHOD that hands out the raw session or the seam is the same hole as a non-private '
            . 'property, and it is the half a check over properties alone does not see: ten plugins extend this '
            . 'class, so any of them could then reach the contact bypass lists, the pending email address or '
            . 'the billing-error gate with no flush, no bound and no hashing. Expose a NARROW named accessor '
            . 'for the one thing the subclass needs instead, the way shouldVerify() and pendingEmailAddress() '
            . 'do.'
        );
    }

    /**
     * Every non-private method of a plugin that hands a caller the raw session or the seam.
     *
     * TWO WAYS OF LOOKING, because either alone leaves the hole open. A declared return type is
     * read from reflection, which catches `protected function session(): Session` without
     * running anything. An UNTYPED accessor - the same method with the type in a docblock, which
     * is this module's prevailing style - is caught by actually calling every non-private method
     * that needs no arguments and looking at what comes back. Anything that throws is skipped:
     * it did not return a session.
     *
     * @param object $plugin Built through the real constructor, so the values are production's.
     * @return string[] One sentence per offender, empty when there are none.
     */
    private function membersHandingOutTheSession(object $plugin): array
    {
        $exposed = [];
        for ($class = new ReflectionClass($plugin); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getMethods() as $method) {
                if ($method->isPrivate() || $method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $label = sprintf('%s::%s()', $method->getDeclaringClass()->getShortName(), $method->getName());
                $returns = $method->getReturnType();
                if ($returns instanceof \ReflectionNamedType
                    && in_array($returns->getName(), [Session::class, ShopperScopedSessionStores::class], true)
                ) {
                    $exposed[$label] = sprintf('%s declares it returns %s', $label, $returns->getName());
                    continue;
                }

                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }

                try {
                    $value = $method->invoke($plugin);
                } catch (\Throwable $exception) {
                    continue;
                }

                if (is_a($value, Session::class) || $value instanceof ShopperScopedSessionStores) {
                    $exposed[$label] = sprintf('%s returned a %s', $label, get_class($value));
                }
            }
        }

        return array_values($exposed);
    }

    /**
     * Every property a plugin holds, its own and its parents', as reflection objects.
     *
     * @param object $plugin
     * @return \ReflectionProperty[]
     */
    private function declaredProperties(object $plugin): array
    {
        $properties = [];
        for ($class = new ReflectionClass($plugin); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if (!isset($properties[$property->getName()])) {
                    $properties[$property->getName()] = $property;
                }
            }
        }

        return array_values($properties);
    }

    /**
     * The defect LOQ-17149 exists to close, at the layer that bills: a login must cost the
     * incoming shopper their predecessor's bypass.
     *
     * session_regenerate_id() preserves session data, so without the flush shopper B's first
     * submission of an address A had been warned about is accepted with no verification and no
     * warning at all.
     */
    public function testAnEmailBypassDoesNotSurviveALogin(): void
    {
        $harness = $this->createPlugin();

        $harness['plugin']->checkEmail(self::EMAIL);
        $this->assertSame(1, $this->emailCalls($harness), 'Shopper A\'s submission is billed.');

        // Somebody signs in on the same browser, which changes the identity and nothing else.
        $harness['identity']['customerId'] = 42;
        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertSame(
            2,
            $this->emailCalls($harness),
            'The bypass must NOT survive the identity change. Inheriting it means the new shopper\'s address '
            . 'is accepted with no verification and no warning, on the strength of a warning the PREVIOUS '
            . 'person at this browser dismissed.'
        );
        $this->assertSame(
            [],
            array_diff(
                (array)($harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? []),
                [$this->soleStoredDigest($harness, self::VERIFIED_EMAIL_SESSION_KEY)]
            ),
            'After the flush the store must hold ONLY the new shopper\'s own entry, not theirs plus the '
            . 'previous shopper\'s.'
        );
    }

    /**
     * On a host with no usable CSPRNG, every submission is checked against Loqate and nothing
     * is written to the session - the merchant pays, and no bypass is granted.
     *
     * THE DIRECTION IS THE WHOLE POINT, and it is the direction that costs money rather than
     * the one that costs a customer. Without a salt there is no digest that is worth anything:
     * hash_hmac() would accept an empty key and hand back a well-formed 64-character value that
     * is the SAME in every session on every installation, so it would both identify the address
     * globally and let one session's entry answer another's lookup. The class refuses to
     * produce it, shouldVerify() answers the refusal by verifying, and the store stays empty.
     * That is one extra billable call per submission on a host that cannot generate entropy,
     * which cannot happen on anything that can run Magento - and if it ever does, an extra
     * verify is the failure worth having.
     *
     * This is asserted at the PLUGIN layer as well as on the seam because the seam only returns
     * a sentinel: what turns that sentinel into "verify and store nothing" is shouldVerify(),
     * and a caller that treated '' as a cache key would put every value into one shared slot.
     */
    public function testWithNoUsableCsprngEverySubmissionIsVerifiedAndNothingIsRemembered(): void
    {
        $harness = $this->createPlugin();

        Csprng::failing(function () use ($harness): void {
            $harness['plugin']->checkEmail(self::EMAIL);
            $harness['plugin']->checkEmail(self::EMAIL);
            $harness['plugin']->checkPhone(self::PHONE);
            $harness['plugin']->checkPhone(self::PHONE);
        });

        $this->assertSame(
            2,
            $this->emailCalls($harness),
            'With no salt to key the digest with, the SECOND submission of the same address must be verified '
            . 'again. Skipping it would mean a bypass was granted on the strength of an entry stored under an '
            . 'empty key - a value that is identical in every session on every installation.'
        );
        $this->assertSame(2, $this->phoneCalls($harness), 'The same holds for the phone number.');
        $this->assertSame(
            [],
            (array)($harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? []),
            'Nothing may be stored when no usable digest can be produced: an unsalted entry is a global '
            . 'identifier for the customer\'s address AND a bypass any other session could present.'
        );
        $this->assertSame(
            [],
            (array)($harness['session'][self::VERIFIED_PHONE_SESSION_KEY] ?? []),
            'And nothing may be stored for the phone number, for the same reason.'
        );
        $this->assertStringNotContainsString(
            self::EMAIL,
            json_encode(iterator_to_array($harness['session'])),
            'The address must not appear anywhere in the session either. "No digest could be produced" must '
            . 'never degrade into "keep the raw value instead".'
        );
    }

    /**
     * The salt is rotated with the flush, so a digest that somehow outlived it could not be
     * matched anyway.
     *
     * Belt and braces on purpose: the reduction must not rest on the flush alone, because the
     * flush is the thing a future edit is most likely to break.
     */
    public function testTheDigestSaltIsRotatedWhenTheShopperChanges(): void
    {
        $harness = $this->createPlugin();
        $saltKey = $this->saltKey();

        $harness['plugin']->checkEmail(self::EMAIL);
        $firstSalt = $harness['session'][$saltKey] ?? null;

        $this->assertIsString($firstSalt, 'A salt must have been minted for the first digest.');

        $harness['identity']['customerId'] = 42;
        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertIsString($harness['session'][$saltKey] ?? null, 'The new shopper needs a salt too.');
        $this->assertNotSame(
            $firstSalt,
            $harness['session'][$saltKey],
            'The salt must be rotated when ownership changes. Without rotation, a digest that survived the '
            . 'flush - through a bug, or through an attribute the flush list forgot - would still match, and '
            . 'the same address would digest identically for both shoppers.'
        );
    }

    /**
     * A decision earned under one store view must not replay under another's.
     *
     * These two stores were not namespaced by store view at all before LOQ-17149, unlike the
     * address caches. One session can span store views (?___store=, a language switcher), and
     * each store view carries its own API key and its own prevent_submit toggle, so "warned
     * once, now allowed" is a statement about a configuration as well as about a value.
     */
    public function testTheSameEmailUnderTwoStoreViewsIsVerifiedOncePerStoreView(): void
    {
        $harness = $this->createPlugin();

        $harness['plugin']->checkEmail(self::EMAIL);
        $this->assertSame(1, $this->emailCalls($harness), 'Store view 1 bills the first submission.');

        $harness['storeId']['id'] = 2;
        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertSame(
            2,
            $this->emailCalls($harness),
            'The same address under a DIFFERENT store view must be verified again: the store view decides '
            . 'which API key it is verified against and whether prevent_submit is on, so a decision earned '
            . 'under one must not be replayed under another (LOQ-17149).'
        );

        $harness['storeId']['id'] = 1;
        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertSame(
            2,
            $this->emailCalls($harness),
            'Coming BACK to the first store view must hit the entry earned there. Without this half the test '
            . 'would pass for a digest that simply never matches anything.'
        );
    }

    /**
     * A raw value written by a release BEFORE LOQ-17149 must be discarded, not matched.
     *
     * This is the live-session-at-deploy-time case. The raw entry is inert either way - it can
     * never equal a digest - so what matters is the second assertion: it is removed from the
     * session at the first write, rather than left sitting there as the customer's email
     * address until it ages out.
     */
    public function testARawEntryFromAnEarlierReleaseIsDiscardedRatherThanMatched(): void
    {
        $harness = $this->createPlugin([self::VERIFIED_EMAIL_SESSION_KEY => [self::EMAIL]]);

        $harness['plugin']->checkEmail(self::EMAIL);

        $this->assertSame(
            1,
            $this->emailCalls($harness),
            'A session that was live when this release landed holds the RAW address, which cannot match a '
            . 'digest, so the address is verified once more. One extra billable verify per value at deploy '
            . 'time, recorded in CHANGELOG.md.'
        );
        $this->assertNotContains(
            self::EMAIL,
            (array)($harness['session'][self::VERIFIED_EMAIL_SESSION_KEY] ?? []),
            'The raw address must be dropped from the store at that first write, not left in the session to '
            . 'age out: getting the last raw contact details out of a live session is half the point of the '
            . 'change.'
        );
    }

    /**
     * The two lists must not answer each other's lookups.
     *
     * They are physically separate attributes AND contactDigest() namespaces by field, which
     * is two independent guards. This test is the one that still holds if somebody
     * "simplifies" the two attributes into one map - the same reasoning
     * Validator::BATCH_VERIFY_CACHE_SESSION_KEY carries for the two verdict caches.
     */
    public function testTheEmailAndPhoneListsCannotAnswerEachOthersLookups(): void
    {
        $shared = '0123456789';
        $harness = $this->createPlugin();

        $harness['plugin']->checkPhone($shared);
        $harness['plugin']->checkEmail($shared);

        $this->assertSame(1, $this->phoneCalls($harness), 'The phone submission is billed once.');
        $this->assertSame(
            1,
            $this->emailCalls($harness),
            'A value already warned about as a PHONE number must not let the same string through as an EMAIL '
            . 'address unverified. Two separate attributes and a field-namespaced digest both have to hold '
            . 'for this.'
        );
    }

    /**
     * A concrete AbstractPlugin over doubles, built through the REAL constructor.
     *
     * @param array<string, mixed> $session Session attributes present before the first call.
     * @return array{plugin: object, session: ArrayObject, identity: ArrayObject,
     *     storeId: ArrayObject, emailRequests: ArrayObject, phoneRequests: ArrayObject}
     */
    private function createPlugin(array $session = []): array
    {
        $sessionStore = new ArrayObject($session);
        $identity = new ArrayObject(['customerId' => null]);
        $storeId = new ArrayObject(['id' => 1]);
        $emailRequests = new ArrayObject();
        $phoneRequests = new ArrayObject();

        // NO configuration paths at all, which leaves createConfigHelper() answering '' to
        // everything - and in particular to BOTH prevent_submit toggles, whose shipped default
        // (etc/config.xml sets them to 0) is the only mode in which the bypass stores are
        // consulted. Switching either on would make every test in this file pass for the wrong
        // reason: the store would never be read.
        $helper = $this->createConfigHelper([], $storeId);
        // Both connectors ACCEPT what they are sent: these tests count CALLS, not verdicts.
        $validator = $this->createCountingValidator($emailRequests, $phoneRequests);

        $plugin = new class (
            $this->createMock(Context::class),
            $this->createMock(UrlInterface::class),
            $this->createSessionDouble($sessionStore, $identity),
            $validator,
            $helper,
            $this->createMock(JsonFactory::class)
        ) extends AbstractPlugin {
            /**
             * The two protected entry points, exposed unchanged.
             *
             * validateEmail()/validatePhone() rather than shouldVerify(), because the bypass
             * is only observable as a billable call that did not happen - and because they are
             * what the ten real subclasses call.
             *
             * @param mixed $email
             * @return false|string
             */
            public function checkEmail($email)
            {
                return $this->validateEmail($email);
            }

            /**
             * @param mixed $phone
             * @return false|string
             */
            public function checkPhone($phone)
            {
                return $this->validatePhone($phone);
            }
        };

        return [
            'plugin' => $plugin,
            'session' => $sessionStore,
            'identity' => $identity,
            'storeId' => $storeId,
            'emailRequests' => $emailRequests,
            'phoneRequests' => $phoneRequests,
        ];
    }

    /** Billable verifyEmail() calls made so far. */
    private function emailCalls(array $harness): int
    {
        return count($harness['emailRequests']);
    }

    /** Billable verifyPhoneNumber() calls made so far. */
    private function phoneCalls(array $harness): int
    {
        return count($harness['phoneRequests']);
    }

    /**
     * The single entry a freshly flushed store holds, so a test can assert "only this one".
     *
     * @param array $harness
     * @param string $key
     * @return mixed
     */
    private function soleStoredDigest(array $harness, string $key)
    {
        $stored = (array)($harness['session'][$key] ?? []);
        $this->assertCount(1, $stored, sprintf('"%s" was expected to hold exactly one entry.', $key));

        return reset($stored);
    }

    /**
     * The production bound, read rather than mirrored so the test describes the real limit.
     *
     * THE RANGE CHECK IS NOT DECORATION. Every test that fills a store loops over this figure,
     * so a limit raised to an absurd number does not make those tests FAIL - it makes the
     * PROCESS die of a memory exhaustion fatal partway through the suite, which is a crash
     * rather than a verdict and tells the next reader nothing about what is wrong. Failing here
     * turns that into a sentence. The upper bound itself is asserted for its own sake in
     * testTheTwoContactStoresCannotGrowTheSessionPayloadWithoutBound().
     */
    private function contactLimit(): int
    {
        $reflection = new ReflectionClass(ShopperScopedSessionStores::class);
        if (!$reflection->hasConstant('VERIFIED_CONTACT_LIMIT')) {
            $this->fail(
                'ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT is not defined: these two stores must be '
                . 'bounded, or one session grows them without limit for as long as the shopper keeps typing '
                . 'new email addresses and phone numbers (LOQ-17149).'
            );
        }

        $limit = (int)$reflection->getConstant('VERIFIED_CONTACT_LIMIT');
        $this->assertGreaterThanOrEqual(
            self::SMALLEST_WORKABLE_CONTACT_LIMIT,
            $limit,
            sprintf(
                'ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT is %d, and below %d this module stops '
                . 'terminating rather than merely losing a saving. One submission can carry a SHIPPING and a '
                . 'BILLING phone number, so at 2 the store is full after a single pass and any third value '
                . 'alive in the session makes each submission evict the entry the next one needs - and with '
                . 'prevent_submit off (the shipped default) the shopper is then warned about a value they have '
                . 'already resubmitted, forever, with nothing on the page to correct. See '
                . 'testBothPhoneNumbersOnOneOrderSurviveTheSameSubmission() in the adminhtml contact-store '
                . 'tests for the behaviour this floor protects.',
                $limit,
                self::SMALLEST_WORKABLE_CONTACT_LIMIT
            )
        );
        $this->assertLessThanOrEqual(
            self::LARGEST_DEFENSIBLE_CONTACT_LIMIT,
            $limit,
            sprintf(
                'ShopperScopedSessionStores::VERIFIED_CONTACT_LIMIT is %d, which is past anything this store '
                . 'can justify holding - see testTheTwoContactStoresCannotGrowTheSessionPayloadWithoutBound(). '
                . 'It is reported here as well because the tests that fill the store loop over this figure, so '
                . 'an absurd value kills the PHP process with a memory fatal instead of failing an assertion.',
                $limit
            )
        );

        return $limit;
    }

    /**
     * The private attribute the digest salt lives in, read from the production constant.
     */
    private function saltKey(): string
    {
        $reflection = new ReflectionClass(ShopperScopedSessionStores::class);
        if (!$reflection->hasConstant('CONTACT_DIGEST_SALT_KEY')) {
            $this->fail(
                'ShopperScopedSessionStores::CONTACT_DIGEST_SALT_KEY is not defined: the digests have to be '
                . 'keyed with a per-session secret that dies with the session, or an unsalted digest of an '
                . 'email address is a global identifier for it.'
            );
        }

        return (string)$reflection->getConstant('CONTACT_DIGEST_SALT_KEY');
    }
}
