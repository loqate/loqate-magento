<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the captured-address (Loqate lookup) bypass logic in Validator.
 *
 * Regression cover for LOQ: addresses selected from the Loqate lookup were being
 * re-verified at checkout and rejected, because the captured-address match never
 * succeeded (Magento stores the street as an array under a single "street" key,
 * and the lookup stores a province name where Magento supplies its own region).
 */
class ValidatorTest extends TestCase
{
    /** @var Validator */
    private $validator;

    /** @var Data|MockObject */
    private $helper;

    protected function setUp(): void
    {
        $logger = $this->createMock(Logger::class);
        $session = $this->createMock(Session::class);
        $regionFactory = $this->createMock(RegionFactory::class);
        $moduleList = $this->createMock(ModuleListInterface::class);

        $this->helper = $this->createMock(Data::class);
        // Empty API key keeps the constructor from instantiating the live Verify
        // client; the captured-address matching under test never needs the API.
        $this->helper->method('getConfigValue')->willReturn('');

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(
            static fn ($value) => json_encode($value)
        );
        $serializer->method('unserialize')->willReturnCallback(
            static fn ($value) => json_decode($value, true)
        );

        $this->validator = new Validator(
            $logger,
            $session,
            $regionFactory,
            $moduleList,
            $this->helper,
            $serializer
        );
    }

    /**
     * parseAddress must turn Magento's array street into discrete Loqate lines,
     * since Magento has no street_1/street_2 fields.
     */
    public function testParseAddressExtractsArrayStreetIntoLoqateLines(): void
    {
        $parsed = $this->invokePrivate('parseAddress', [[
            'street' => ['123 High Street', 'Flat 2'],
            'city' => 'London',
            'region' => 'London',
            'postcode' => 'SW1A 1AA',
            'country_id' => 'GB',
        ]]);

        $this->assertSame('123 High Street', $parsed['Address1']);
        $this->assertSame('Flat 2', $parsed['Address2']);
        $this->assertSame('London', $parsed['Address3']);
        $this->assertSame('SW1A 1AA', $parsed['PostalCode']);
        $this->assertSame('GB', $parsed['Country']);
    }

    /**
     * The core regression: an address picked from the Loqate lookup must be
     * recognised as already captured at checkout, even though Magento delivers
     * the street as an array and a region name that differs from the lookup's
     * province name.
     */
    public function testCapturedLookupAddressIsMatchedAtCheckout(): void
    {
        $stored = [$this->storedCapturedAddress([
            'Line1' => '123 High Street',
            'Line2' => 'Flat 2',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London', // province name, differs from region below
        ])];

        $parsed = $this->invokePrivate('parseAddress', [[
            'street' => ['123 High Street', 'Flat 2'],
            'city' => 'London',
            'region' => 'London',
            'postcode' => 'SW1A 1AA',
            'country_id' => 'GB',
        ]]);

        $this->assertTrue(
            $this->invokePrivate('checkForCapturedAddress', [$parsed, $stored]),
            'A lookup-selected address should bypass verification at checkout.'
        );
    }

    /**
     * Trivial formatting differences (case, extra whitespace) must not break the
     * match, otherwise valid lookup addresses still get re-verified.
     */
    public function testMatchToleratesCaseAndWhitespace(): void
    {
        $stored = [$this->storedCapturedAddress([
            'Line1' => '123 High Street',
            'Line2' => '',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ])];

        $parsed = $this->invokePrivate('parseAddress', [[
            'street' => ['123  high   street'],
            'city' => 'LONDON',
            'region' => 'London',
            'postcode' => 'sw1a 1aa',
            'country_id' => 'gb',
        ]]);

        $this->assertTrue(
            $this->invokePrivate('checkForCapturedAddress', [$parsed, $stored])
        );
    }

    /**
     * A genuinely different address must NOT be treated as captured - guard
     * against the normalisation being so loose it bypasses verification for
     * everything.
     */
    public function testDifferentAddressIsNotMatched(): void
    {
        $stored = [$this->storedCapturedAddress([
            'Line1' => '123 High Street',
            'Line2' => 'Flat 2',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ])];

        $parsed = $this->invokePrivate('parseAddress', [[
            'street' => ['9 Different Road'],
            'city' => 'Manchester',
            'region' => 'Greater Manchester',
            'postcode' => 'M1 1AA',
            'country_id' => 'GB',
        ]]);

        $this->assertFalse(
            $this->invokePrivate('checkForCapturedAddress', [$parsed, $stored])
        );
    }

    /**
     * An empty/blank address never matches, so it cannot accidentally bypass
     * verification.
     */
    public function testEmptyAddressIsNotMatched(): void
    {
        $stored = [$this->storedCapturedAddress([
            'Line1' => '123 High Street',
            'Line2' => 'Flat 2',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ])];

        $this->assertFalse(
            $this->invokePrivate('checkForCapturedAddress', [['Address' => ''], $stored])
        );
    }

    /**
     * Replicates Controller::storeCapturedAddress: maps a Loqate lookup response
     * onto the captured-address store using ADDRESS_CAPTURE_MAPPING and serialises it.
     */
    private function storedCapturedAddress(array $lookupResult): string
    {
        $storeArray = [];
        foreach (Validator::ADDRESS_CAPTURE_MAPPING as $key => $value) {
            $storeArray[$key] = $lookupResult[$value] ?? '';
        }

        return json_encode($storeArray);
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function invokePrivate(string $method, array $args)
    {
        $reflection = new ReflectionMethod(Validator::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->validator, $args);
    }
}
