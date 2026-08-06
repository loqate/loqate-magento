<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin\Frontend;

use ArrayObject;
use Loqate\ApiIntegration\Plugin\Frontend\CheckoutBillingAddress;
use Loqate\ApiIntegration\Plugin\Frontend\PlaceOrder;
use Loqate\ApiIntegration\Plugin\Frontend\PlaceOrderGuest;
use Loqate\ApiIntegration\Test\Unit\Plugin\ShopperSessionHarness;
use Magento\Checkout\Model\GuestPaymentInformationManagement;
use Magento\Checkout\Model\PaymentInformationManagement;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\BillingAddressManagement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the billing-error gate - session attribute 'loqate_billing_errors' - across
 * the three plugins that own it: Plugin\Frontend\CheckoutBillingAddress writes it in both
 * directions, and Plugin\Frontend\PlaceOrder and PlaceOrderGuest read it and refuse the order.
 *
 * WHY THIS ATTRIBUTE IS IN THE SHOPPER FLUSH FOR THE OPPOSITE REASON TO THE OTHER SIX. The
 * other six are bypasses: a stale value lets the next shopper SKIP a verification. This one is
 * a DENIAL: a stale true refuses them a checkout. Both are defects and both are closed by the
 * same flush, but they fail in opposite directions, so a test written for the bypass shape
 * would not notice this one at all.
 *
 * AND WHY THE DENIAL IS UNRECOVERABLE WITHOUT THE FLUSH, which is what makes it worth three
 * tests rather than one. The gate is written ONLY by CheckoutBillingAddress::aroundAssign(),
 * i.e. on BillingAddressManagement::assign(). Both readers are BEFORE plugins on
 * savePaymentInformationAndPlaceOrder(), which assigns the billing address further down the
 * same call. So on any flow that submits the billing address together with the place-order call
 * - which is what the default one-page checkout does when the shopper edits the billing address
 * in the payment step - the reader throws before the only thing that could clear the gate ever
 * runs. The shopper is then stuck behind a message that names no field
 * ("Please check the error again before continuing."), and no amount of correcting their address
 * helps. testResubmittingACorrectedBillingAddressWithThePlaceOrderCallCannotReleaseTheBlock()
 * pins that reading, and it is the argument for enrolling this attribute in the flush rather
 * than trusting that it gets overwritten.
 */
class BillingErrorGateTest extends TestCase
{
    use ShopperSessionHarness;

    /** Session attribute the gate lives under. */
    private const BILLING_ERRORS_SESSION_KEY = 'loqate_billing_errors';

    /** A billing address as it arrives from checkout, carrying the phone number under test. */
    private const BILLING_ADDRESS = [
        'street' => ['1 High St'],
        'city' => 'London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
        'telephone' => '+44 20 7946 0000',
    ];

    /**
     * The gate in both directions, driven through the only class that writes it and both
     * classes that read it: a rejected billing address blocks the order, and the resubmission
     * the module explicitly invites releases it again.
     *
     * The second half is as load-bearing as the first. A gate that stuck on true would make
     * "Submit again to use this phone number" a lie and leave every shopper who ever failed a
     * check unable to buy anything for the rest of their session - and it would do so with
     * every privacy assertion in this suite still green.
     */
    public function testARejectedBillingAddressBlocksTheOrderAndTheInvitedResubmissionReleasesIt(): void
    {
        $harness = $this->createCheckout(['phonePasses' => false]);

        $rejected = null;
        try {
            $this->submitBillingAddress($harness, self::BILLING_ADDRESS);
        } catch (InputException $e) {
            $rejected = $e;
        }

        $this->assertInstanceOf(
            InputException::class,
            $rejected,
            'A billing address whose phone number Loqate rejects must be refused at the billing step.'
        );
        $this->assertTrue(
            (bool)($harness['session'][self::BILLING_ERRORS_SESSION_KEY] ?? false),
            'The rejection must record the gate, or the place-order call has nothing to stop it.'
        );
        $this->assertOrderIsRefused(
            $harness,
            'A shopper whose billing address was just rejected must not be able to place the order: that is '
            . 'the whole purpose of the gate.'
        );

        // The resubmission the module's own message invites. The phone number is already in the
        // bypass list, so it is accepted without a second billable call - which is what clears
        // the gate.
        $this->submitBillingAddress($harness, self::BILLING_ADDRESS);

        $this->assertSame(
            1,
            count($harness['phoneRequests']),
            'The resubmission must be recognised by its digest and skip the billable verify - that is what '
            . '"Submit again to use this phone number" means - so the address passes and the gate is cleared.'
        );
        $this->assertFalse(
            (bool)($harness['session'][self::BILLING_ERRORS_SESSION_KEY] ?? false),
            'A passing billing address must clear the gate.'
        );
        $this->assertOrderIsAllowed(
            $harness,
            'Once the billing address passes, the shopper must be able to place their order. A gate that only '
            . 'ever closed would leave every shopper who failed one check unable to buy anything.'
        );
    }

