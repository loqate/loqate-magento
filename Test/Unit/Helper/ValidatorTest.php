<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use ArrayObject;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Loqate\ApiIntegration\Test\Unit\Plugin\ShopperSessionHarness;
use Magento\Customer\Model\Session;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
    // A remembering session, a config helper and store id - for the validateEmail() tests, which
    // need the "already warned" list to persist between calls.
    use ShopperSessionHarness;

    /** Config path: reject an invalid email outright (1) or warn once and accept a resubmission (0). */
    private const EMAIL_PREVENT_SUBMIT = 'loqate_settings/email_settings/prevent_submit';

    /** An email address with nothing special about it. */
    private const EMAIL = 'guest.shopper@example.com';

    private const SUBMIT_AGAIN_MESSAGE = 'Invalid email address. Submit again to use this email address.';
    private const INVALID_MESSAGE = 'The provided email address is invalid.';
    private const UNEXPECTED_ERROR_MESSAGE = 'An unexpected error occurred while trying to validate your email address.';

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
     * The captured-address match must keep working for stores that configure more than
     * two street lines (customer/address/street_lines), where Magento delivers a third
     * or fourth line the Loqate lookup has no field for at all: it stores Line1/Line2
     * only (ADDRESS_CAPTURE_MAPPING).
     *
     * This is the regression risk of folding the joined street into the VERIFY cache
     * keys: buildAddressSignature() is shared between that cache and this comparison,
     * so had 'Address' been added to the signature itself, every captured entry - which
     * has no 'Address' key and would normalise it to '' - would stop matching, and every
     * address picked from the lookup would be re-verified and possibly rejected. That is
     * exactly the defect the previous ticket fixed, so it is pinned here.
     */
    public function testCapturedLookupAddressWithMoreThanTwoStreetLinesIsStillMatched(): void
    {
        $stored = [$this->storedCapturedAddress([
            'Line1' => '123 High Street',
            'Line2' => 'Flat 2',
            'CountryIso2' => 'GB',
            'PostalCode' => 'SW1A 1AA',
            'City' => 'London',
            'ProvinceName' => 'Greater London',
        ])];

        foreach (
            [
                'third line' => ['123 High Street', 'Flat 2', 'Block C'],
                'third and fourth lines' => ['123 High Street', 'Flat 2', 'Block C', 'Floor 9'],
            ] as $label => $street
        ) {
            $parsed = $this->invokePrivate('parseAddress', [[
                'street' => $street,
                'city' => 'London',
                'region' => 'London',
                'postcode' => 'SW1A 1AA',
                'country_id' => 'GB',
            ]]);

            $this->assertNotSame(
                '',
                $parsed['Address'],
                'The joined street must be populated, so this really is the ' . $label . ' case.'
            );
            $this->assertTrue(
                $this->invokePrivate('checkForCapturedAddress', [$parsed, $stored]),
                'A lookup-selected address must still be recognised as captured with a ' . $label
                . ' Magento supplied but the lookup never stored.'
            );
        }
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
     * Validator::validateEmail() (LOQ-19188) - what Observer\QuoteSubmitBefore uses on Hyvä
     * Checkout - returns the same verdict as Plugin\AbstractPlugin::validateEmail() for every
     * answer verifyEmail() can give. Only the billable verifyEmail() is stubbed; the
     * prevent_submit branch, the session store and the message choice are all real.
     *
     * @param string $preventSubmit loqate_settings/email_settings/prevent_submit.
     * @param mixed $verifyResponse What verifyEmail() answers.
     * @param false|string $expected What validateEmail() must return.
     */
    #[DataProvider('validateEmailVerdictProvider')]
    public function testValidateEmailTurnsEachVerifyAnswerIntoTheShopperFacingVerdict(
        string $preventSubmit,
        $verifyResponse,
        $expected
    ): void {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => $preventSubmit], $verifyResponse);

        $verdict = $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertSame($expected, $verdict);
        $this->assertSame([self::EMAIL], iterator_to_array($fixture['emailRequests']), 'One billable verify per first submission.');
    }

    public static function validateEmailVerdictProvider(): array
    {
        return [
            'invalid, prevent submit No: warn once' => ['', false, self::SUBMIT_AGAIN_MESSAGE],
            'invalid, prevent submit Yes: reject' => ['1', false, self::INVALID_MESSAGE],
            'API error, prevent submit No' => ['', ['error' => true, 'message' => 'timeout'], self::UNEXPECTED_ERROR_MESSAGE],
            'API error, prevent submit Yes' => ['1', ['error' => true, 'message' => 'timeout'], self::UNEXPECTED_ERROR_MESSAGE],
            'no API key: let it through' => ['', ['noKeyFound' => true], false],
            'valid' => ['1', ['Valid' => true], false],
        ];
    }

    /**
     * Prevent Submit = No (the shipped default): the first submission is billed and warned, and
     * placing the order again with the SAME email is accepted with no second billable call.
     * This is the "submit again" contract the CHANGELOG promises for Hyvä Checkout.
     */
    public function testValidateEmailWithPreventSubmitOffAcceptsTheResubmissionWithoutBillingAgain(): void
    {
        $fixture = $this->emailFixture([], false);

        $first = $fixture['validator']->validateEmail(self::EMAIL);
        $second = $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertSame(self::SUBMIT_AGAIN_MESSAGE, $first, 'The first submission of an invalid email is warned.');
        $this->assertFalse($second, 'The resubmission of the same email must be accepted.');
        $this->assertCount(
            1,
            $fixture['emailRequests'],
            'The resubmission must be answered from the session, not by a second billable verifyEmail().'
        );
    }

    /**
     * Prevent Submit = Yes: the session list is never consulted, so the same invalid email is
     * verified and rejected every time - otherwise resubmitting would be a way past the block.
     */
    public function testValidateEmailWithPreventSubmitOnRejectsEveryResubmission(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], false);

        $first = $fixture['validator']->validateEmail(self::EMAIL);
        $second = $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertSame(self::INVALID_MESSAGE, $first);
        $this->assertSame(self::INVALID_MESSAGE, $second, 'With Prevent Submit = Yes a resubmission must still be rejected.');
        $this->assertCount(2, $fixture['emailRequests'], 'Each submission is verified.');
    }

    /**
     * validateEmail($email, false) - what Observer\QuoteSubmitBefore calls - lets an email API
     * error through, so an outage cannot block the order, under both Prevent Submit settings.
     * It must lower ONLY that verdict: an email Loqate genuinely rejected still gets its message.
     *
     * @param string $preventSubmit loqate_settings/email_settings/prevent_submit.
     * @param mixed $verifyResponse What verifyEmail() answers.
     * @param false|string $expected What validateEmail() must return.
     */
    #[DataProvider('validateEmailNotBlockingOnApiErrorProvider')]
    public function testValidateEmailNotBlockingOnApiErrorPassesOnlyTheApiError(
        string $preventSubmit,
        $verifyResponse,
        $expected
    ): void {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => $preventSubmit], $verifyResponse);

        $verdict = $fixture['validator']->validateEmail(self::EMAIL, false);

        $this->assertSame($expected, $verdict);
        $this->assertSame([self::EMAIL], iterator_to_array($fixture['emailRequests']), 'The email is still verified.');
    }

    public static function validateEmailNotBlockingOnApiErrorProvider(): array
    {
        return [
            'API error, prevent submit No: pass' => ['', ['error' => true, 'message' => 'timeout'], false],
            'API error, prevent submit Yes: pass' => ['1', ['error' => true, 'message' => 'timeout'], false],
            'invalid, prevent submit Yes: still rejected' => ['1', false, self::INVALID_MESSAGE],
            'invalid, prevent submit No: still warned' => ['', false, self::SUBMIT_AGAIN_MESSAGE],
        ];
    }

    /**
     * ...and with Prevent Submit = No the warning is still given ONCE: the resubmission is
     * accepted without a second billable call, exactly as with the default argument.
     */
    public function testValidateEmailNotBlockingOnApiErrorStillWarnsOnceWithPreventSubmitOff(): void
    {
        $fixture = $this->emailFixture([], false);

        $first = $fixture['validator']->validateEmail(self::EMAIL, false);
        $second = $fixture['validator']->validateEmail(self::EMAIL, false);

        $this->assertSame(self::SUBMIT_AGAIN_MESSAGE, $first, 'The first submission of an invalid email is warned.');
        $this->assertFalse($second, 'The resubmission of the same email must be accepted.');
        $this->assertCount(1, $fixture['emailRequests'], 'The resubmission must not be billed again.');
    }

    /**
     * A shopper warned on Luma's shipping step (Plugin\AbstractPlugin) who then places the order
     * must not be warned and billed a second time by Validator::validateEmail() on quote submit.
     *
     * This is what passing $helper to ShopperScopedSessionStores in Validator's constructor
     * buys: the digest is namespaced by store view, and a Validator without the helper scopes
     * it as '-' while the plugin scopes it as the store id, so neither would ever find the
     * other's entry.
     */
    public function testAWarningRecordedThroughThePluginIsHonouredByValidateEmail(): void
    {
        $fixture = $this->emailFixture([], false);
        $plugin = new class (
            $this->createMock(Context::class),
            $this->createMock(UrlInterface::class),
            $fixture['session'],
            $fixture['validator'],
            $fixture['helper'],
            $this->createMock(JsonFactory::class)
        ) extends AbstractPlugin {
            /**
             * @param mixed $email
             * @return false|string
             */
            public function checkEmail($email)
            {
                return $this->validateEmail($email);
            }
        };

        $pluginVerdict = $plugin->checkEmail(self::EMAIL);
        $validatorVerdict = $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertSame(self::SUBMIT_AGAIN_MESSAGE, $pluginVerdict, 'The plugin path warns the first submission.');
        $this->assertFalse(
            $validatorVerdict,
            'Validator::validateEmail() must recognise the warning the plugin recorded in the same session.'
        );
        $this->assertCount(
            1,
            $fixture['emailRequests'],
            'The same email in the same session and store view must be billed once across both paths.'
        );
    }

    /**
     * A real Validator over a remembering session, with only the billable verifyEmail() stubbed
     * and counted. No API key is configured, so the constructor builds no live client.
     *
     * @param array<string, mixed> $config Configuration paths that are switched on.
     * @param mixed $verifyResponse What verifyEmail() answers.
     * @return array{validator: Validator, session: Session, helper: Data, emailRequests: ArrayObject}
     */
    private function emailFixture(array $config, $verifyResponse): array
    {
        $emailRequests = new ArrayObject();
        $session = $this->createSessionDouble(new ArrayObject(), new ArrayObject(['customerId' => null]));
        $helper = $this->createConfigHelper($config);

        $validator = $this->getMockBuilder(Validator::class)
            ->setConstructorArgs([
                $this->createMock(Logger::class),
                $session,
                $this->createMock(RegionFactory::class),
                $this->createMock(ModuleListInterface::class),
                $helper,
                $this->createMock(SerializerInterface::class),
            ])
            ->onlyMethods(['verifyEmail'])
            ->getMock();
        $validator->method('verifyEmail')->willReturnCallback(
            static function ($email) use ($emailRequests, $verifyResponse) {
                $emailRequests[] = $email;

                return $verifyResponse;
            }
        );

        return ['validator' => $validator, 'session' => $session, 'helper' => $helper, 'emailRequests' => $emailRequests];
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
