<?php

declare(strict_types=1);

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Extra;
use Loqate\ApiIntegration\Plugin\ChangeCheckoutDefaultCountry;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\CountryFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the checkout default-country plugin, which injects the IP-derived
 * country into the checkout jsLayout when enabled.
 */
class ChangeCheckoutDefaultCountryTest extends TestCase
{
    private const CONFIG_PATH = 'loqate_settings/ipcountry_settings/enable_checkout';

    /** @var CountryFactory&MockObject */
    private $countryFactory;

    /** @var Extra&MockObject */
    private $extra;

    /** @var Data&MockObject */
    private $helper;

    /** @var Session&MockObject */
    private $session;

    /** @var ChangeCheckoutDefaultCountry */
    private $plugin;

    protected function setUp(): void
    {
        $this->countryFactory = $this->createMock(CountryFactory::class);
        $this->extra = $this->createMock(Extra::class);
        $this->helper = $this->createMock(Data::class);
        $this->session = $this->createMock(Session::class);

        $this->plugin = new ChangeCheckoutDefaultCountry(
            $this->countryFactory,
            $this->extra,
            $this->helper,
            $this->session
        );
    }

    private function countryModel(?int $id): object
    {
        return new class($id) {
            public function __construct(private ?int $id)
            {
            }

            public function loadByCode($code): self
            {
                return $this;
            }

            public function getId(): ?int
            {
                return $this->id;
            }
        };
    }

    /**
     * Builds a minimal jsLayout containing the shipping country_id node the
     * plugin targets.
     */
    private function layoutWithShippingCountry(string $value = ''): array
    {
        return [
            'components' => ['checkout' => ['children' => ['steps' => ['children' => [
                'shipping-step' => ['children' => ['shippingAddress' => ['children' => [
                    'shipping-address-fieldset' => ['children' => [
                        'country_id' => ['value' => $value],
                    ]],
                ]]]],
            ]]]]],
        ];
    }

    private function shippingCountryValue(array $jsLayout): mixed
    {
        return $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['country_id']['value'];
    }

    public function testReturnsLayoutUnchangedWhenDisabled(): void
    {
        $this->helper->method('getConfigValue')->with(self::CONFIG_PATH)->willReturn(false);
        $this->extra->expects($this->never())->method('ipToCountry');

        $layout = $this->layoutWithShippingCountry('US');

        $this->assertSame($layout, $this->plugin->afterProcess($this->subject(), $layout));
    }

    public function testInjectsResolvedCountryIntoShippingFieldset(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->with('loqate_ipcountry')->willReturn(null);
        $this->extra->method('ipToCountry')->willReturn(['Iso2' => 'gb']);
        $this->countryFactory->method('create')->willReturn($this->countryModel(826));

        $result = $this->plugin->afterProcess($this->subject(), $this->layoutWithShippingCountry());

        $this->assertSame('GB', $this->shippingCountryValue($result));
    }

    public function testLeavesLayoutUnchangedWhenCountryCannotBeResolved(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->willReturn(['Iso2' => 'ZZ']);
        $this->countryFactory->method('create')->willReturn($this->countryModel(null));

        $layout = $this->layoutWithShippingCountry('US');

        $this->assertSame('US', $this->shippingCountryValue($this->plugin->afterProcess($this->subject(), $layout)));
    }

    public function testIgnoresMissingIso2(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->willReturn(null);
        $this->extra->method('ipToCountry')->willReturn(['error' => true]); // no Iso2
        $this->countryFactory->expects($this->never())->method('create');

        $layout = $this->layoutWithShippingCountry('US');

        $this->assertSame('US', $this->shippingCountryValue($this->plugin->afterProcess($this->subject(), $layout)));
    }

    private function subject(): LayoutProcessorInterface
    {
        return $this->createMock(LayoutProcessorInterface::class);
    }
}
