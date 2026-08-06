<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use ArrayObject;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
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
    /** Session attribute the email bypass list lives under. */
    private const VERIFIED_EMAIL_SESSION_KEY = 'loqate_email';

    /** Session attribute the phone bypass list lives under. */
    private const VERIFIED_PHONE_SESSION_KEY = 'loqate_phone';

    /** An address that is not a numeric string, so the loose/strict distinction cannot bite. */
    private const EMAIL = 'shopper.a@example.com';

    /** A phone number, likewise well clear of the numeric-string collision below. */
    private const PHONE = '+44 20 7946 0000';

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

        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static function ($configPath) {
                // '' for BOTH prevent_submit toggles, which is the SHIPPED DEFAULT
                // (etc/config.xml sets them to 0) and the only mode in which the bypass
                // stores are consulted at all. Setting them would make every test here pass
                // for the wrong reason: the store would never be read.
                return '';
            }
        );
        $helper->method('getCurrentStore')->willReturnCallback(static fn (): int => (int)$storeId['id']);

        $validator = $this->createMock(Validator::class);
        $validator->method('verifyEmail')->willReturnCallback(
            static function ($email) use ($emailRequests) {
                $emailRequests[] = $email;

                // Truthy, with no 'error' and no 'noKeyFound', so validateEmail() reports no
                // error. The tests count CALLS, not verdicts.
                return ['Valid' => true];
            }
        );
        $validator->method('verifyPhoneNumber')->willReturnCallback(
            static function ($phone, $country = null) use ($phoneRequests) {
                $phoneRequests[] = $phone;

                return ['Valid' => true];
            }
        );

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

    /**
     * A Magento\Customer\Model\Session double that actually stores what it is given.
     *
     * The shared Test/stubs Session is a no-op (getData() returns null, setData() stores
     * nothing), so nothing under test could ever be observed. getData()/setData() have to be
     * *added* when the real Magento Session is present, because it does not declare them -
     * SessionManager __call-forwards them to Session\Storage - while the stub does declare
     * them and PHPUnit refuses to "add" an existing method; hence the method_exists() filter,
     * which keeps this double working on both sides.
     *
     * @param ArrayObject $sessionStore Backing store for the session attributes.
     * @param ArrayObject $identity Holds 'customerId', read LIVE so a test can log in mid-test.
     * @return Session&MockObject
     */
    private function createSessionDouble(ArrayObject $sessionStore, ArrayObject $identity)
    {
        $sessionBuilder = $this->getMockBuilder(Session::class)->disableOriginalConstructor();
        $undeclared = array_values(array_filter(
            ['getData', 'setData'],
            static fn (string $method): bool => !method_exists(Session::class, $method)
        ));
        if ($undeclared) {
            $sessionBuilder->addMethods($undeclared);
        }
        $sessionMock = $sessionBuilder->getMock();
        $sessionMock->method('getData')->willReturnCallback(
            static fn ($key = '', $clear = false) => $sessionStore[$key] ?? null
        );
        $sessionMock->method('setData')->willReturnCallback(
            static function ($key, $value = null) use ($sessionStore, $sessionMock) {
                $sessionStore[$key] = $value;

                return $sessionMock;
            }
        );
        $sessionMock->method('getCustomerId')->willReturnCallback(static fn () => $identity['customerId']);

        return $sessionMock;
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

        return (int)$reflection->getConstant('VERIFIED_CONTACT_LIMIT');
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
