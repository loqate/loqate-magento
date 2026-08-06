<?php

namespace Loqate\ApiIntegration\Test\Unit\Plugin\Admin;

use ArrayObject;
use Loqate\ApiIntegration\Helper\ShopperScopedSessionStores;
use Loqate\ApiIntegration\Plugin\Admin\OrderSave;
use Loqate\ApiIntegration\Plugin\Admin\ValidateAddress;
use Loqate\ApiIntegration\Plugin\Admin\ValidateCustomer;
use Loqate\ApiIntegration\Test\Unit\Plugin\ShopperSessionHarness;
use Magento\Customer\Controller\Adminhtml\Address\Validate as AddressValidate;
use Magento\Customer\Controller\Adminhtml\Index\Validate as CustomerValidate;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Controller\Adminhtml\Order\Create\Save;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the three adminhtml writers of the two contact bypass stores - 'loqate_email'
 * and 'loqate_phone' - which before LOQ-17149 held the customer's raw email address and phone
 * number: Plugin\Admin\OrderSave (both stores, from an admin order create),
 * Plugin\Admin\ValidateCustomer (the email store) and Plugin\Admin\ValidateAddress (the phone
 * store).
 *
 * WHY THE ADMIN PATH NEEDS ITS OWN TESTS RATHER THAN INHERITING THE STOREFRONT'S. The
 * shopper-ownership flush is a documented NO-OP here: in adminhtml the customer session carries
 * no customer id, so the owner is permanently the guest and there is no identity for the guard
 * to see change (the admin identity lives in Magento\Backend\Model\Auth\Session, which this
 * module deliberately does not read - the price of reading it is a backend dependency in a
 * helper built on every frontend checkout request). That leaves these three plugins as the
 * paths where a store's contents outlive everything: they persist for the whole of an admin's
 * browser session with nothing able to clear them.
 *
 * SO WHAT LOQ-17149 CHANGED HERE IS THE CONTENT, AND THAT IS WHAT THESE TESTS ASSERT. The
 * residual on this path is BILLING ATTRIBUTION - a second admin at one shared browser is not
 * charged for a verification the first already paid for, which is what the store is for - and it
 * is a residual the module can defend. It would NOT be defensible if what the second admin
 * inherited were a readable list of customers' email addresses and phone numbers, which is
 * exactly what it was. Two guarantees are therefore pinned per plugin: the store still does its
 * job (an identical resubmission inside one admin session is not re-verified, so the merchant is
 * not billed twice), and what it retains is a digest that no one can read a customer's contact
 * details out of.
 */
class AdminContactStoresTest extends TestCase
{
    use ShopperSessionHarness;

    /** Session attribute the email bypass list lives under. */
    private const VERIFIED_EMAIL_SESSION_KEY = 'loqate_email';

    /** Session attribute the phone bypass list lives under. */
    private const VERIFIED_PHONE_SESSION_KEY = 'loqate_phone';

    /** A customer's email address, as an admin types it into the panel. */
    private const EMAIL = 'admin.entered.customer@example.com';

    /** A customer's phone number, chosen so no numeric-string comparison can be involved. */
    private const PHONE = '+44 20 7946 0000';

    /**
     * The SECOND number one admin order can carry: Plugin\Admin\OrderSave verifies the telephone
     * on the billing address and on the shipping address, so a single submission writes two
     * entries to the phone store before anything can be resubmitted.
     */
    private const SECOND_PHONE = '+44 20 7946 0999';

    /**
     * The rest of what an admin order-create POST carries, and what used to be copied into the
     * session with it: the two contact stores hold only an email address and a phone number, but
     * the attribute this module wrote on a rejected submission held the entire form.
     */
    private const CUSTOMER_NAME = 'Wilhelmina-Testcase';

    /** @see self::CUSTOMER_NAME */
    private const CUSTOMER_STREET = '17 Nonexistent Wharf';

