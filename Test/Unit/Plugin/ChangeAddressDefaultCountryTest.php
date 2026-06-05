<?php

declare(strict_types=1);

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Extra;
use Loqate\ApiIntegration\Plugin\ChangeAddressDefaultCountry;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\CountryFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the customer-address default-country plugin, which pre-fills the
 * country from the visitor's IP (via Loqate) when no country is set yet.
 */
class ChangeAddressDefaultCountryTest extends TestCase
{
    private const CONFIG_PATH = 'loqate_settings/ipcountry_settings/enable_customer_account';

    /** @var CountryFactory&MockObject */
    private $countryFactory;

    /** @var Extra&MockObject */
    private $extra;

    /** @var Data&MockObject */
    private $helper;

    /** @var Session&MockObject */
    private $session;

    /** @var ChangeAddressDefaultCountry */
    private $plugin;

    protected function setUp(): void
    {
        $this->countryFactory = $this->createMock(CountryFactory::class);
        $this->extra = $this->createMock(Extra::class);
        $this->helper = $this->createMock(Data::class);
        $this->session = $this->createMock(Session::class);

        $this->plugin = new ChangeAddressDefaultCountry(
            $this->countryFactory,
            $this->extra,
            $this->helper,
            $this->session
        );
    }

    /**
     * A country model double that resolves a code to a Magento country id.
     */
    private function countryModel(?int $id, string $countryId = 'GB'): object
    {
        return new class($id, $countryId) {
            public function __construct(private ?int $id, private string $countryId)
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

            public function getCountryId(): string
            {
                return $this->countryId;
            }
        };
    }

    public function testReturnsResultUnchangedWhenDisabled(): void
    {
        $this->helper->method('getConfigValue')->with(self::CONFIG_PATH)->willReturn(false);
        $this->extra->expects($this->never())->method('ipToCountry');
        $this->countryFactory->expects($this->never())->method('create');

        $subject = $this->createMock(AddressInterface::class);

        $this->assertSame('US', $this->plugin->afterGetCountryId($subject, 'US'));
    }

    public function testResolvesCountryFromIpWhenResultEmpty(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->with('loqate_ipcountry')->willReturn(null);
        $this->extra->method('ipToCountry')->willReturn(['Iso2' => 'gb']);
        $this->countryFactory->method('create')->willReturn($this->countryModel(826, 'GB'));

        $subject = $this->createMock(AddressInterface::class);

        $this->assertSame('GB', $this->plugin->afterGetCountryId($subject, ''));
    }

    public function testKeepsExistingResultWhenNotEmpty(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->willReturn(['Iso2' => 'GB']);
        $this->countryFactory->expects($this->never())->method('create');

        $subject = $this->createMock(AddressInterface::class);

        $this->assertSame('FR', $this->plugin->afterGetCountryId($subject, 'FR'));
    }

    public function testUsesCachedIpCountryFromSession(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->with('loqate_ipcountry')->willReturn(['Iso2' => 'GB']);
        // Cached value present -> the IP lookup must not be called again.
        $this->extra->expects($this->never())->method('ipToCountry');
        $this->countryFactory->method('create')->willReturn($this->countryModel(826, 'GB'));

        $subject = $this->createMock(AddressInterface::class);

        $this->assertSame('GB', $this->plugin->afterGetCountryId($subject, ''));
    }

    public function testKeepsResultWhenCountryCannotBeResolved(): void
    {
        $this->helper->method('getConfigValue')->willReturn(true);
        $this->session->method('getData')->willReturn(['Iso2' => 'ZZ']);
        // Unknown country -> getId() returns null, original (empty) result is kept.
        $this->countryFactory->method('create')->willReturn($this->countryModel(null));

        $subject = $this->createMock(AddressInterface::class);

        $this->assertSame('', $this->plugin->afterGetCountryId($subject, ''));
    }
}
