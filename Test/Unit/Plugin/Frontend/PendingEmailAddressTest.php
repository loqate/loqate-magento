<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin\Frontend;

use ArrayObject;
use Loqate\ApiIntegration\Plugin\Frontend\AccountManagement;
use Loqate\ApiIntegration\Plugin\Frontend\CheckoutShippingInformation;
use Loqate\ApiIntegration\Test\Unit\Plugin\ShopperSessionHarness;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Customer\Model\AccountManagement as CoreAccountManagement;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\StateException;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pending email address - session attribute 'loqate_email_to_validate' -
 * across the two plugins that own it: Plugin\Frontend\AccountManagement writes it when the
 * checkout asks whether an email address is available, and
 * Plugin\Frontend\CheckoutShippingInformation reads it, pays to verify it, and clears it only
 * when the verification passes.
 *
 * WHY THAT PAIRING IS THE WHOLE POINT. The writer runs in the EMAIL step of a guest checkout
 * and the reader runs in the SHIPPING step, so between them the shopper can walk away - and if
 * they do, the address sits in the session with nothing to clear it. It holds a raw email
 * address (it has to: it is a value awaiting verification, not a comparison store), so an
 * abandoned one is both a billable verify and a message about an address the next shopper never
 * typed, charged to and shown to whoever uses the browser next. LOQ-17149 enrolled it in the
 * shopper flush for that reason, and the flush is what covers the abandoned case, because the
 * reader's own clear only runs on success.
 */
class PendingEmailAddressTest extends TestCase
{
    use ShopperSessionHarness;

    /** Session attribute the pending address lives under. */
    private const PENDING_EMAIL_SESSION_KEY = 'loqate_email_to_validate';

    /** The address shopper A offers at the email step. */
    private const SHOPPER_A_EMAIL = 'abandoned.shopper.a@example.com';

    /**
     * The behaviour that must survive: an address offered at the email step is verified once,
     * inside the same shopper's shipping step, and then not again.
     *
     * Asserted first because every guarantee below is about an address NOT being verified, and
     * a plugin pair that verified nothing at all would satisfy all of them while quietly
     * dropping the check this module is installed to make.
     */
    public function testAnAddressOfferedAtTheEmailStepIsVerifiedOnceInTheSameShoppersCheckout(): void
    {
        $harness = $this->createCheckout();

        $this->offerEmailAddress($harness, self::SHOPPER_A_EMAIL);

        $this->assertSame(
            self::SHOPPER_A_EMAIL,
            $harness['session'][self::PENDING_EMAIL_SESSION_KEY] ?? null,
            'The address the shopper typed must be held for the shipping step to verify.'
        );

        $this->saveShippingInformation($harness);

        $this->assertSame(
            [self::SHOPPER_A_EMAIL],
            iterator_to_array($harness['emailRequests']),
            'The shipping step must verify the address that was offered, and verify exactly that one.'
        );
        $this->assertSame(
            '',
            $harness['session'][self::PENDING_EMAIL_SESSION_KEY] ?? null,
            'A successful verification must clear the pending address, or it is re-verified on every '
            . 'subsequent request of the same checkout.'
        );

        $this->saveShippingInformation($harness);

        $this->assertCount(
            1,
            $harness['emailRequests'],
            'A second shipping save must not re-verify the same address: the checkout saves the shipping '
            . 'information again on every change of shipping method, and each one would otherwise be billed.'
        );
    }