    /**
     * An admin who submits the same order twice is billed for one email and one phone
     * verification, not two.
     *
     * OrderSave is the widest of the three writers - it verifies the account email address and
     * the telephone on every address on the order - so it is where a lost bypass costs the most.
     * The re-submission is not hypothetical: it is what an admin does after Magento rejects the
     * form for some unrelated reason, which is precisely the case the store exists for.
     */
    public function testAnIdenticalAdminOrderResubmissionIsNotBilledASecondTime(): void
    {
        $harness = $this->createAdminSession();
        $order = $this->orderSave($harness);
        $request = $this->postValue([
            'order' => [
                'billing_address' => ['telephone' => self::PHONE, 'country_id' => 'GB'],
                'account' => ['email' => self::EMAIL],
            ],
        ]);

        $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertSame(
            [self::EMAIL],
            iterator_to_array($harness['emailRequests']),
            'The first admin order create must verify the account email address.'
        );
        $this->assertSame(
            [self::PHONE],
            iterator_to_array($harness['phoneRequests']),
            'And the telephone on the billing address.'
        );

        $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertCount(
            1,
            $harness['emailRequests'],
            'Re-submitting the same order must not pay for the same email address twice. That saving is the '
            . 'whole purpose of these two stores, and it is what the accepted admin residual is a residual OF: '
            . 'if the store stopped matching, every re-submission would be billed again.'
        );
        $this->assertCount(1, $harness['phoneRequests'], 'Nor for the same phone number twice.');
    }

