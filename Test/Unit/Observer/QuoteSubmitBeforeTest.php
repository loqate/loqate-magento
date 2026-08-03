<?php

namespace Loqate\ApiIntegration\Test\Unit\Observer;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Loqate\ApiIntegration\Logger\Logger;
use Loqate\ApiIntegration\Observer\QuoteSubmitBefore;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Observer\QuoteSubmitBefore, which had NO coverage at all.
 *
 * The observer is registered GLOBALLY in etc/events.xml, on purpose: it exists because
 * Hyvä's GraphQL checkout does not use the REST ShippingInformationManagement /
 * BillingAddressManagement services the other plugins intercept, and 'graphql' and
 * 'webapi_rest' are separate area codes that do NOT inherit 'frontend' - so moving the
 * registration into etc/frontend/events.xml would silently disable verification on the very
 * paths it was added for. A global registration, however, also fires on ADMIN order create,
 * where Plugin\Admin\OrderSave already verifies both addresses in ONE batch call
 * (verifyMultipleAddresses()); this observer would then add up to two further single-address
 * calls for the same order, and the two paths cannot share a verdict because one judges the
 * AVC and the other the AQI. So the admin area is excluded at RUNTIME instead.
 *
 * That makes three things load-bearing, and this file pins each of them:
 *  - in 'adminhtml' NOTHING is verified, asserted on a spy rather than on the absence of an
 *    error, because the observer's normal outcome for a valid address is also "no error" -
 *    see testTheAdminAreaVerifiesNothing();
 *  - in EVERY non-admin area the previous behaviour is completely unchanged, which has to be
 *    asserted for 'frontend', 'graphql' AND 'webapi_rest' individually: an area-code
 *    comparison written as a whitelist rather than an exclusion would pass for 'frontend'
 *    alone - see testEveryNonAdminAreaStillVerifiesBothAddressesAndPhoneNumbers();
 *  - when the area cannot be resolved at all (State::getAreaCode() throws, which it does when
 *    no area code has been set yet) the observer fails TOWARDS verifying and swallows the
 *    throwable - ANY throwable, not just the documented LocalizedException. Both halves
 *    matter: skipping verification on an unknown area would let unverified addresses through
 *    checkout unnoticed, while letting anything escape from here aborts the order submission
 *    outright, a failure mode this class only acquired by taking a State dependency - see
 *    testAnUnresolvableAreaStillVerifiesAndDoesNotAbortTheOrder() and
 *    testAnyThrowableFromTheAreaLookupIsSwallowedSoItCannotKillTheOrder().
 */
class QuoteSubmitBeforeTest extends TestCase
{
    /** Config path gating address verification at checkout. */
    private const ADDRESS_CHECKOUT = 'loqate_settings/address_settings/enable_checkout';

    /** Config path gating phone verification at checkout. */
    private const PHONE_CHECKOUT = 'loqate_settings/phone_settings/enable_checkout';

    /** A shipping address as Quote\Address::getData() delivers it (newline-string street). */
    private const SHIPPING_ADDRESS = [
        'street' => "1 High St\nFlat 2\n",
        'city' => 'London',
        'postcode' => 'SW1A 1AA',
        'country_id' => 'GB',
        'telephone' => '02079460000',
    ];

    /** A billing address that is deliberately a DIFFERENT address from the shipping one. */
    private const BILLING_ADDRESS = [
        'street' => "77 Office Park\nUnit 4\n",
        'city' => 'Reading',
        'postcode' => 'RG1 1AA',
        'country_id' => 'GB',
        'telephone' => '01189990000',
    ];

    /** @var ArrayObject Ordered log of every Validator call the observer made. */
    private $validatorCalls;

    /** @var ArrayObject Ordered log of every record written to the module logger. */
    private $logRecords;

    /** @var ArrayObject Live store configuration, config path => value. */
    private $config;