    /**
     * THE DEFECT LOQ-17149 CLOSES, in the direction that costs a sale: a block earned by the
     * previous person at this browser must not deny the next shopper their order.
     *
     * session_regenerate_id() preserves session data across a login and a logout, so without
     * the flush the gate shopper A tripped is still set when B places their order - and B is
     * refused, with a message about an error they never saw, on a checkout where nothing is
     * wrong. Both readers and all three identity transitions are driven, because the guest
     * reader is not a special case of the customer one and a logout is not a special case of a
     * login.
     *
     * @param string $reader Which of the two place-order plugins enforces the gate.
     * @param int|string|null $after Identity of whoever places the order next.
     */
    #[DataProvider('inheritedBlockProvider')]
    public function testAStaleBlockFromThePreviousShopperDoesNotDenyTheNextShopperTheirOrder(
        string $reader,
        $after
    ): void {
        $harness = $this->createCheckout(['phonePasses' => false, 'customerId' => 7]);

        // Shopper A trips the gate for real, through the only class that writes it, so the
        // ownership marker is recorded exactly as production records it.
        try {
            $this->submitBillingAddress($harness, self::BILLING_ADDRESS);
        } catch (InputException $e) {
            // Expected: asserted in the test above.
        }
        $this->assertTrue(
            (bool)($harness['session'][self::BILLING_ERRORS_SESSION_KEY] ?? false),
            'Fixture guard: shopper A must really be blocked, or there is no stale block to inherit.'
        );

        // Somebody else is at this browser now: a login, a logout, or one login after another.
        $harness['identity']['customerId'] = $after;

        $this->assertOrderIsAllowed(
            $harness,
            'A block earned by the PREVIOUS shopper must not deny this one their order. Nothing on this '
            . 'checkout is wrong, the message names no field they could correct, and on a flow that submits '
            . 'the billing address with the place-order call there is nothing they could do about it - see '
            . 'testResubmittingACorrectedBillingAddressWithThePlaceOrderCallCannotReleaseTheBlock().',
            $reader
        );
        $this->assertNull(
            $harness['session'][self::BILLING_ERRORS_SESSION_KEY] ?? null,
            'The inherited gate must be CLEARED, not merely ignored: a value left in the session is read again '
            . 'by the other reader on the next request.'
        );
    }

    /**
     * @return array<string, array{0: string, 1: int|string|null}>
     */
    public static function inheritedBlockProvider(): array
    {
        $cases = [];
        foreach (['the registered-customer reader' => 'customer', 'the guest reader' => 'guest'] as $label => $reader) {
            $cases["a customer logs out and a guest checks out, blocked by $label"] = [$reader, null];
            $cases["one customer logs in straight after another, blocked by $label"] = [$reader, 8];
            $cases["the customer id arrives as a numeric string, blocked by $label"] = [$reader, '8'];
        }

        return $cases;
    }