    /**
     * Nothing an admin types into the panel leaves a readable email address or phone number in
     * the session.
     *
     * ASSERTED OVER THE WHOLE PAYLOAD, not over the two attributes: a digest in the store plus
     * the raw value in a sibling attribute would be no reduction at all. This is the assertion
     * that turns the documented admin-to-admin residual from a PII-retention question into a
     * billing-attribution one - the second admin at a shared browser inherits a set of
     * unreadable digests under a salt that dies with the session, and nothing else.
     */
    public function testAnAdminSessionRetainsDigestsAndNotTheCustomersContactDetails(): void
    {
        $harness = $this->createAdminSession();

        $this->orderSave($harness)->aroundExecute(
            $this->postValue([
                'order' => [
                    'billing_address' => ['telephone' => self::PHONE, 'country_id' => 'GB'],
                    'account' => ['email' => self::EMAIL],
                ],
            ]),
            static fn () => 'order saved'
        );

        $payload = $this->sessionPayload($harness['session']);

        $this->assertStringNotContainsString(
            self::EMAIL,
            $payload,
            'The customer\'s email address must not appear anywhere in the admin\'s session. The store only '
            . 'ever COMPARES, so it never needed the value - and on this path nothing ever clears it, because '
            . 'the shopper flush is a no-op in adminhtml.'
        );
        $this->assertStringNotContainsString(
            self::PHONE,
            $payload,
            'Nor must the customer\'s phone number.'
        );
        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_EMAIL_SESSION_KEY);
        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_PHONE_SESSION_KEY);
    }

    /**
     * The same guarantee on the submission that is REJECTED - which is the only submission that
     * stores anything, and the whole reason these two stores exist.
     *
     * WHY THIS IS A SEPARATE TEST AND WHY IT IS THE IMPORTANT ONE. Every other test in this file
     * drives a submission Loqate ACCEPTS, and an accepted submission never reaches
     * Plugin\Admin\OrderSave's error branch. That branch is not an edge case: with prevent_submit
     * off (the shipped default) the module warns once and accepts the value on resubmission, so
     * "rejected, then submitted again" IS the sequence the store is for, and a store entry is
     * only ever written on the way to it. It used to answer that branch by handing the WHOLE POST
     * to Session::setCustomerFormData() - raw - a few lines after storing the digest, so the
     * address and the number went back into the same session the digest had kept them out of, and
     * on this path nothing in adminhtml ever reads that attribute back and clears it. The suite
     * was green throughout, because no test reached line 92 (LOQ-17149).
     *
     * ASSERTED OVER THE WHOLE POST, not just the two values the stores hold: what was copied was
     * the customer's name and street as well, so a test that only looked for the email address
     * would pass on a change that kept copying everything else.
     */
    public function testARejectedAdminOrderLeavesNoPartOfTheSubmissionReadableInTheSession(): void
    {
        $harness = $this->createAdminSession(false);

        $result = $this->orderSave($harness)->aroundExecute(
            $this->postValue([
                'order' => [
                    'billing_address' => [
                        'telephone' => self::PHONE,
                        'country_id' => 'GB',
                        'firstname' => self::CUSTOMER_NAME,
                        'street' => [self::CUSTOMER_STREET],
                    ],
                    'account' => ['email' => self::EMAIL],
                ],
            ]),
            static fn () => 'order saved'
        );

        $this->assertNotSame(
            'order saved',
            $result,
            'Fixture guard: the submission must have been REJECTED and redirected. If it proceeded, this test '
            . 'is exercising the accepted path the other tests already cover and proves nothing about the '
            . 'branch that stores the digests.'
        );
        $this->assertNotSame(
            [],
            iterator_to_array($harness['errorMessages']),
            'Fixture guard: the admin must have been warned, which is what "submit again to use this value" '
            . 'means and what the store entry is written for.'
        );

        $payload = $this->sessionPayload($harness['session']);

        foreach ([self::EMAIL, self::PHONE, self::CUSTOMER_NAME, self::CUSTOMER_STREET] as $submitted) {
            $this->assertStringNotContainsString(
                $submitted,
                $payload,
                sprintf(
                    '"%s" was submitted on an order the module rejected, and none of the POST may be left '
                    . 'readable in the session afterwards. In adminhtml nothing reads back or clears '
                    . '\'customer_form_data\' on the customer session, so anything written there stays for the '
                    . 'whole of the admin\'s browser session - which is why LOQ-17149 removed that write '
                    . 'instead of documenting it.',
                    $submitted
                )
            );
        }

        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_EMAIL_SESSION_KEY);
        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_PHONE_SESSION_KEY);
    }

    /**
     * An order carrying TWO rejected phone numbers can actually be submitted again - which is
     * what the bound has to leave room for, and what a bound of 1 quietly takes away.
     *
     * WHY THIS IS A BEHAVIOURAL TEST AND NOT AN ASSERTION ON THE CONSTANT. The store is a FIFO
     * list, so "big enough" is not a property of the number on its own: it is a property of the
     * number against what ONE submission writes. Plugin\Admin\OrderSave loops every address on
     * the order, so a submission with a shipping and a billing telephone writes TWO entries
     * before the operator can resubmit anything - and if the second write evicts the first, the
     * resubmission warns about the number the first write was supposed to have remembered, the
     * next one warns about the other, and with prevent_submit off (the shipped default) the
     * order can never be placed at all. Nothing on the page tells the operator why. Eviction is
     * evict-then-append, so one submission's own values are always the newest entries and a limit
     * of exactly 2 still holds them: this test therefore goes red at 1, and the floor asserted in
     * AbstractPluginContactStoresTest is that demonstrated 2 plus a margin of one.
     */
    public function testBothPhoneNumbersOnOneOrderSurviveTheSameSubmission(): void
    {
        $harness = $this->createAdminSession(false);
        $order = $this->orderSave($harness);
        $request = $this->postValue([
            'order' => [
                'billing_address' => ['telephone' => self::PHONE, 'country_id' => 'GB'],
                'shipping_address' => ['telephone' => self::SECOND_PHONE, 'country_id' => 'GB'],
                'account' => ['email' => self::EMAIL],
            ],
        ]);

        $rejected = $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertNotSame(
            'order saved',
            $rejected,
            'Fixture guard: Loqate rejects both numbers, so the first submission must be refused - that is the '
            . 'submission whose warnings the second one is allowed to proceed on.'
        );
        $this->assertSame(
            [self::PHONE, self::SECOND_PHONE],
            iterator_to_array($harness['phoneRequests']),
            'Fixture guard: ONE submission must have verified BOTH numbers, which is what fills two slots of '
            . 'the store in a single pass and is the whole premise of this test.'
        );

        // The operator submits the identical order again, which is exactly what the warning
        // "Submit again to use this phone number" instructs them to do.
        $accepted = $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertSame(
            'order saved',
            $accepted,
            'The resubmission must go through. Both numbers were warned about on the first pass, so both must '
            . 'still be remembered on the second - if the store cannot hold one submission\'s worth of values, '
            . 'each pass evicts the entry the next one needs and the operator is warned forever about an order '
            . 'they have already confirmed, with no way to place it and nothing on the page to correct.'
        );
        $this->assertCount(
            2,
            $harness['phoneRequests'],
            'And neither number may be re-verified on that second pass: the whole submission was already paid '
            . 'for once.'
        );
    }

    /**
     * The customer-grid email check behaves the same way: one billable verify per address, and
     * a digest is what is kept.
     *
     * ValidateCustomer runs on every save of the admin customer form, so an admin correcting a
     * name and saving three times would otherwise pay for the same address three times.
     */
    public function testAnAdminRecheckingTheSameCustomerEmailIsBilledOnceAndKeepsOnlyADigest(): void
    {
        $harness = $this->createAdminSession();
        $plugin = new ValidateCustomer(...$this->constructorArguments($harness));
        $request = $this->postValue(['customer' => ['email' => self::EMAIL]]);
        $subject = $this->createMock(CustomerValidate::class);
        $subject->method('getRequest')->willReturn($request->getRequest());

        $plugin->aroundExecute($subject, static fn () => 'validated');
        $plugin->aroundExecute($subject, static fn () => 'validated');

        $this->assertCount(
            1,
            $harness['emailRequests'],
            'Two saves of the same admin customer form must cost one email verification. The form is saved on '
            . 'every correction an admin makes, so without the store each one is billed.'
        );
        $this->assertStringNotContainsString(
            self::EMAIL,
            $this->sessionPayload($harness['session']),
            'And the address the admin typed must not be readable back out of their session afterwards.'
        );
        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_EMAIL_SESSION_KEY);
    }

    /**
     * The address-form phone check, likewise: one billable verify per number, and a digest is
     * what is kept.
     */
    public function testAnAdminRecheckingTheSameAddressPhoneIsBilledOnceAndKeepsOnlyADigest(): void
    {
        $harness = $this->createAdminSession();
        $plugin = new ValidateAddress(...$this->constructorArguments($harness));
        $request = $this->postValue(['telephone' => self::PHONE, 'country_id' => 'GB']);
        $subject = $this->createMock(AddressValidate::class);
        $subject->method('getRequest')->willReturn($request->getRequest());

        $plugin->aroundExecute($subject, static fn () => 'validated');
        $plugin->aroundExecute($subject, static fn () => 'validated');

        $this->assertCount(
            1,
            $harness['phoneRequests'],
            'Two saves of the same admin address form must cost one phone verification.'
        );
        $this->assertStringNotContainsString(
            self::PHONE,
            $this->sessionPayload($harness['session']),
            'And the number the admin typed must not be readable back out of their session afterwards.'
        );
        $this->assertEveryEntryIsADigest($harness, self::VERIFIED_PHONE_SESSION_KEY);
    }

    /**
     * THE ACCEPTED LIMIT, pinned as documented behaviour for the two contact stores rather than
     * inherited from the batch cache's argument: in adminhtml the stores are owned by the guest
     * for the whole session, so an admin-user swap flushes nothing - and what the next admin
     * inherits is a digest.
     *
     * Both halves belong in one test because the limit is only defensible with the second one.
     * "An admin inherits the previous admin's entries" is a data-leak-shaped sentence and is not
     * one: the entries are salted HMACs under a salt that never leaves the session, so what is
     * shared is the BILLABLE CALL - one verification attributed to whichever admin submitted
     * first - and not the customer's contact details. The module has never claimed to attribute
     * per admin user, and closing this would mean reading
     * Magento\Backend\Model\Auth\Session from a class built on every frontend checkout request.
     *
     * If someone ever does close it, this test fails and says so; if someone assumes it is
     * already closed, this test is the counter-example.
     */
    public function testAnAdminUserSwapKeepsTheStoresButWhatItKeepsIsUnreadable(): void
    {
        $harness = $this->createAdminSession();
        $order = $this->orderSave($harness);
        $request = $this->postValue([
            'order' => [
                'billing_address' => ['telephone' => self::PHONE, 'country_id' => 'GB'],
                'account' => ['email' => self::EMAIL],
            ],
        ]);

        $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertSame(
            0,
            $harness['session'][$this->ownerKey()] ?? null,
            'In adminhtml the customer session carries no customer id, so these stores are owned by the GUEST '
            . 'for the whole of the admin\'s session. That is the documented limit: the guard scopes by '
            . 'customer identity, and there is no customer identity here to scope by.'
        );

        // A different admin user now drives the same browser session. Nothing about the CUSTOMER
        // session changes, because the admin identity lives in a different session object that
        // this module deliberately does not read - so there is nothing for the guard to detect.
        $order->aroundExecute($request, static fn () => 'order saved');

        $this->assertCount(
            1,
            $harness['emailRequests'],
            'DOCUMENTED LIMIT, asserted so it cannot drift in either direction: an admin-user swap inside one '
            . 'browser session does NOT flush the contact stores, so the second admin\'s identical submission '
            . 'replays the first admin\'s entry instead of being billed. The consequence is billing '
            . 'ATTRIBUTION - the merchant pays once either way - and never exposure, which is what the '
            . 'assertion below is for. If this ever fails because the backend auth session was injected, that '
            . 'is an improvement: update this test and the ACCEPTED LIMITS note on ShopperScopedSessionStores '
            . 'together.'
        );
        $this->assertStringNotContainsString(
            self::EMAIL,
            $this->sessionPayload($harness['session']),
            'And this is what makes that limit defensible rather than merely accepted: what a second admin '
            . 'inherits is a set of salted digests under a salt that dies with the session, not a readable '
            . 'list of the customers the first admin was working on. Before LOQ-17149 it was the list.'
        );
    }

    /**
     * Every entry in one of the two stores must be something contactDigest() produced.
     *
     * Shape-checked against the production digest length rather than eyeballed, so a change that
     * truncated or un-keyed the digest is reported here as well as at the seam.
     *
     * @param array<string, mixed> $harness
     * @param string $key
     */
    private function assertEveryEntryIsADigest(array $harness, string $key): void
    {
        $stored = (array)($harness['session'][$key] ?? []);

        $this->assertNotSame(
            [],
            $stored,
            sprintf(
                'Fixture guard: "%s" must actually hold something, or the "no readable contact details" '
                . 'assertions above passed over an empty store and proved nothing.',
                $key
            )
        );
        foreach ($stored as $entry) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                (string)$entry,
                sprintf(
                    'Every entry in "%s" must be a full-length salted HMAC. This is the adminhtml path, where '
                    . 'nothing ever flushes the store, so anything readable left here stays for the whole of '
                    . 'the admin\'s browser session.',
                    $key
                )
            );
        }
    }

    /**
     * A Plugin\Admin\OrderSave over the shared admin session, built through its real constructor.
     *
     * @param array<string, mixed> $harness
     */
    private function orderSave(array $harness): OrderSave
    {
        return new OrderSave(...$this->constructorArguments($harness));
    }

    /**
     * The AbstractPlugin constructor arguments, shared by all three admin plugins.
     *
     * Each plugin builds its own ShopperScopedSessionStores inline from the Session it is given,
     * so handing them ONE session double is what puts them in one admin browser session - which
     * is the situation every test in this file is about.
     *
     * @param array<string, mixed> $harness
     * @return array<int, mixed>
     */
    private function constructorArguments(array $harness): array
    {
        return [
            $harness['context'],
            $this->createMock(UrlInterface::class),
            $harness['sessionMock'],
            $harness['validator'],
            $harness['helper'],
            $this->createMock(JsonFactory::class),
        ];
    }

    /**
     * One adminhtml customer session - no customer id, ever - with a counting connector.
     *
     * @param bool $contactChecksPass Whether Loqate ACCEPTS the email addresses and phone numbers
     *                                it is sent. False drives Plugin\Admin\OrderSave's error
     *                                branch, which is the only branch that redirects and the one
     *                                a store entry is written on the way to.
     * @return array<string, mixed>
     */
    private function createAdminSession(bool $contactChecksPass = true): array
    {
        $sessionStore = new ArrayObject();
        // NULL for the whole session and never changed: that IS the adminhtml situation. The
        // admin identity lives in Magento\Backend\Model\Auth\Session, which the guard does not
        // read, so from the customer session's point of view an admin-user swap is invisible.
        $identity = new ArrayObject(['customerId' => null]);
        $emailRequests = new ArrayObject();
        $phoneRequests = new ArrayObject();
        $errorMessages = new ArrayObject();

        return [
            'sessionMock' => $this->createSessionDouble($sessionStore, $identity),
            'context' => $this->createAdminContext($errorMessages),
            'helper' => $this->createConfigHelper([
                'loqate_settings/settings/api_key' => 'TEST-API-KEY-0000',
                // The three admin toggles the contact stores are reached through. The ADDRESS
                // toggles are deliberately left off: verifyMultipleAddresses() and
                // verifyAddress() write different stores with their own tests, and switching
                // them on here would make these tests pass or fail for a reason that is not
                // theirs.
                'loqate_settings/email_settings/enable_create_order_admin' => 1,
                'loqate_settings/phone_settings/enable_create_order_admin' => 1,
                'loqate_settings/email_settings/enable_customer_account_admin' => 1,
                'loqate_settings/phone_settings/enable_customer_account_admin' => 1,
            ]),
            'validator' => $this->createCountingValidator(
                $emailRequests,
                $phoneRequests,
                $contactChecksPass,
                $contactChecksPass
            ),
            'session' => $sessionStore,
            'identity' => $identity,
            'emailRequests' => $emailRequests,
            'phoneRequests' => $phoneRequests,
            'errorMessages' => $errorMessages,
        ];
    }

    /**
     * A Magento\Framework\App\Action\Context whose three collaborators survive the ERROR branch.
     *
     * WHY THE DEFAULT DOUBLE IS NOT ENOUGH, and why that mattered. createMock(Context::class)
     * answers null to getMessageManager(), getResultRedirectFactory() and getRedirect(), because
     * the stub declares no return types - which is sufficient for a submission Loqate ACCEPTS,
     * since that path touches none of them. It is also why a rejected submission could not be
     * driven at all: the first addErrorMessage() on the error branch was a call on null. The
     * branch that was never reached is the branch that stores the digests, so the assertions in
     * this file were passing over the one path they are about (LOQ-17149).
     *
     * Anonymous classes rather than mocks of Magento\Framework\Message\ManagerInterface and its
     * siblings: those three types have no stub under Test/stubs, and AbstractPlugin keeps all
     * three in untyped properties, so what is needed is an object that answers the calls - not
     * one that satisfies a type nothing checks.
     *
     * @param ArrayObject $errorMessages Every error message the plugin raises, in order.
     * @return Context&MockObject
     */
    private function createAdminContext(ArrayObject $errorMessages)
    {
        $messageManager = new class ($errorMessages) {
            /** @var ArrayObject */
            private $messages;

            public function __construct(ArrayObject $messages)
            {
                $this->messages = $messages;
            }

            /**
             * @param mixed $message A Phrase in production, a string under the __() stub.
             * @return $this
             */
            public function addErrorMessage($message)
            {
                $this->messages[] = (string)$message;

                return $this;
            }
        };

        $redirect = new class {
            /**
             * @return string
             */
            public function getRefererUrl()
            {
                return 'https://example.test/admin/sales/order_create/';
            }

            /**
             * @param string $defaultUrl
             * @return string
             */
            public function error($defaultUrl)
            {
                return $defaultUrl;
            }
        };

        $redirectFactory = new class {
            /**
             * @return object A result with the one method the plugin calls on it.
             */
            public function create()
            {
                return new class {
                    /** @var mixed The URL the plugin redirected to; declared, not dynamic. */
                    public $url;

                    /**
                     * @param mixed $url
                     * @return $this
                     */
                    public function setUrl($url)
                    {
                        $this->url = $url;

                        return $this;
                    }
                };
            }
        };

        $context = $this->createMock(Context::class);
        $context->method('getMessageManager')->willReturn($messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getRedirect')->willReturn($redirect);

        return $context;
    }

    /**
     * An admin controller double whose request answers one POST body.
     *
     * The three plugins reach the POST the same way - $subject->getRequest()->getPostValue() -
     * so this returns something that answers both, and the callers hand the inner object to the
     * two Validate subjects, which are separate classes with the same shape.
     *
     * @param array<string, mixed> $post
     */
    private function postValue(array $post): Save
    {
        $request = new class ($post) {
            /** @var array<string, mixed> */
            private $post;

            public function __construct(array $post)
            {
                $this->post = $post;
            }

            /**
             * @return array<string, mixed>
             */
            public function getPostValue()
            {
                return $this->post;
            }
        };

        $subject = $this->createMock(Save::class);
        $subject->method('getRequest')->willReturn($request);

        return $subject;
    }

    /**
     * The session attribute the owning identity is recorded in, read from the production
     * constant so this test describes the real marker rather than a guess at it.
     */
    private function ownerKey(): string
    {
        $reflection = new ReflectionClass(ShopperScopedSessionStores::class);
        if (!$reflection->hasConstant('SESSION_OWNER_KEY')) {
            $this->fail(
                'ShopperScopedSessionStores::SESSION_OWNER_KEY is not defined: the identity the shopper-scoped '
                . 'stores belong to has to be recorded somewhere, or no identity change can be detected.'
            );
        }

        return (string)$reflection->getConstant('SESSION_OWNER_KEY');
    }
}
