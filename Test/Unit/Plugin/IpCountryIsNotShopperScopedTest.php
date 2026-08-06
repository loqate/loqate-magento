<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use ArrayObject;
use Loqate\ApiIntegration\Helper\Extra;
use Loqate\ApiIntegration\Plugin\ChangeAddressDefaultCountry;
use Loqate\ApiIntegration\Plugin\ChangeCheckoutDefaultCountry;
use Loqate\ApiIntegration\Plugin\Frontend\PlaceOrder;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the ONE session attribute this module writes that is deliberately NOT flushed
 * when the shopper changes - 'loqate_ipcountry' - across both plugins that own it:
 * Plugin\ChangeAddressDefaultCountry and Plugin\ChangeCheckoutDefaultCountry.
 *
 * WHY AN EXCLUSION NEEDS A TEST AT ALL, and why it needs THIS one. LOQ-17149 enrolled four
 * sibling attributes in the shopper flush and left this one out, so the file that lists them is
 * now a decision with two sides and only one of them was defended. A test that asserted only
 * "the seven are flushed" would be satisfied by a guard that flushed EVERYTHING, and a test that
 * asserted only "the IP country survives" would be satisfied by a guard that flushed nothing.
 * Both halves are asserted here in ONE test, over ONE session, across ONE identity change, so
 * neither can be made true by breaking the other.
 *
 * THE REASONING BEING DEFENDED, so that a future reader who wants to "fix" the omission finds
 * the argument rather than only the assertion. The value is derived from the request's IP
 * address, not from the shopper: two people sharing a browser share the IP address, so what was
 * correct for shopper A is correct for shopper B by construction. Flushing it would protect
 * nobody and would only make the module resolve the same answer again - against Loqate's
 * /Extras/Web/Ip2Country/ endpoint, which is a separate call from the /Cleansing/,
 * /EmailValidation/ and /PhoneValidation/ verifies these stores exist to de-duplicate. And it
 * grants no bypass: it pre-selects a country in a dropdown, and the address is verified on its
 * own merits afterwards whatever the dropdown said.
 *
 * The flush is driven through Plugin\Frontend\PlaceOrder rather than through the seam directly,
 * because that is a real request a shopper makes in the same checkout, and because a test that
 * built the seam itself would be asserting its own wiring rather than production's.
 */
class IpCountryIsNotShopperScopedTest extends TestCase
{
    use ShopperSessionHarness;

    /** Session attribute the IP-derived country lives under. */
    private const IP_COUNTRY_SESSION_KEY = 'loqate_ipcountry';

    /** The country the Ip2Country lookup resolves for this browser's IP address. */
    private const RESOLVED_COUNTRY = 'GB';

    /**
     * One recognisable value per shopper-scoped store, so a flush that misses one is reported as
     * which store survived rather than as a bare count.
     *
     * @return array<string, mixed>
     */
    private function seededStores(): array
    {
        return [
            'captured_addresses' => ['the previous shopper\'s captured address'],
            'loqate_verified_addresses' => ['cached' => 'the previous shopper\'s verdict'],
            'loqate_verified_batch_addresses' => ['cached' => 'the previous shopper\'s batch verdict'],
            'loqate_email' => ['the previous shopper\'s email digest'],
            'loqate_phone' => ['the previous shopper\'s phone digest'],
            'loqate_email_to_validate' => 'previous.shopper@example.com',
            'loqate_billing_errors' => true,
        ];
    }