    /** @var array Value the Validator returns from verifyAddress(). */
    private $addressResponse = ['error' => false];

    /** @var mixed Value the Validator returns from verifyPhoneNumber(). */
    private $phoneResponse = true;

    protected function setUp(): void
    {
        $this->validatorCalls = new ArrayObject();
        $this->logRecords = new ArrayObject();
        // Both features on, which is the configuration the observer was written for; the
        // tests that care about a feature being off turn it off explicitly.
        $this->config = new ArrayObject([
            'loqate_settings/settings/api_key' => 'TEST-API-KEY-0000',
            self::ADDRESS_CHECKOUT => '1',
            self::PHONE_CHECKOUT => '1',
        ]);
        $this->addressResponse = ['error' => false];
        $this->phoneResponse = true;
    }

    /**
     * THE guard (task 3): on admin order create the observer must verify NOTHING.
     *
     * Asserted on a SPY - the recorded list of Validator calls - and not on the absence of an
     * exception, because a valid address produces no exception either, so "it did not throw"
     * would pass just as happily with the guard deleted. The addresses here are additionally
     * stubbed to be INVALID, so removing the guard fails this test twice over: the spy records
     * two verifications AND the observer aborts the admin's order with a LocalizedException.
     */
    public function testTheAdminAreaVerifiesNothing(): void
    {
        $this->addressResponse = ['error' => true, 'message' => 'The provided address is invalid.'];
        $this->phoneResponse = false;
        $observer = $this->createObserver(Area::AREA_ADMINHTML);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [],
            $this->recordedCalls(),
            'Admin order create is already verified by Plugin\Admin\OrderSave, in ONE batch call. This '
            . 'observer must not verify anything there: every call it makes is a second, separately '
            . 'billable Loqate request for an address the merchant has already paid to have checked, and '
            . 'the two paths cannot share a verdict because they judge different thresholds.'
        );
        $this->assertSame(
            [],
            iterator_to_array($this->logRecords),
            'Skipping the admin area is normal operation, not a fault: it must not write anything to the log.'
        );
    }

    /**
     * The other half of the guard, and the half a whitelist-shaped check would break: in EVERY
     * non-admin area the pre-existing behaviour must be completely unchanged.
     *
     * All three areas are covered individually because they are genuinely different codes and
     * none of them inherits another: 'frontend' is the Luma page, 'webapi_rest' is the REST
     * checkout the Luma front-end actually posts to, and 'graphql' is Hyvä. An "is this area
     * frontend?" test written the wrong way round - or a registration moved into
     * etc/frontend/events.xml - keeps working on the first and silently stops verifying on the
     * other two, which are the paths this observer was added for in the first place.
     *
     * @param string $areaCode Area the request is being handled in.
     */
    #[DataProvider('nonAdminAreaProvider')]
    public function testEveryNonAdminAreaStillVerifiesBothAddressesAndPhoneNumbers(string $areaCode): void
    {
        $observer = $this->createObserver($areaCode);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [
                ['verifyAddress', self::SHIPPING_ADDRESS],
                ['verifyPhoneNumber', '02079460000', 'GB'],
                ['verifyAddress', self::BILLING_ADDRESS],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            sprintf(
                'In the "%s" area both addresses and both phone numbers must still be verified, in that '
                . 'order: %s does not inherit any other area, so excluding the admin area must not '
                . 'exclude this one with it.',
                $areaCode,
                $areaCode
            )
        );
    }

    /**
     * ...and the rejection path in every non-admin area: an invalid address must still abort
     * the order submission with a LocalizedException carrying the shopper-facing message.
     *
     * This is what the observer is FOR, so it is asserted per area as well: an area guard that
     * accidentally covered 'graphql' would not merely skip a billable call there, it would let
     * an address Loqate rejected through Hyvä checkout.
     *
     * @param string $areaCode Area the request is being handled in.
     */
    #[DataProvider('nonAdminAreaProvider')]
    public function testAnInvalidAddressStillAbortsTheOrderInEveryNonAdminArea(string $areaCode): void
    {
        $this->addressResponse = ['error' => true, 'message' => 'The provided address is invalid.'];
        $observer = $this->createObserver($areaCode);

        try {
            $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));
            $this->fail(sprintf(
                'An invalid address must still abort the order submission in the "%s" area: without the '
                . 'LocalizedException the order is placed with an address Loqate rejected.',
                $areaCode
            ));
        } catch (LocalizedException $exception) {
            $this->assertSame(
                'The provided address is invalid.' . PHP_EOL . 'The provided address is invalid.',
                $exception->getMessage(),
                'Both rejected addresses must be reported, joined one per line, exactly as before.'
            );
        }

        $this->assertSame(
            [
                ['verifyAddress', self::SHIPPING_ADDRESS],
                ['verifyPhoneNumber', '02079460000', 'GB'],
                ['verifyAddress', self::BILLING_ADDRESS],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            'A rejected shipping address must not stop the billing address being verified: the shopper is '
            . 'shown every problem at once, which is the pre-existing behaviour.'
        );
    }

    /**
     * An invalid PHONE number must still abort the order too, in every non-admin area - the
     * observer verifies two things, and only one of them is an address.
     *
     * @param string $areaCode Area the request is being handled in.
     */
    #[DataProvider('nonAdminAreaProvider')]
    public function testAnInvalidPhoneNumberStillAbortsTheOrderInEveryNonAdminArea(string $areaCode): void
    {
        $this->phoneResponse = false;
        $observer = $this->createObserver($areaCode);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The provided phone number is invalid.' . PHP_EOL . 'The provided phone number is invalid.'
        );

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));
    }

    /**
     * The three area codes the observer MUST keep running in. Named from
     * Magento\Framework\App\Area so a renamed constant fails here rather than silently
     * testing a string Magento no longer uses.
     */
    public static function nonAdminAreaProvider(): array
    {
        return [
            'Luma page' => [Area::AREA_FRONTEND],
            'Hyvä GraphQL checkout' => [Area::AREA_GRAPHQL],
            'Luma REST checkout' => [Area::AREA_WEBAPI_REST],
        ];
    }

    /**
     * State::getAreaCode() THROWS when no area code has been set yet, and the observer then
     * has to decide which way to fail. It fails towards VERIFYING, and this test pins both
     * halves of that decision.
     *
     * The direction is not arbitrary: the two failure modes are not symmetric. Treating an
     * unresolvable area as admin would skip verification silently - unverified addresses reach
     * checkout, nobody notices, and it is a correctness and compliance problem. Treating it as
     * non-admin costs at worst one duplicate billable call, which shows up on the invoice and
     * never blocks an order. And the exception must be SWALLOWED rather than propagated for
     * the same reason: a LocalizedException out of this observer aborts the order submission
     * altogether, so an unset area code would stop the store taking orders.
     */
    public function testAnUnresolvableAreaStillVerifiesAndDoesNotAbortTheOrder(): void
    {
        $observer = $this->createObserver(
            new LocalizedException('Area code is not set')
        );

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [
                ['verifyAddress', self::SHIPPING_ADDRESS],
                ['verifyPhoneNumber', '02079460000', 'GB'],
                ['verifyAddress', self::BILLING_ADDRESS],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            'An area code that cannot be resolved must fail TOWARDS verifying: skipping verification '
            . 'there would let unverified addresses through checkout with nothing to show it happened.'
        );

        $messages = array_column(iterator_to_array($this->logRecords), 'message');
        $this->assertCount(
            1,
            $messages,
            'The swallowed exception must leave exactly one trace in the log, so an unset area code is '
            . 'diagnosable rather than invisible.'
        );
        $this->assertStringContainsString(
            'Area code is not set',
            $messages[0],
            'The log line must carry the underlying reason.'
        );
    }

    /**
     * ...and the verification it falls back to is a REAL one: with the area unresolvable, an
     * invalid address must still abort the order, and the exception the merchant sees must be
     * the ADDRESS rejection - not the swallowed area-code exception leaking out under a
     * misleading message.
     */
    public function testAnUnresolvableAreaStillRejectsAnInvalidAddress(): void
    {
        $this->addressResponse = ['error' => true, 'message' => 'The provided address is invalid.'];
        $observer = $this->createObserver(new LocalizedException('Area code is not set'));

        try {
            $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));
            $this->fail('An invalid address must still be rejected when the area cannot be resolved.');
        } catch (LocalizedException $exception) {
            $this->assertSame(
                'The provided address is invalid.' . PHP_EOL . 'The provided address is invalid.',
                $exception->getMessage(),
                'The exception must be the address rejection. If the area-code exception propagated '
                . 'instead, the merchant would be shown "Area code is not set" as the reason their '
                . 'customer could not place an order.'
            );
        }
    }

    /**
     * EVERY throwable out of State::getAreaCode() is swallowed, not only the LocalizedException
     * the real method documents - and this test is the record of that decision rather than the
     * consequence of an accident.
     *
     * It used to assert the opposite, on the stated grounds that widening the catch should be a
     * deliberate decision with a test to update rather than a silent one. The decision has now
     * been taken, and it went the other way, for the reason a narrow catch could not answer:
     * State is an interceptable @api class, so a plugin on it, a broken DI compilation or a
     * not-yet-initialised object manager can raise something that is not a LocalizedException,
     * and ANY throwable escaping a sales_model_service_quote_submit_before observer KILLS THE
     * ORDER. That failure mode did not exist before this class took a State dependency, so
     * resolving the area must never be the reason a customer cannot check out.
     *
     * Nothing is traded away in exchange, and each half is asserted for every throwable type:
     *  - it does not escape - the observer runs to completion, so the order is not aborted;
     *  - it fails TOWARDS verifying, with exactly the calls
     *    testAnUnresolvableAreaStillVerifiesAndDoesNotAbortTheOrder() asserts, so the wider
     *    catch cannot quietly change WHICH addresses get verified;
     *  - it leaves exactly ONE log record carrying the underlying message, identically for
     *    every type, so an \Error is as diagnosable as a LocalizedException. Swallowing without
     *    that line is the outcome this test exists to forbid.
     *
     * The provider covers the documented type AND two undocumented ones deliberately: an
     * \Error is not an \Exception, so catch (\Exception) - the plausible half-measure - passes
     * the first two rows and fails the third, and the original narrow catch (LocalizedException)
     * passes the first row alone.
     *
     * @param \Throwable $areaFault What State::getAreaCode() raises instead of an area code.
     */
    #[DataProvider('areaLookupFaultProvider')]
    public function testAnyThrowableFromTheAreaLookupIsSwallowedSoItCannotKillTheOrder(
        \Throwable $areaFault
    ): void {
        $observer = $this->createObserver($areaFault);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [
                ['verifyAddress', self::SHIPPING_ADDRESS],
                ['verifyPhoneNumber', '02079460000', 'GB'],
                ['verifyAddress', self::BILLING_ADDRESS],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            sprintf(
                'A %s out of the area lookup must be swallowed and the request must fail TOWARDS '
                . 'verifying, exactly as an unresolvable area does: if this throwable escaped instead it '
                . 'would abort the order submission, and resolving the application area must never be '
                . 'the reason an order fails.',
                get_class($areaFault)
            )
        );

        $messages = array_column(iterator_to_array($this->logRecords), 'message');
        $this->assertCount(
            1,
            $messages,
            sprintf(
                'A swallowed %s must leave exactly one trace in the log. Widening the catch is only '
                . 'defensible because diagnosis is preserved, not traded away: a silent catch turns an '
                . 'unresolvable area into an invisible extra billable call.',
                get_class($areaFault)
            )
        );
        $this->assertStringContainsString(
            'object manager is not initialised',
            $messages[0],
            'The log line must carry the underlying reason, identically for every throwable type - the '
            . 'observer cannot tell the merchant what went wrong if it only records that something did.'
        );
    }

    /**
     * The throwable types State::getAreaCode() must not be able to kill an order with.
     *
     * All three carry the SAME message, so the log assertion above is one assertion about every
     * type rather than three variants of it: the observer must not treat an undocumented
     * throwable as less worth reporting than the documented one.
     */
    public static function areaLookupFaultProvider(): array
    {
        $message = 'object manager is not initialised';

        return [
            // The only type real Magento documents here: State::getAreaCode() throws it when no
            // area code has been set yet.
            'LocalizedException, the documented case' => [new LocalizedException($message)],
            // A plugin on the interceptable State class, or any fault below it.
            'RuntimeException, an undocumented runtime fault' => [new \RuntimeException($message)],
            // Not an \Exception at all, so catch (\Exception) would let this one through.
            'Error, which a catch (\Exception) would not hold' => [new \Error($message)],
        ];
    }

    /**
     * Existing behaviour that must survive the new guard, because the guard runs before all of
     * it: with no API key configured nothing is verified, whatever the area.
     */
    public function testNoApiKeyConfiguredVerifiesNothing(): void
    {
        $this->config['loqate_settings/settings/api_key'] = '';
        $observer = $this->createObserver(Area::AREA_FRONTEND);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame([], $this->recordedCalls(), 'Without an API key there is nothing to verify with.');
    }

    /**
     * Existing behaviour: a virtual quote has no shippable address, so only the billing
     * address is verified. Magento still hands back a shipping address object on a virtual
     * quote, so this is a real branch and not a defensive one.
     */
    public function testAVirtualQuoteVerifiesTheBillingAddressOnly(): void
    {
        $observer = $this->createObserver(Area::AREA_FRONTEND);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS, true));

        $this->assertSame(
            [
                ['verifyAddress', self::BILLING_ADDRESS],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            'A virtual quote has nothing to ship, so its shipping address must not be verified or billed.'
        );
    }

    /**
     * Existing behaviour: each feature is gated independently, so turning address verification
     * off must not take phone verification with it, or vice versa.
     */
    public function testEachFeatureToggleIsHonouredIndependently(): void
    {
        $this->config[self::ADDRESS_CHECKOUT] = '';
        $observer = $this->createObserver(Area::AREA_FRONTEND);

        $observer->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [
                ['verifyPhoneNumber', '02079460000', 'GB'],
                ['verifyPhoneNumber', '01189990000', 'GB'],
            ],
            $this->recordedCalls(),
            'With address verification off, only the phone numbers may be verified.'
        );

        $this->validatorCalls = new ArrayObject();
        $this->config[self::ADDRESS_CHECKOUT] = '1';
        $this->config[self::PHONE_CHECKOUT] = '';

        // The spy closure captures the call log by reference at construction time, so the
        // second observer has to be built AFTER the log is swapped for a fresh one.
        $phoneOff = $this->createObserver(Area::AREA_FRONTEND);
        $phoneOff->execute($this->quoteEvent(self::SHIPPING_ADDRESS, self::BILLING_ADDRESS));

        $this->assertSame(
            [
                ['verifyAddress', self::SHIPPING_ADDRESS],
                ['verifyAddress', self::BILLING_ADDRESS],
            ],
            $this->recordedCalls(),
            'With phone verification off, only the addresses may be verified.'
        );
    }

    /**
     * Build the observer under test, wired to recording doubles.
     *
     * @param string|\Throwable $area Area code State::getAreaCode() answers with, or a
     *                                throwable it raises instead.
     * @return QuoteSubmitBefore
     */
    private function createObserver($area): QuoteSubmitBefore
    {
        $config = $this->config;
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static fn ($configPath) => $config[$configPath] ?? ''
        );

        // A recording spy rather than a set of expectations, so a test can assert exactly WHICH
        // calls were made, in WHICH order and with WHICH arguments - and, for the admin guard,
        // that the list is empty. Both of those are stronger than "the method was not called".
        $calls = $this->validatorCalls;
        $validator = $this->createMock(Validator::class);
        $validator->method('verifyAddress')->willReturnCallback(
            function ($address, $checkForCaptured = true) use ($calls): array {
                $calls[] = ['verifyAddress', $address];

                return $this->addressResponse;
            }
        );
        $validator->method('verifyPhoneNumber')->willReturnCallback(
            function ($phone, $country = null) use ($calls) {
                $calls[] = ['verifyPhoneNumber', $phone, $country];

                return $this->phoneResponse;
            }
        );

        $records = $this->logRecords;
        $logger = $this->createMock(Logger::class);
        foreach (['debug', 'info', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                static function ($message, array $context = []) use ($records, $level) {
                    $records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
                }
            );
        }

        $appState = $this->createMock(State::class);
        if ($area instanceof \Throwable) {
            $appState->method('getAreaCode')->willThrowException($area);
        } else {
            $appState->method('getAreaCode')->willReturn($area);
        }

        return new QuoteSubmitBefore($helper, $validator, $logger, $appState);
    }

    /**
     * An Observer carrying a quote with the given addresses, in the shape
     * sales_model_service_quote_submit_before delivers it.
     *
     * The quote and address doubles are anonymous classes rather than mocks: the observer
     * only asks them for data, they need no Magento base class (nothing type-checks them),
     * and this keeps the fixture readable enough that a reader can see what the observer is
     * actually given.
     *
     * @param array|null $shipping Shipping address data, or null for a quote with none.
     * @param array|null $billing Billing address data, or null for a quote with none.
     * @param bool $isVirtual Whether the quote is virtual (nothing to ship).
     * @return Observer
     */
    private function quoteEvent(?array $shipping, ?array $billing, bool $isVirtual = false): Observer
    {
        $shippingAddress = $shipping === null ? null : self::addressDouble($shipping);
        $billingAddress = $billing === null ? null : self::addressDouble($billing);

        $quote = new class ($shippingAddress, $billingAddress, $isVirtual) {
            private $shippingAddress;
            private $billingAddress;
            private $isVirtual;

            public function __construct($shippingAddress, $billingAddress, bool $isVirtual)
            {
                $this->shippingAddress = $shippingAddress;
                $this->billingAddress = $billingAddress;
                $this->isVirtual = $isVirtual;
            }

            public function getShippingAddress()
            {
                return $this->shippingAddress;
            }

            public function getBillingAddress()
            {
                return $this->billingAddress;
            }

            public function getIsVirtual()
            {
                return $this->isVirtual;
            }
        };

        $event = new class ($quote) {
            private $quote;

            public function __construct($quote)
            {
                $this->quote = $quote;
            }

            public function getQuote()
            {
                return $this->quote;
            }
        };

        return (new Observer())->setEvent($event);
    }

    /**
     * A Quote\Address double: the three accessors the observer uses, answered from one data
     * array exactly as AbstractAddress does.
     *
     * @param array $data Address data as Quote\Address::getData() returns it.
     * @return object
     */
    private static function addressDouble(array $data): object
    {
        return new class ($data) {
            /** @var array */
            private $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function getData()
            {
                return $this->data;
            }

            public function getTelephone()
            {
                return $this->data['telephone'] ?? null;
            }

            public function getCountryId()
            {
                return $this->data['country_id'] ?? null;
            }
        };
    }

    /**
     * Every Validator call the observer made, in order, as ['method', ...arguments].
     *
     * @return array<int, array>
     */
    private function recordedCalls(): array
    {
        return array_values(iterator_to_array($this->validatorCalls));
    }
}
