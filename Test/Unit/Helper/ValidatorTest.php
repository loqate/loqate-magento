<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Loqate\ApiIntegration\Helper\Validator;
use ArrayObject;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Plugin\AbstractPlugin;
use Loqate\ApiIntegration\Test\Unit\Plugin\ShopperSessionHarness;
use Magento\Customer\Model\Session;
use Loqate\ApiConnector\Client\Http\HttpClient;
use Loqate\ApiConnector\Client\Verify;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

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

    /** Config path: accept emails Loqate reports as valid catch-all (sent to the API as a flag). */
    private const EMAIL_CATCH_ALL = 'loqate_settings/email_settings/enable_accept_valid_catch_all';

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
            $serializer,
            $this->createMock(NotifierInterface::class),
            $this->createMock(CacheInterface::class)
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
            'Transport error, prevent submit No' => ['', ['error' => true, 'message' => 'cURL error 28: Operation timed out'], self::UNEXPECTED_ERROR_MESSAGE],
            'Transport error, prevent submit Yes' => ['1', ['error' => true, 'message' => 'cURL error 28: Operation timed out'], self::UNEXPECTED_ERROR_MESSAGE],
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
            'Transport error, prevent submit No: pass' => ['', ['error' => true, 'message' => 'cURL error 28: Operation timed out'], false],
            'Transport error, prevent submit Yes: pass' => ['1', ['error' => true, 'message' => 'cURL error 28: Operation timed out'], false],
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
     * LOQ-20299: with $usePassCache a passed email is not billed a second time in the session,
     * under BOTH Prevent Submit settings - the pass list is consulted before the prevent_submit
     * branch, so Yes (where the "already warned" list is never read) benefits too. The list
     * holds digests only: the raw address is nowhere in the session.
     *
     * @param string $preventSubmit loqate_settings/email_settings/prevent_submit.
     */
    #[DataProvider('preventSubmitProvider')]
    public function testWithThePassCacheAPassedEmailIsNotBilledAgain(string $preventSubmit): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => $preventSubmit], ['Valid' => true]);

        $first = $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $second = $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertFalse($first, 'A valid email has no error.');
        $this->assertFalse($second, 'A pass reused from the session has no error either.');
        $this->assertCount(1, $fixture['emailRequests'], 'The second check of a passed email must make no billable call.');
        $this->assertCount(1, (array)$this->passList($fixture), 'Exactly one pass is recorded.');
        $this->assertStringNotContainsString(
            self::EMAIL,
            $this->sessionPayload($fixture['sessionStore']),
            'The pass list holds digests, never the customer\'s email address.'
        );
    }

    /**
     * ...and WITHOUT $usePassCache - the default, which is what the admin and account plugins use
     * through Plugin\AbstractPlugin::validateEmail() - nothing is written to the pass list. Under
     * Prevent Submit = Yes that means every save is billed.
     */
    public function testWithoutThePassCacheEveryCallIsBilledAndNoPassIsRecorded(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], ['Valid' => true]);

        $fixture['validator']->validateEmail(self::EMAIL);
        $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertCount(2, $fixture['emailRequests'], 'Admin and account saves verify on every save.');
        $this->assertNull($this->passList($fixture), 'A caller that did not opt in must not write the pass list.');
    }

    /**
     * A pass earned at checkout must not exempt the admin/account path: the default caller does
     * not READ the pass list either.
     */
    public function testAPassEarnedAtCheckoutIsIgnoredByACallerThatDidNotOptIn(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], ['Valid' => true]);

        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['validator']->validateEmail(self::EMAIL);

        $this->assertCount(2, $fixture['emailRequests'], 'The default caller must verify despite the checkout pass.');
    }

    /**
     * Only a real pass is cached. An invalid verdict, an API error (blocking or let through, as
     * the observer does) and noKeyFound are NOT recorded, so the next check - Prevent Submit =
     * Yes, where nothing else short-circuits - is billed again. Otherwise an outage or a missing
     * key would mark an address valid for the rest of the session.
     *
     * @param mixed $verifyResponse What verifyEmail() answers.
     * @param bool $blockOnApiError What the caller passes.
     */
    #[DataProvider('notAPassProvider')]
    public function testOnlyAPassIsCachedSoAnyOtherAnswerIsBilledAgain($verifyResponse, bool $blockOnApiError): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], $verifyResponse);

        $fixture['validator']->validateEmail(self::EMAIL, $blockOnApiError, true);
        $fixture['validator']->validateEmail(self::EMAIL, $blockOnApiError, true);

        $this->assertCount(2, $fixture['emailRequests'], 'Nothing but a pass may spare the next billable call.');
        $this->assertNull($this->passList($fixture), 'Nothing but a pass may be written to the pass list.');
    }

    public static function notAPassProvider(): array
    {
        return [
            'invalid' => [false, true],
            'Transport error, blocking (shipping step)' => [['error' => true, 'message' => 'cURL error 28: Operation timed out'], true],
            'Transport error, let through (quote submit)' => [['error' => true, 'message' => 'cURL error 28: Operation timed out'], false],
            'no API key' => [['noKeyFound' => true], true],
        ];
    }

    /**
     * A pass is for THAT address: a changed email is billed, and the earlier pass still stands.
     */
    public function testAChangedEmailIsAlwaysBilled(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], ['Valid' => true]);

        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['validator']->validateEmail('another.shopper@example.com', usePassCache: true);
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertSame(
            [self::EMAIL, 'another.shopper@example.com'],
            iterator_to_array($fixture['emailRequests']),
            'The new address must be verified; returning to the first one must not re-bill it.'
        );
    }

    /**
     * The pass list is enrolled in the shopper flush: after a login, a logout or a switch of
     * account a previously passed email is billed again - and then cached for the new owner.
     *
     * @param int|null $before Identity that earned the pass.
     * @param int|null $after Identity making the next check.
     */
    #[DataProvider('shopperChangeProvider')]
    public function testAPassDoesNotSurviveAShopperChange(?int $before, ?int $after): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], ['Valid' => true]);
        $fixture['identity']['customerId'] = $before;
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $this->assertCount(1, $fixture['emailRequests'], 'Fixture guard: the pass must be reused before the change.');

        $fixture['identity']['customerId'] = $after;
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertCount(
            2,
            $fixture['emailRequests'],
            'The new shopper must be billed once for the address - never served the previous shopper\'s pass, '
            . 'and not billed on every check either.'
        );
    }

    public static function shopperChangeProvider(): array
    {
        return [
            'guest logs in' => [null, 7],
            'customer logs out' => [7, null],
            'one customer, then another' => [7, 8],
        ];
    }

    /**
     * Passes are namespaced by store view, like the "already warned" list: each store view can
     * have its own API key and settings, so a pass earned in one is not reused in another.
     */
    public function testAPassIsReusedOnlyInTheStoreViewThatEarnedIt(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_PREVENT_SUBMIT => '1'], ['Valid' => true]);

        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['storeId']['id'] = 2;
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['storeId']['id'] = 1;
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertCount(2, $fixture['emailRequests'], 'Billed once per store view, and only once.');
    }

    /**
     * Fix iteration 1: whether an email passes depends on enable_accept_valid_catch_all (a
     * catch-all domain passes only with it on), so a pass is keyed by that setting. Unchanged,
     * the pass is reused; flipped either way, the email is billed again rather than served a
     * pass earned under the other rule.
     *
     * @param string $before Catch-all setting when the pass is earned.
     * @param string $after Catch-all setting after the merchant changes it.
     */
    #[DataProvider('catchAllFlipProvider')]
    public function testAPassIsNotReusedAfterTheCatchAllSettingChanges(string $before, string $after): void
    {
        $fixture = $this->emailFixture(
            [self::EMAIL_PREVENT_SUBMIT => '1', self::EMAIL_CATCH_ALL => $before],
            ['Valid' => true]
        );
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $this->assertCount(1, $fixture['emailRequests'], 'With the setting unchanged the pass is reused: 0 extra calls.');

        $fixture['config'][self::EMAIL_CATCH_ALL] = $after;
        $verdict = $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertFalse($verdict);
        $this->assertCount(
            2,
            $fixture['emailRequests'],
            'A pass earned under the other catch-all rule must not be reused: the email is billed again.'
        );
    }

    public static function catchAllFlipProvider(): array
    {
        return [
            'catch-all ON, then OFF' => ['1', ''],
            'catch-all OFF, then ON' => ['', '1'],
        ];
    }

    /**
     * A non-scalar email (a crafted `email[]=` POST) is never remembered or matched through the
     * pass cache: the prefix must not turn it into a digestible string.
     */
    public function testANonScalarEmailIsNeverRememberedNorMatchedByThePassCache(): void
    {
        $fixture = $this->emailFixture(
            [self::EMAIL_PREVENT_SUBMIT => '1', self::EMAIL_CATCH_ALL => '1'],
            ['Valid' => true]
        );

        $fixture['validator']->validateEmail([self::EMAIL], usePassCache: true);
        $fixture['validator']->validateEmail([self::EMAIL], usePassCache: true);

        $this->assertCount(2, $fixture['emailRequests'], 'Every check of an array value is verified.');
        $this->assertNull($this->passList($fixture), 'Nothing may be stored for an array value.');
    }

    /**
     * The catch-all key belongs to the PASS list only. With Prevent Submit = No a warning given
     * with catch-all on is still honoured after it is switched off: the resubmission is answered
     * by the "already warned" list with no billable call, so the prefix did not leak into it.
     */
    public function testTheWarnedListIsNotKeyedByTheCatchAllSetting(): void
    {
        $fixture = $this->emailFixture([self::EMAIL_CATCH_ALL => '1'], false);

        $first = $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);
        $fixture['config'][self::EMAIL_CATCH_ALL] = '';
        $second = $fixture['validator']->validateEmail(self::EMAIL, usePassCache: true);

        $this->assertSame(self::SUBMIT_AGAIN_MESSAGE, $first, 'Fixture guard: the first submission is warned.');
        $this->assertFalse($second, 'The resubmission is accepted from the warned list.');
        $this->assertCount(1, $fixture['emailRequests'], 'And it costs no second billable call.');
    }

    public static function preventSubmitProvider(): array
    {
        return [
            'prevent submit Yes' => ['1'],
            'prevent submit No' => [''],
        ];
    }

    /**
     * SA-2: an email API error is a fault the merchant has to act on (bad key, no credit, outage),
     * and the quote-submit path now lets such orders through unchecked. So it is logged as a
     * WARNING: the level is what log shippers and alerting filter on.
     */
    public function testAnEmailApiErrorIsLoggedAsAWarningNotAsInfo(): void
    {
        $fixture = $this->emailApiFixture(['error' => true, 'message' => 'Loqate email API timed out']);

        $fixture['validator']->verifyEmail(self::EMAIL);

        $this->assertSame(
            [],
            $this->logMessages($fixture, 'info'),
            'The email API error must no longer be logged at info level.'
        );
        $warnings = $this->logMessages($fixture, 'warning');
        $this->assertCount(1, $warnings, 'The email API error must be logged exactly once, as a warning.');
        $this->assertStringContainsString(
            'Loqate email API timed out',
            $warnings[0],
            'The warning must carry the SDK\'s error message, or the merchant cannot diagnose the fault.'
        );
    }

    /**
     * SA-2: the first email API error raises ONE major admin inbox notice and opens a one-hour
     * throttle window in the cache, so the merchant sees the fault without having to read logs.
     *
     * @param false|string $absent What CacheInterface::load() answers for a key it does not hold.
     */
    #[DataProvider('absentCacheValueProvider')]
    public function testTheFirstEmailApiErrorRaisesOneMajorAdminNoticeAndOpensAnHourLongThrottle($absent): void
    {
        $fixture = $this->emailApiFixture(['error' => true, 'message' => 'cURL error 28: Operation timed out'], $absent);

        $fixture['validator']->verifyEmail(self::EMAIL);

        $this->assertCount(1, $fixture['notices'], 'The first email API error must raise exactly one admin notice.');
        $this->assertCount(1, $fixture['cacheSaves'], 'The throttle must be recorded in the cache, once.');
        $save = $fixture['cacheSaves'][0];
        $this->assertSame(3600, (int)$save['lifetime'], 'The throttle window is one hour.');
        $this->assertNotFalse($save['data'], 'The throttle entry must hold something load() reports as present.');
        $this->assertNotSame('', (string)$save['data'], 'An empty entry would read back as absent.');
        $this->assertContains(
            $save['key'],
            iterator_to_array($fixture['cacheLoads']),
            'The throttle key saved must be the one checked before raising the notice.'
        );
    }

    public static function absentCacheValueProvider(): array
    {
        return [
            'load() answers false when absent' => [false],
            'load() answers an empty string when absent' => [''],
        ];
    }

    /**
     * SA-2: while the throttle entry is present, a further email API error raises NO notice - the
     * inbox must not get one entry per failed checkout during an outage.
     */
    public function testAnEmailApiErrorWhileTheThrottleIsActiveRaisesNoNotice(): void
    {
        // A cache that holds every key: whatever throttle key the Validator uses is present.
        $fixture = $this->emailApiFixture(['error' => true, 'message' => 'cURL error 28: Operation timed out'], false, '1');

        $fixture['validator']->verifyEmail(self::EMAIL);

        $this->assertCount(0, $fixture['notices'], 'No notice may be raised while the throttle is active.');
        $this->assertNotEmpty($fixture['cacheLoads'], 'Fixture guard: the throttle must actually be consulted.');
        $this->assertCount(1, $this->logMessages($fixture, 'warning'), 'The error is still logged as a warning.');
    }

    /**
     * SA-2: the throttle is install-wide and lives in the CACHE, not in the Validator instance or
     * per email: repeated errors, a later request (new Validator) and a DIFFERENT email within the
     * hour raise no further notice; once the entry expires, the next error raises one again.
     */
    public function testTheNoticeIsThrottledAcrossCallsRequestsAndEmailsUntilTheCacheEntryExpires(): void
    {
        $fixture = $this->emailApiFixture(['error' => true, 'message' => 'cURL error 28: Operation timed out']);

        $fixture['validator']->verifyEmail(self::EMAIL);
        $fixture['validator']->verifyEmail(self::EMAIL);
        $fixture['validator']->verifyEmail('another.shopper@example.com');
        $nextRequest = $this->emailApiFixture(
            ['error' => true, 'message' => 'cURL error 28: Operation timed out'],
            false,
            null,
            $fixture['cacheStore'],
            $fixture['notices']
        );
        $nextRequest['validator']->verifyEmail('third.shopper@example.com');

        $this->assertCount(1, $fixture['notices'], 'Within the hour only the first error may raise a notice.');

        // The hour passes: the cache entry is gone.
        $fixture['cacheStore']->exchangeArray([]);
        $nextRequest['validator']->verifyEmail(self::EMAIL);

        $this->assertCount(2, $fixture['notices'], 'Once the throttle entry expires the next error raises a notice again.');
    }

    /**
     * SA-2: an admin notice is visible to every admin user and stored in the inbox table, so it
     * must not carry the shopper's email address.
     */
    public function testTheAdminNoticeDoesNotContainTheEmailAddress(): void
    {
        $email = 'private.person+tag@shopper-domain.example';
        $fixture = $this->emailApiFixture(['error' => true, 'message' => 'cURL error 28: Operation timed out']);

        $fixture['validator']->verifyEmail($email);

        $this->assertCount(1, $fixture['notices'], 'Fixture guard: a notice must have been raised.');
        $notice = $fixture['notices'][0];
        $this->assertNotSame('', trim((string)$notice['title']), 'The notice needs a title.');
        foreach ($notice['args'] as $index => $argument) {
            $this->assertStringNotContainsString(
                $email,
                is_array($argument) ? implode(' ', $argument) : (string)$argument,
                'addMajor() argument #' . $index . ' must not name the shopper\'s email.'
            );
        }
    }

    /**
     * SA-2: the notice is best-effort. On the quote-submit path an API error lets the order
     * through, so an inbox (or cache) fault while raising the notice must not abort it: the SDK's
     * error array is still returned and the fault is logged as a second warning.
     */
    public function testANoticeFailureStillReturnsTheErrorAndLogsBothWarnings(): void
    {
        $sdkResponse = ['error' => true, 'message' => 'cURL error 28: Operation timed out'];
        $fixture = $this->emailApiFixture($sdkResponse, addMajorThrows: new \RuntimeException('inbox table is locked'));

        $response = $fixture['validator']->verifyEmail(self::EMAIL);

        $this->assertSame($sdkResponse, $response, 'The SDK error array must still be returned.');
        $this->assertCount(1, $fixture['notices'], 'Fixture guard: addMajor() was reached and threw.');
        $this->assertSame(
            ['cURL error 28: Operation timed out', 'Loqate could not raise the email API error notice: inbox table is locked'],
            $this->logMessages($fixture, 'warning'),
            'Both the API error and the notice failure must be logged as warnings.'
        );
    }

    /**
     * SA-2: only an API ERROR is a fault. A pass (true) or an invalid email (false) is normal
     * operation: no notice, no warning, and the throttle is not touched.
     *
     * @param bool $sdkResponse What the SDK's verifyEmail() answers.
     */
    #[DataProvider('emailVerdictProvider')]
    public function testAPassOrAnInvalidEmailRaisesNoNoticeAndNoWarning(bool $sdkResponse): void
    {
        $fixture = $this->emailApiFixture($sdkResponse);

        $response = $fixture['validator']->verifyEmail(self::EMAIL);

        $this->assertSame($sdkResponse, $response, 'Fixture guard: the SDK verdict is returned unchanged.');
        $this->assertCount(0, $fixture['notices'], 'A verdict is not a fault: no admin notice.');
        $this->assertSame([], $this->logMessages($fixture, 'warning'), 'A verdict is not a fault: no warning.');
        $this->assertCount(0, $fixture['cacheSaves'], 'A verdict must not open the throttle window.');
    }

    public static function emailVerdictProvider(): array
    {
        return [
            'valid email' => [true],
            'invalid email' => [false],
        ];
    }

    /**
     * LOQ-20324: through the REAL SDK (lqt/api-connector 1.1.5+), with only its HTTP client
     * stubbed. A Timeout ResponseCode is no verdict on the address: the blocking callers show the
     * "unexpected error" message, never the invalid-email one, and the place-order path lets it
     * through. Invalid and Valid_CatchAll still give their verdicts.
     *
     * @param string $responseCode The ResponseCode the email API answers.
     * @param array<string, mixed> $config Configuration paths that are switched on.
     * @param bool $blockOnApiError What the caller passes (false: Observer\QuoteSubmitBefore).
     * @param false|string $expected What validateEmail() must return.
     */
    #[DataProvider('sdkResponseCodeProvider')]
    public function testValidateEmailTurnsEachApiResponseCodeIntoTheShopperFacingVerdict(
        string $responseCode,
        array $config,
        bool $blockOnApiError,
        $expected
    ): void {
        $fixture = $this->emailApiFixture($this->verifyAnswering($responseCode), config: $config);

        $this->assertSame($expected, $fixture['validator']->validateEmail(self::EMAIL, $blockOnApiError));
    }

    public static function sdkResponseCodeProvider(): array
    {
        $yes = [self::EMAIL_PREVENT_SUBMIT => '1'];

        return [
            'Timeout, blocking, prevent submit Yes' => ['Timeout', $yes, true, self::UNEXPECTED_ERROR_MESSAGE],
            'Timeout, blocking, prevent submit No' => ['Timeout', [], true, self::UNEXPECTED_ERROR_MESSAGE],
            'Timeout, place order, prevent submit Yes: pass' => ['Timeout', $yes, false, false],
            'Timeout, place order, prevent submit No: pass' => ['Timeout', [], false, false],
            'Valid' => ['Valid', $yes, true, false],
            'Invalid' => ['Invalid', $yes, true, self::INVALID_MESSAGE],
            'Valid_CatchAll, catch-all off' => ['Valid_CatchAll', $yes, true, self::INVALID_MESSAGE],
            'Valid_CatchAll, catch-all on' => ['Valid_CatchAll', $yes + [self::EMAIL_CATCH_ALL => '1'], true, false],
        ];
    }

    /**
     * LOQ-20324: on the place-order path a Timeout lets the order through, but it is still an
     * API error the merchant must see: a warning naming the Timeout, and the hourly admin notice
     * raised once - a second checkout within the hour raises no other.
     */
    #[DataProvider('preventSubmitProvider')]
    public function testATimeoutAtPlaceOrderIsLoggedAndRaisesTheNoticeOnce(string $preventSubmit): void
    {
        $fixture = $this->emailApiFixture(
            $this->verifyAnswering('Timeout'),
            config: [self::EMAIL_PREVENT_SUBMIT => $preventSubmit]
        );

        $first = $fixture['validator']->validateEmail(self::EMAIL, false);
        $second = $fixture['validator']->validateEmail('another.shopper@example.com', false);

        $this->assertFalse($first, 'A Timeout must not reject the order.');
        $this->assertFalse($second, 'A Timeout must not reject the order.');
        $warnings = $this->logMessages($fixture, 'warning');
        $this->assertCount(2, $warnings, 'Each Timeout is logged as a warning.');
        $this->assertStringContainsString('Timeout', $warnings[0]);
        $this->assertCount(1, $fixture['notices'], 'Within the hour only the first Timeout raises a notice.');
    }

    /**
     * A real SDK Verify whose HTTP client answers the decoded email API response with $responseCode,
     * so the SDK's own ResponseCode handling runs.
     */
    private function verifyAnswering(string $responseCode): Verify
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->method('get')->willReturn(['Items' => [['ResponseCode' => $responseCode]]]);
        $verify = new Verify('TEST-API-KEY-0000');
        (new ReflectionProperty(Verify::class, 'httpClient'))->setValue($verify, $httpClient);

        return $verify;
    }

    /**
     * A real Validator with an API key, whose SDK client is swapped for a double answering
     * $sdkResponse, so verifyEmail() itself runs. The logger, notifier and cache are recording
     * doubles; the cache is an in-memory map.
     *
     * Passes the notifier and cache as the 7th and 8th constructor arguments (SA-2).
     *
     * @param mixed $sdkResponse What Verify::verifyEmail() answers, or a real Verify to use as is.
     * @param false|string $absent What load() answers for a key the cache does not hold.
     * @param string|null $everyKey When set, load() answers this for EVERY key.
     * @param ArrayObject|null $cacheStore Shared cache contents, key => data.
     * @param ArrayObject|null $notices Shared addMajor() log.
     * @param \Throwable|null $addMajorThrows When set, addMajor() records the call and then throws it.
     * @param array<string, mixed> $config Configuration paths switched on besides the API key.
     * @return array{validator: Validator, logs: ArrayObject, notices: ArrayObject, cacheStore: ArrayObject,
     *     cacheSaves: ArrayObject, cacheLoads: ArrayObject}
     */
    private function emailApiFixture(
        $sdkResponse,
        $absent = false,
        ?string $everyKey = null,
        ?ArrayObject $cacheStore = null,
        ?ArrayObject $notices = null,
        ?\Throwable $addMajorThrows = null,
        array $config = []
    ): array {
        $logs = new ArrayObject();
        $notices = $notices ?? new ArrayObject();
        $cacheStore = $cacheStore ?? new ArrayObject();
        $cacheSaves = new ArrayObject();
        $cacheLoads = new ArrayObject();

        $logger = $this->createMock(Logger::class);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            $logger->method($level)->willReturnCallback(
                static function ($message, array $context = []) use ($logs, $level): void {
                    $logs[] = ['level' => $level, 'message' => (string)$message];
                }
            );
        }

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->method('addMajor')->willReturnCallback(
            static function ($title, $description, $url = '', $isInternal = true) use ($notices, $notifier, $addMajorThrows) {
                $notices[] = ['title' => $title, 'description' => $description, 'args' => func_get_args()];
                if ($addMajorThrows) {
                    throw $addMajorThrows;
                }

                return $notifier;
            }
        );

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function ($key) use ($cacheStore, $cacheLoads, $absent, $everyKey) {
                $cacheLoads[] = $key;
                if ($everyKey !== null) {
                    return $everyKey;
                }

                return $cacheStore[$key] ?? $absent;
            }
        );
        $cache->method('save')->willReturnCallback(
            static function ($data, $key, $tags = [], $lifetime = null) use ($cacheStore, $cacheSaves): bool {
                $cacheSaves[] = ['data' => $data, 'key' => $key, 'tags' => $tags, 'lifetime' => $lifetime];
                $cacheStore[$key] = $data;

                return true;
            }
        );

        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => 'test']);

        $validator = new Validator(
            $logger,
            $this->createMock(Session::class),
            $this->createMock(RegionFactory::class),
            $moduleList,
            $this->createConfigHelper(['loqate_settings/settings/api_key' => 'TEST-API-KEY-0000'] + $config),
            $this->createMock(SerializerInterface::class),
            $notifier,
            $cache
        );

        // The SDK client is built in the constructor (new Verify($apiKey)); swap it for a double.
        $connector = $sdkResponse;
        if (!$connector instanceof Verify) {
            $connector = $this->createMock(Verify::class);
            $connector->method('verifyEmail')->willReturn($sdkResponse);
        }
        $property = new ReflectionProperty(Validator::class, 'apiConnector');
        $property->setValue($validator, $connector);

        return [
            'validator' => $validator,
            'logs' => $logs,
            'notices' => $notices,
            'cacheStore' => $cacheStore,
            'cacheSaves' => $cacheSaves,
            'cacheLoads' => $cacheLoads,
        ];
    }

    /**
     * The messages logged at one level, in order.
     *
     * @param array $fixture From emailApiFixture().
     * @return string[]
     */
    private function logMessages(array $fixture, string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter(iterator_to_array($fixture['logs']), static fn (array $record): bool => $record['level'] === $level)
        ));
    }

    /**
     * A real Validator over a remembering session, with only the billable verifyEmail() stubbed
     * and counted. No API key is configured, so the constructor builds no live client.
     *
     * @param array<string, mixed> $config Configuration paths that are switched on.
     * @param mixed $verifyResponse What verifyEmail() answers.
     * @return array{validator: Validator, session: Session, helper: Data, emailRequests: ArrayObject,
     *     sessionStore: ArrayObject, identity: ArrayObject, storeId: ArrayObject, config: ArrayObject}
     */
    private function emailFixture(array $config, $verifyResponse): array
    {
        $emailRequests = new ArrayObject();
        // All read LIVE, so a test can log in/out, switch store view or change a setting between calls.
        $sessionStore = new ArrayObject();
        $identity = new ArrayObject(['customerId' => null]);
        $storeId = new ArrayObject(['id' => 1]);
        $liveConfig = new ArrayObject($config);
        $session = $this->createSessionDouble($sessionStore, $identity);
        // Same contract as ShopperSessionHarness::createConfigHelper(), over a mutable map.
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static fn ($configPath) => $liveConfig[$configPath] ?? ''
        );
        $helper->method('getCurrentStore')->willReturnCallback(static fn (): int => (int)$storeId['id']);

        $validator = $this->getMockBuilder(Validator::class)
            ->setConstructorArgs([
                $this->createMock(Logger::class),
                $session,
                $this->createMock(RegionFactory::class),
                $this->createMock(ModuleListInterface::class),
                $helper,
                $this->createMock(SerializerInterface::class),
                $this->createMock(NotifierInterface::class),
                $this->createMock(CacheInterface::class),
            ])
            ->onlyMethods(['verifyEmail'])
            ->getMock();
        $validator->method('verifyEmail')->willReturnCallback(
            static function ($email) use ($emailRequests, $verifyResponse) {
                $emailRequests[] = $email;

                return $verifyResponse;
            }
        );

        return [
            'validator' => $validator,
            'session' => $session,
            'helper' => $helper,
            'emailRequests' => $emailRequests,
            'sessionStore' => $sessionStore,
            'identity' => $identity,
            'storeId' => $storeId,
            'config' => $liveConfig,
        ];
    }

    /**
     * The pass list (LOQ-20299) exactly as the session holds it.
     *
     * @param array $fixture From emailFixture().
     * @return mixed
     */
    private function passList(array $fixture)
    {
        return $fixture['sessionStore'][ShopperScopedSessionStores::PASSED_EMAIL_SESSION_KEY] ?? null;
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
