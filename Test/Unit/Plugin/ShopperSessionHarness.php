<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin;

use ArrayObject;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\Validator;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The doubles every plugin-level test of the shopper-scoped session stores needs: a customer
 * session that actually remembers what it is given and whose logged-in identity can change
 * mid-test, a Helper\Data over a configuration map, and a Helper\Validator that COUNTS the
 * billable calls instead of making them.
 *
 * WHY THE PLUGINS ARE DRIVEN AT ALL, rather than only Plugin\AbstractPlugin and
 * Helper\ShopperScopedSessionStores. Each of the four attributes LOQ-17149 enrolled is written
 * by one class and read by another - the pending email address is written by
 * Plugin\Frontend\AccountManagement and read by CheckoutShippingInformation, the billing-error
 * gate is written by CheckoutBillingAddress and read by PlaceOrder and PlaceOrderGuest - so the
 * guarantee ("shopper B never inherits A's") is a statement about a pair of plugins and is not
 * observable from either one alone. A seam that flushes perfectly protects nothing if a plugin
 * reaches the attribute another way, and that is exactly the shape of defect this series has
 * already shipped once.
 *
 * EVERY FIXTURE BUILT WITH THIS TRAIT GOES THROUGH THE REAL CONSTRUCTOR. AbstractPlugin and the
 * three classes that do not extend it all build their ShopperScopedSessionStores inline from the
 * injected Session, so injecting the seam by reflection would assert the test's own wiring
 * rather than production's - and the wiring is what LOQ-17149 changed.
 */
trait ShopperSessionHarness
{
    /**
     * A Magento\Customer\Model\Session double that actually stores what it is given.
     *
     * The shared Test/stubs Session is a no-op (getData() returns null, setData() stores
     * nothing), so nothing under test could ever be observed. getData()/setData() have to be
     * *added* when the real Magento Session is present, because it does not declare them -
     * SessionManager __call-forwards them to Session\Storage - while the stub does declare them
     * and PHPUnit refuses to "add" an existing method; hence the method_exists() filter, which
     * keeps this double working on both sides.
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

    /**
     * A Helper\Data over an explicit configuration map.
     *
     * Anything not named answers '' - what an untouched admin field leaves behind, and in
     * particular the SHIPPED DEFAULT of both prevent_submit toggles (etc/config.xml sets them
     * to 0). That default is the only mode in which the contact bypass stores are consulted at
     * all, so a test that switched it would pass without ever reading the store it is about.
     *
     * @param array<string, mixed> $config Configuration paths that are switched on.
     * @param ArrayObject|null $storeId Holds 'id', read LIVE so a test can switch store view.
     * @return Data&MockObject
     */
    private function createConfigHelper(array $config, ?ArrayObject $storeId = null)
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            static fn ($configPath) => $config[$configPath] ?? ''
        );
        $helper->method('getCurrentStore')->willReturnCallback(
            static fn (): int => $storeId === null ? 1 : (int)$storeId['id']
        );

        return $helper;
    }

    /**
     * A Helper\Validator that records every billable request instead of making it.
     *
     * A bypass is only ever observable as a call that DID NOT HAPPEN - on the shipped default
     * configuration a store hit skips the warning as well as the request - so every test here
     * counts these arrays rather than reading a return value.
     *
     * @param ArrayObject $emailRequests Every email address sent for verification, in order.
     * @param ArrayObject $phoneRequests Every phone number sent for verification, in order.
     * @param bool $emailPasses Whether the connector accepts the email addresses it is sent.
     * @param bool $phonePasses Whether the connector accepts the phone numbers it is sent.
     * @return Validator&MockObject
     */
    private function createCountingValidator(
        ArrayObject $emailRequests,
        ArrayObject $phoneRequests,
        bool $emailPasses = true,
        bool $phonePasses = true
    ) {
        $validator = $this->createMock(Validator::class);
        $validator->method('verifyEmail')->willReturnCallback(
            static function ($email) use ($emailRequests, $emailPasses) {
                $emailRequests[] = $email;

                // Truthy with no 'error' and no 'noKeyFound' => validateEmail() reports no
                // error; false => it reports the "submit again" message.
                return $emailPasses ? ['Valid' => true] : false;
            }
        );
        $validator->method('verifyPhoneNumber')->willReturnCallback(
            static function ($phone, $country = null) use ($phoneRequests, $phonePasses) {
                $phoneRequests[] = $phone;

                return $phonePasses ? ['Valid' => true] : false;
            }
        );
        $validator->method('verifyAddress')->willReturn(['error' => false]);
        $validator->method('verifyMultipleAddresses')->willReturnCallback(
            static fn ($addresses, $admin = false) => array_map(static fn () => true, (array)$addresses)
        );

        return $validator;
    }

    /**
     * Every string that appears anywhere in the session, as one blob a raw email address or
     * phone number can be searched for.
     *
     * Searched over the WHOLE payload rather than over the two contact attributes on purpose: a
     * digest in the store plus the raw value in a sibling attribute would be no reduction at
     * all, and that is the privacy guarantee LOQ-17149 states.
     *
     * @param ArrayObject $sessionStore Backing store from createSessionDouble().
     */
    private function sessionPayload(ArrayObject $sessionStore): string
    {
        return (string)json_encode(iterator_to_array($sessionStore));
    }
}