    /**
     * THE READING THAT MAKES THE FLUSH NECESSARY RATHER THAN MERELY TIDY: on a checkout that
     * submits the billing address together with the place-order call, correcting the address
     * cannot release the block, because the reader throws before the only writer runs.
     *
     * Pinned as behaviour, not left in a comment, because it is the whole argument for
     * enrolling this attribute: if the block could simply be overwritten by the next billing
     * submission, an inherited one would cost the next shopper one confusing message and no
     * more. It cannot, so an inherited one is permanent for the life of the session.
     *
     * The order of the two calls below is production's, not the test's: Magento runs a before
     * plugin, and only if it returns does it run the subject method - which is where the
     * billing address is assigned and therefore where CheckoutBillingAddress::aroundAssign()
     * gets its chance to clear the gate.
     */
    public function testResubmittingACorrectedBillingAddressWithThePlaceOrderCallCannotReleaseTheBlock(): void
    {
        $harness = $this->createCheckout(['phonePasses' => false]);

        try {
            $this->submitBillingAddress($harness, self::BILLING_ADDRESS);
        } catch (InputException $e) {
            // Expected: the shopper's first attempt is rejected and the gate is recorded.
        }

        $corrected = array_merge(self::BILLING_ADDRESS, ['telephone' => '+44 161 496 0000']);
        $refusalMessage = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $refused = null;
            try {
                // Magento's own ordering: the before plugin first...
                $harness['placeOrder']->beforeSavePaymentInformationAndPlaceOrder(
                    $this->createMock(PaymentInformationManagement::class),
                    1,
                    'checkmo',
                    $corrected
                );
                // ...and only if it did not throw does the subject method run, assigning the
                // billing address and giving CheckoutBillingAddress its chance to clear the gate.
                $this->submitBillingAddress($harness, $corrected);
            } catch (CouldNotSaveException $e) {
                $refused = $e;
                $refusalMessage = $e->getMessage();
            }

            $this->assertInstanceOf(
                CouldNotSaveException::class,
                $refused,
                sprintf(
                    'Attempt %d: the gate must still refuse the order. The corrected address travels WITH the '
                    . 'place-order call, so it is never inspected - which is why an inherited gate has to be '
                    . 'flushed rather than left to be overwritten.',
                    $attempt
                )
            );
        }