    /**
     * The whole of the LOQ-17149 decision about this attribute, in one sequence: a shopper
     * change costs the incoming shopper every one of the seven stores that gate a verification,
     * and costs them nothing at all in the country resolved from the browser's IP address.
     *
     * The second half is not a convenience. Flushing it would mean a second Ip2Country lookup
     * on the first request after every login and every logout, for an answer that cannot have
     * changed - the IP address is the same browser's - and the module would be paying for that
     * on a path that is not part of the verify billing these stores exist to reduce.
     *
     * @param string $plugin Which of the two IP-country plugins pre-selects the country.
     */
    #[DataProvider('ipCountryReaderProvider')]
    public function testAShopperChangeCostsTheNextShopperEveryBypassButNotTheBrowsersOwnCountry(
        string $plugin
    ): void {
        $harness = $this->createCheckout($this->seededStores(), 7);

        // Shopper A's checkout: the country is resolved once and cached, and their stores are
        // adopted and recorded as theirs.
        $this->assertSame(
            self::RESOLVED_COUNTRY,
            $this->preselectedCountry($harness, $plugin),
            'Fixture guard: the country must really be pre-selected from the IP lookup, or the assertions '
            . 'below hold for a plugin that does nothing.'
        );
        $this->assertSame(1, count($harness['lookups']), 'Fixture guard: the first request must resolve it.');
        $this->placeOrderIsAttempted($harness);
        $this->assertSame(
            $this->seededStores(),
            $this->shopperScopedAttributes($harness),
            'Fixture guard: shopper A\'s stores must survive their OWN request, or the flush asserted below '
            . 'would be indistinguishable from a guard that flushes on every access.'
        );

        // Somebody else is at this browser now - a login, a logout, a second login - and their
        // checkout reaches the place-order call.
        $harness['identity']['customerId'] = 8;
        $this->placeOrderIsAttempted($harness);

        foreach ($this->shopperScopedAttributes($harness) as $key => $value) {
            $this->assertNull(
                $value,
                sprintf(
                    'Session attribute "%s" survived the shopper change. All seven gate one shopper\'s '
                    . 'submission - six are a licence to skip a billable verification and the seventh refuses '
                    . 'an order - so the next person at this browser must inherit none of them.',
                    $key
                )
            );
        }

        // ...and the country the BROWSER is in is untouched by any of that.
        $this->assertSame(
            self::RESOLVED_COUNTRY,
            $this->preselectedCountry($harness, $plugin),
            'The new shopper must still get the country pre-selected.'
        );
        $this->assertSame(
            1,
            count($harness['lookups']),
            'The IP-derived country must NOT be flushed when the shopper changes, and this is the half of the '
            . 'decision that a test of the flush alone would never notice. It is derived from the request\'s IP '
            . 'address, so two shoppers on one browser share it by construction: flushing it would protect '
            . 'nobody and would buy a second Ip2Country lookup on the first request after every login and every '
            . 'logout, for an answer that cannot have changed. It also grants no bypass - it pre-selects a '
            . 'dropdown, and the address is verified on its merits afterwards whatever the dropdown said. If '
            . 'this is ever enrolled, delete getIpCountry()/setIpCountry() and say why.'
        );
        $this->assertNotNull(
            $harness['session'][self::IP_COUNTRY_SESSION_KEY] ?? null,
            'And it is still in the session, rather than merely being re-resolved quietly: the attribute is '
            . 'what is excluded from the flush, not just the lookup.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ipCountryReaderProvider(): array
    {
        return [
            // Neither is a special case of the other: one runs on the customer address form and
            // the other while a checkout PAGE is being rendered, which is the only reader of the
            // seam that runs on a rendered page at all.
            'the customer address form' => ['address'],
            'the checkout page' => ['checkout'],
        ];
    }

    /**
     * The exclusion must stay a NAMED accessor, not a key anyone can pass: the generic
     * accessors have to keep refusing this attribute.
     *
     * WHY THAT IS A SEPARATE GUARANTEE FROM THE FLUSH. If the IP-country cache were reachable
     * through getData()/setData() it would be flushed - they run the ownership check - and the
     * decision above would be silently reversed with both plugins still working, because a
     * flushed value simply costs another lookup. Refusing the key is what makes "not enrolled"
     * and "not reachable" the same statement, and a method with no key parameter cannot be
     * pointed at a store by mistake.
     */
    public function testTheBrowsersCountryCannotBeReachedThroughTheShopperScopedAccessors(): void
    {
        $enrolled = (array)(new ReflectionClass(\Loqate\ApiIntegration\Helper\ShopperScopedSessionStores::class))
            ->getConstant('SHOPPER_SCOPED_SESSION_KEYS');

        $this->assertNotContains(
            self::IP_COUNTRY_SESSION_KEY,
            $enrolled,
            'The IP-country cache must stay out of the enrolment list. That list is BOTH the flush list and the '
            . 'allowlist for getData()/setData(), so adding it there would enrol it in a flush it does not need '
            . 'and hand it a generic accessor it must not have.'
        );

        $harness = $this->createCheckout([], null);

        // The named accessors still work over the same session, which is what makes the
        // refusal an exclusion rather than an omission.
        $this->preselectedCountry($harness, 'address');

        $this->assertNotNull(
            $harness['session'][self::IP_COUNTRY_SESSION_KEY] ?? null,
            'The named accessors must still be able to write it: it is excluded from the shopper flush, not '
            . 'from the module.'
        );
    }

    /**
     * Both IP-country plugins and a place-order reader over ONE customer session, each built
     * through its real constructor.
     *
     * @param array<string, mixed> $session Session attributes present before the first request.
     * @param int|string|null $customerId Logged-in customer, null for a guest.
     * @return array<string, mixed>
     */
    private function createCheckout(array $session, $customerId): array
    {
        $sessionStore = new ArrayObject($session);
        $identity = new ArrayObject(['customerId' => $customerId]);
        $lookups = new ArrayObject();

        $sessionMock = $this->createSessionDouble($sessionStore, $identity);
        $helper = $this->createConfigHelper([
            'loqate_settings/ipcountry_settings/enable_customer_account' => 1,
            'loqate_settings/ipcountry_settings/enable_checkout' => 1,
        ]);

        $extra = $this->createMock(Extra::class);
        $extra->method('ipToCountry')->willReturnCallback(
            static function () use ($lookups) {
                // Counted, not merely stubbed: "the value survived the shopper change" is only
                // observable as a lookup that did not happen a second time.
                $lookups[] = 'Ip2Country';

                return ['Iso2' => strtolower(self::RESOLVED_COUNTRY)];
            }
        );

        return [
            'addressPlugin' => new ChangeAddressDefaultCountry(
                $this->createCountryFactory(),
                $extra,
                $helper,
                $sessionMock
            ),
            'checkoutPlugin' => new ChangeCheckoutDefaultCountry(
                $this->createCountryFactory(),
                $extra,
                $helper,
                $sessionMock
            ),
            'placeOrder' => new PlaceOrder($sessionMock),
            'session' => $sessionStore,
            'identity' => $identity,
            'lookups' => $lookups,
        ];
    }

    /**
     * A CountryFactory whose model recognises the resolved ISO code.
     *
     * @return CountryFactory
     */
    private function createCountryFactory(): CountryFactory
    {
        $factory = $this->createMock(CountryFactory::class);
        $factory->method('create')->willReturn(new class {
            /** @var string */
            private $code = '';

            /**
             * @param string $code
             * @return $this
             */
            public function loadByCode($code)
            {
                $this->code = (string)$code;

                return $this;
            }

            /**
             * @return int
             */
            public function getId()
            {
                return 1;
            }

            /**
             * @return string
             */
            public function getCountryId()
            {
                return $this->code;
            }
        });

        return $factory;
    }

    /**
     * The country one of the two plugins pre-selects, whichever form the shopper is looking at.
     *
     * @param array<string, mixed> $harness
     * @param string $plugin 'address' or 'checkout'.
     */
    private function preselectedCountry(array $harness, string $plugin): string
    {
        if ($plugin === 'address') {
            return (string)$harness['addressPlugin']->afterGetCountryId(
                $this->createMock(AddressInterface::class),
                // Empty, which is what an address with no country chosen yet answers - the only
                // case in which the plugin substitutes anything.
                ''
            );
        }

        $layout = $harness['checkoutPlugin']->afterProcess(
            $this->createMock(LayoutProcessorInterface::class),
            $this->checkoutLayout()
        );

        return (string)($layout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children']
            ['country_id']['value'] ?? '');
    }

    /**
     * The one branch of Magento's checkout jsLayout the plugin writes into.
     *
     * Only the shipping-address fieldset is built: the plugin guards every path it touches with
     * isset(), so a layout carrying one of them exercises the substitution without this fixture
     * having to reproduce the whole checkout component tree.
     *
     * @return array<string, mixed>
     */
    private function checkoutLayout(): array
    {
        return ['components' => ['checkout' => ['children' => ['steps' => ['children' => [
            'shipping-step' => ['children' => ['shippingAddress' => ['children' => [
                'shipping-address-fieldset' => ['children' => ['country_id' => ['value' => '']]],
            ]]]],
        ]]]]]];
    }

    /**
     * Make the place-order call the way checkout does, swallowing the refusal.
     *
     * The refusal is not what this file is about - BillingErrorGateTest owns it - but the CALL
     * is, because it is a real request that reaches a shopper-scoped store through the guard and
     * therefore triggers the flush exactly as production does.
     *
     * @param array<string, mixed> $harness
     */
    private function placeOrderIsAttempted(array $harness): void
    {
        try {
            $harness['placeOrder']->beforeSavePaymentInformationAndPlaceOrder(
                $this->createMock(\Magento\Checkout\Model\PaymentInformationManagement::class),
                1,
                'checkmo',
                null
            );
        } catch (CouldNotSaveException $e) {
            // Expected while the seeded billing-error gate is still set; the point of the call
            // here is that it reaches the store through the guard.
        }
    }

    /**
     * The current value of every shopper-scoped store, in the order seededStores() lists them.
     *
     * @param array<string, mixed> $harness
     * @return array<string, mixed>
     */
    private function shopperScopedAttributes(array $harness): array
    {
        $values = [];
        foreach (array_keys($this->seededStores()) as $key) {
            $values[$key] = $harness['session'][$key] ?? null;
        }

        return $values;
    }
}