    /**
     * THE DEFECT LOQ-17149 CLOSES for this attribute: an address shopper A abandoned must never
     * be verified inside shopper B's checkout, nor be readable from B's session.
     *
     * Three separate harms, all pinned here, because the attribute holds a RAW email address:
     * B is billed for a Cleansing request on a stranger's address; B is blocked at the shipping
     * step by a message about an address that is nowhere on their form; and A's address is
     * sitting in B's session, which is the privacy half.
     *
     * The email connector REJECTS what it is sent in this fixture. That is deliberate: with the
     * flush working nothing is sent at all, so the verdict is irrelevant - but with the flush
     * broken it is the difference between a silent extra charge and B being refused their
     * checkout, and the assertion below says which.
     */
    public function testShopperAsAbandonedAddressIsNeverVerifiedInShopperBsCheckoutNorLeftInTheirSession(): void
    {
        $harness = $this->createCheckout(['emailPasses' => false, 'customerId' => 7]);

        // Shopper A offers an address at the email step and then walks away: nothing clears it.
        $this->offerEmailAddress($harness, self::SHOPPER_A_EMAIL);
        $this->assertSame(
            self::SHOPPER_A_EMAIL,
            $harness['session'][self::PENDING_EMAIL_SESSION_KEY] ?? null,
            'Fixture guard: A\'s address must really be left pending, or there is nothing for B to inherit.'
        );

        // Shopper B is at this browser now - a logout, a login, a second login - and reaches
        // the shipping step of their own checkout.
        $harness['identity']['customerId'] = 8;

        $blocked = null;
        try {
            $this->saveShippingInformation($harness);
        } catch (StateException $e) {
            $blocked = $e;
        }

        $this->assertNull(
            $blocked,
            'Shopper B must not be blocked at the shipping step by the previous shopper\'s abandoned address: '
            . 'the message names an address that is nowhere on B\'s form, so there is nothing B can correct.'
        );
        $this->assertCount(
            0,
            $harness['emailRequests'],
            'Shopper B must not pay for a Cleansing request on a stranger\'s email address. The pending '
            . 'address is read through the shopper-ownership guard, so an identity change discards it before '
            . 'anything is sent to Loqate.'
        );
        $this->assertStringNotContainsString(
            self::SHOPPER_A_EMAIL,
            $this->sessionPayload($harness['session']),
            'And A\'s raw email address must be gone from the session entirely - not merely ignored. Unlike '
            . 'the two contact bypass lists this attribute holds the address itself, because it is a value '
            . 'awaiting verification, so the flush is the only thing that removes it.'
        );
    }

    /**
     * The two plugins that own the pending address, sharing ONE customer session, built through
     * their real constructors.
     *
     * @param array{emailPasses?: bool, customerId?: int|string|null} $options
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
            // Only the email check is on, so the pending address is the only thing the shipping
            // step can report an error about.
            'loqate_settings/email_settings/enable_checkout' => 1,
        ]);
        $validator = $this->createCountingValidator(
            $emailRequests,
            $phoneRequests,
            $options['emailPasses'] ?? true
        );
        $arguments = [
            $this->createMock(Context::class),
            $this->createMock(UrlInterface::class),
            $session,
            $validator,
            $helper,
            $this->createMock(JsonFactory::class),
        ];

        return [
            'accountManagement' => new AccountManagement(...$arguments),
            'shipping' => new CheckoutShippingInformation(...$arguments),
            'session' => $sessionStore,
            'identity' => $identity,
            'emailRequests' => $emailRequests,
        ];
    }

    /**
     * Offer an email address the way the checkout's email field does, through
     * AccountManagement::isEmailAvailable().
     *
     * @param array<string, mixed> $harness
     */
    private function offerEmailAddress(array $harness, string $email): void
    {
        $harness['accountManagement']->beforeIsEmailAvailable(
            $this->createMock(CoreAccountManagement::class),
            $email,
            1
        );
    }

    /**
     * Save the shipping information the way ShippingInformationManagement::saveAddressInformation()
     * does - the request that verifies the pending address and clears it on success.
     *
     * @param array<string, mixed> $harness
     * @throws StateException When the plugin reports an error.
     */
    private function saveShippingInformation(array $harness): void
    {
        $harness['shipping']->aroundSaveAddressInformation(
            $this->createMock(ShippingInformationManagement::class),
            static fn ($cartId, $addressInformation) => 'shipping saved',
            1,
            // The plugin calls getShippingAddress()->getData() and nothing else, so the double
            // is the smallest pair of objects that answers that.
            new class {
                public function getShippingAddress(): object
                {
                    return new class {
                        /**
                         * A shipping address with nothing wrong with it: only the email check is
                         * switched on in this fixture, so the address itself is never inspected.
                         *
                         * @return array<string, mixed>
                         */
                        public function getData(): array
                        {
                            return [
                                'street' => ['1 High St'],
                                'city' => 'London',
                                'postcode' => 'SW1A 1AA',
                                'country_id' => 'GB',
                            ];
                        }
                    };
                }
            }
        );
    }
}