        $this->assertSame(
            1,
            count($harness['phoneRequests']),
            'The corrected phone number must never have been verified at all: the throw happens before the '
            . 'billing address is assigned, so the only writer of the gate never runs. One call, from the '
            . 'original rejection.'
        );
        $this->assertTrue(
            (bool)($harness['session'][self::BILLING_ERRORS_SESSION_KEY] ?? false),
            'And the gate is still set after both attempts: nothing on this flow can clear it.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'phone',
            $refusalMessage,
            'The refusal names no field, which is why a shopper cannot work out what to correct: the message is '
            . '"Please check the error again before continuing." and the error it refers to may have been '
            . 'someone else\'s.'
        );
    }

    /**
     * A checkout over doubles: the billing-address plugin and both place-order readers, all
     * three sharing ONE customer session, built through their real constructors.
     *
     * @param array{phonePasses?: bool, customerId?: int|string|null} $options
     * @return array<string, mixed>
     */
    private function createCheckout(array $options = []): array
    {
        $sessionStore = new ArrayObject();
        $identity = new ArrayObject(['customerId' => $options['customerId'] ?? null]);
        $emailRequests = new ArrayObject();
        $phoneRequests = new ArrayObject();

        $session = $this->createSessionDouble($sessionStore, $identity);
        $helper = $this->createConfigHelper([
            'loqate_settings/settings/api_key' => 'TEST-API-KEY-0000',
            // Only the phone check is on, so the gate is decided by one thing and the address
            // connector never enters into it.
            'loqate_settings/phone_settings/enable_checkout' => 1,
        ]);
        $validator = $this->createCountingValidator(
            $emailRequests,
            $phoneRequests,
            true,
            $options['phonePasses'] ?? true
        );

        $billing = new CheckoutBillingAddress(
            $this->createMock(Context::class),
            $this->createMock(UrlInterface::class),
            $session,
            $validator,
            $helper,
            $this->createMock(JsonFactory::class)
        );

        return [
            'billing' => $billing,
            'placeOrder' => new PlaceOrder($session),
            'placeOrderGuest' => new PlaceOrderGuest($session),
            'session' => $sessionStore,
            'identity' => $identity,
            'phoneRequests' => $phoneRequests,
            'emailRequests' => $emailRequests,
            'proceedCalls' => new ArrayObject(),
        ];
    }

    /**
     * Submit a billing address the way BillingAddressManagement::assign() does.
     *
     * @param array<string, mixed> $harness
     * @param array<string, mixed> $address The address as the plugin sees it.
     * @throws InputException When the plugin rejects it.
     */
    private function submitBillingAddress(array $harness, array $address): void
    {
        $proceedCalls = $harness['proceedCalls'];
        $harness['billing']->aroundAssign(
            $this->createMock(BillingAddressManagement::class),
            static function ($cartId, $submitted, $useForShipping = false) use ($proceedCalls) {
                $proceedCalls[] = $submitted;

                return 1;
            },
            1,
            // The plugin only ever calls getData() on the address, so the double is anything
            // that answers it - the same shape Magento's quote address presents.
            new class ($address) {
                /** @var array<string, mixed> */
                private $data;

                public function __construct(array $data)
                {
                    $this->data = $data;
                }

                /**
                 * @return array<string, mixed>
                 */
                public function getData()
                {
                    return $this->data;
                }
            }
        );
    }

    /**
     * Assert that placing the order is refused, by whichever reader (or by both).
     *
     * @param array<string, mixed> $harness
     * @param string $because Why the refusal is the guarantee.
     */
    private function assertOrderIsRefused(array $harness, string $because, string $reader = 'both'): void
    {
        foreach ($this->readersUnderTest($harness, $reader) as $label => $case) {
            $refused = null;
            try {
                ($case['call'])();
            } catch (CouldNotSaveException $e) {
                $refused = $e;
            }

            $this->assertInstanceOf(CouldNotSaveException::class, $refused, sprintf('%s: %s', $label, $because));
        }
    }

    /**
     * Assert that placing the order goes through, by whichever reader (or by both).
     *
     * What is asserted is not merely "no exception" but that the plugin hands the call on with
     * the shopper's own arguments untouched, which is what a before plugin returning normally
     * means - and which no amount of swallowing an exception could fake.
     *
     * @param array<string, mixed> $harness
     * @param string $because Why letting it through is the guarantee.
     */
    private function assertOrderIsAllowed(array $harness, string $because, string $reader = 'both'): void
    {
        foreach ($this->readersUnderTest($harness, $reader) as $label => $case) {
            try {
                $arguments = ($case['call'])();
            } catch (CouldNotSaveException $e) {
                $this->fail(sprintf('%s: %s (refused with "%s")', $label, $because, $e->getMessage()));
            }

            $this->assertSame(
                $case['arguments'],
                $arguments,
                sprintf('%s must pass the call on with the shopper\'s own arguments unchanged: %s', $label, $because)
            );
        }
    }

    /**
     * The place-order readers as callables, keyed by a readable label, each with the argument
     * list a before plugin is expected to hand back untouched.
     *
     * Both are exercised by default. They are separate classes with separate signatures - the
     * guest one carries the shopper's email address - so neither is a special case of the other,
     * and a fix applied to one would leave the other refusing orders.
     *
     * @param array<string, mixed> $harness
     * @param string $reader 'both', 'customer' or 'guest'.
     * @return array<string, array{call: callable, arguments: array}>
     */
    private function readersUnderTest(array $harness, string $reader): array
    {
        $customerSubject = $this->createMock(PaymentInformationManagement::class);
        $guestSubject = $this->createMock(GuestPaymentInformationManagement::class);
        $cases = [
            'customer' => [
                'Plugin\Frontend\PlaceOrder' => [
                    'call' => static fn () => $harness['placeOrder']
                        ->beforeSavePaymentInformationAndPlaceOrder($customerSubject, 1, 'checkmo', null),
                    'arguments' => [1, 'checkmo', null],
                ],
            ],
            'guest' => [
                'Plugin\Frontend\PlaceOrderGuest' => [
                    'call' => static fn () => $harness['placeOrderGuest']
                        ->beforeSavePaymentInformationAndPlaceOrder(
                            $guestSubject,
                            1,
                            'shopper@example.com',
                            'checkmo',
                            null
                        ),
                    'arguments' => [1, 'shopper@example.com', 'checkmo', null],
                ],
            ],
        ];

        return $reader === 'both' ? array_merge($cases['customer'], $cases['guest']) : $cases[$reader];
    }
}
