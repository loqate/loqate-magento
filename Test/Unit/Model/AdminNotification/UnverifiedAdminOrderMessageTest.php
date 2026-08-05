<?php

namespace Loqate\ApiIntegration\Test\Unit\Model\AdminNotification;

use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Model\AdminNotification\UnverifiedAdminOrderMessage;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The admin notice for the toggle combination that verifies nothing.
 *
 * Observer\QuoteSubmitBefore returns early in the admin area so admin order create is not
 * billed twice for the same order. The cost is that admin order create obeys only the
 * enable_create_order_admin toggles - and in ONE combination, enable_create_order_admin OFF
 * with enable_checkout ON, the observer had been the only thing verifying an admin-created
 * order, so nothing verifies it now.
 *
 * These tests exist because that fact used to live only in a docblock. A merchant does not read
 * docblocks, so the behaviour change was invisible to the only person who could act on it. What
 * is asserted here is therefore not the wording but the TRIGGER: the notice appears in exactly
 * the combination that verifies nothing, and stays silent in every combination that does not.
 */
class UnverifiedAdminOrderMessageTest extends TestCase
{
    /** @var array<string, mixed> Default-scope config values keyed by path, read by the Data stub. */
    private array $config = [];

    /** @var array<int, array<string, mixed>> Per-store-view overrides, keyed by store id then path. */
    private array $storeConfig = [];

    /**
     * Store view ids the store manager reports, or null to make getStores() throw.
     *
     * Defaults to a single store view so the combination tests read as "one ordinary store".
     *
     * @var array<int>|null
     */
    private ?array $storeIds = [1];

    /**
     * Every combination of the two toggles, for both groups, with whether the notice must show.
     *
     * The whole point is that only ONE of the four combinations per group is a problem. A test
     * that only covered that one case would still pass if the notice fired unconditionally,
     * which would train merchants to dismiss it.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public static function toggleCombinationProvider(): array
    {
        $path = static fn (string $group, string $field): string
            => 'loqate_settings/' . $group . '/' . $field;

        $combination = static fn (string $group, $admin, $checkout): array => [
            $path($group, 'enable_create_order_admin') => $admin,
            $path($group, 'enable_checkout') => $checkout,
        ];

        return [
            'address: admin OFF, checkout ON - the unverified combination' => [
                $combination('address_settings', '0', '1'),
                true,
                'this is the case the notice exists for: the observer was the only thing '
                . 'verifying admin-created addresses and now nothing is',
            ],
            'phone: admin OFF, checkout ON - the unverified combination' => [
                $combination('phone_settings', '0', '1'),
                true,
                'the phone toggles have the same pair and the same consequence, so the notice '
                . 'must not be address-only',
            ],
            'address: both ON - verification runs' => [
                $combination('address_settings', '1', '1'),
                false,
                'admin order create IS verified here, via Plugin\Admin\OrderSave',
            ],
            'address: admin ON, checkout OFF - verification runs' => [
                $combination('address_settings', '1', '0'),
                false,
                'the admin toggle is what governs this path, and it is on',
            ],
            'address: both OFF - the merchant asked for no verification' => [
                $combination('address_settings', '0', '0'),
                false,
                'nothing is verified anywhere and that is exactly what was configured, so there '
                . 'is nothing to warn about - warning here would make the notice noise',
            ],
            'address: ints rather than the strings core_config_data returns' => [
                $combination('address_settings', 0, 1),
                true,
                'a data patch writes ints while core_config_data yields strings, and the notice '
                . 'must not depend on which wrote the value',
            ],
        ];
    }

    #[DataProvider('toggleCombinationProvider')]
    public function testTheNoticeShowsOnlyForTheCombinationThatVerifiesNothing(
        array $config,
        bool $expected,
        string $why
    ): void {
        $this->config = $config;

        $this->assertSame(
            $expected,
            $this->message()->isDisplayed(),
            sprintf('Notice visibility is wrong: %s.', $why)
        );
    }

    /**
     * Both groups at once must be reported in ONE notice naming both.
     *
     * Two separate notices would need two identities and two dismissals for one mistake.
     */
    public function testBothGroupsAffectedAreNamedInOneNotice(): void
    {
        $this->config = [
            'loqate_settings/address_settings/enable_create_order_admin' => '0',
            'loqate_settings/address_settings/enable_checkout' => '1',
            'loqate_settings/phone_settings/enable_create_order_admin' => '0',
            'loqate_settings/phone_settings/enable_checkout' => '1',
        ];

        $text = (string)$this->message()->getText();

        $this->assertStringContainsString('Address Settings', $text, 'The address group must be named.');
        $this->assertStringContainsString('Phone Settings', $text, 'The phone group must be named.');
        $this->assertStringContainsString(
            'Enable on Create Order (Admin)',
            $text,
            'The notice must name the setting to change, in the words the admin UI uses, or a '
            . 'merchant cannot act on it.'
        );
    }

    /**
     * The identity must not vary with configuration.
     *
     * Magento keys a dismissal on the identity, so an identity derived from the current config
     * state would make a dismissal apply to one combination only and resurface the notice on
     * the next unrelated change.
     */
    public function testTheIdentityIsStableAcrossConfigurationChanges(): void
    {
        $this->config = [
            'loqate_settings/address_settings/enable_create_order_admin' => '0',
            'loqate_settings/address_settings/enable_checkout' => '1',
        ];
        $whenAffected = $this->message()->getIdentity();

        $this->config = [
            'loqate_settings/address_settings/enable_create_order_admin' => '1',
            'loqate_settings/address_settings/enable_checkout' => '1',
        ];

        $this->assertSame(
            $whenAffected,
            $this->message()->getIdentity(),
            'The identity is a dismissal key, not a description of the current state.'
        );
    }

    public function testTheSeverityIsMajorRatherThanCritical(): void
    {
        $this->assertSame(
            MessageInterface::SEVERITY_MAJOR,
            $this->message()->getSeverity(),
            'Unverified addresses are a real correctness problem, so not a minor notice - but it '
            . 'is a configuration choice the merchant can act on, not a fault, so not critical '
            . 'either. Critical severity here would crowd out genuine outages.'
        );
    }

    /**
     * An unset toggle must not be read as the unverified combination.
     *
     * etc/config.xml defaults both toggles to 1, so absent values mean "never configured".
     * Reading absent-as-off would fire the notice on a fresh install where nothing is wrong.
     */
    public function testAnUnconfiguredModuleDoesNotRaiseTheNotice(): void
    {
        $this->config = [];

        $this->assertFalse(
            $this->message()->isDisplayed(),
            'With no configuration written at all, enable_checkout is not ON, so the combination '
            . 'does not apply and the notice must stay silent.'
        );
    }

    /**
     * A per-STORE-VIEW enable_checkout over a default of off must still raise the notice.
     *
     * This is the case a single read at the current scope cannot see. enable_checkout is
     * showInStore, enable_create_order_admin is showInDefault only, and in the admin area a
     * scope-less read resolves to the DEFAULT store - so a merchant who switched checkout
     * verification on for one store view, with the admin toggle off, has orders going
     * unverified and would have been told nothing.
     */
    public function testAStoreViewOverrideRaisesTheNoticeEvenWhenTheDefaultScopeLooksFine(): void
    {
        $this->config = [
            // Default scope: checkout off, so the pair does NOT trip here.
            'loqate_settings/address_settings/enable_create_order_admin' => '0',
            'loqate_settings/address_settings/enable_checkout' => '0',
        ];
        // Store view 2 turns checkout verification on, which completes the combination.
        $this->storeConfig = [
            2 => ['loqate_settings/address_settings/enable_checkout' => '1'],
        ];
        $this->storeIds = [1, 2];

        $this->assertTrue(
            $this->message()->isDisplayed(),
            'A store view in the unverified combination must raise the notice: one store view is one set '
            . 'of orders going unverified. If this fails, the notice is blind to per-store-view '
            . 'configuration and stays silent in a case it exists for.'
        );
        $this->assertStringContainsString(
            'Address Settings',
            (string)$this->message()->getText(),
            'And the affected group must still be named.'
        );
    }

    /**
     * The group is named ONCE however many store views trip it.
     *
     * Without the early exit, three affected store views would produce "Address Settings,
     * Address Settings, Address Settings".
     */
    public function testAGroupIsNamedOnceHoweverManyStoreViewsTripIt(): void
    {
        $this->config = [
            'loqate_settings/address_settings/enable_create_order_admin' => '0',
            'loqate_settings/address_settings/enable_checkout' => '1',
        ];
        $this->storeIds = [1, 2, 3];

        $this->assertSame(
            1,
            substr_count((string)$this->message()->getText(), 'Address Settings'),
            'One group, named once, however many store views are in the combination.'
        );
    }

    /**
     * An unreadable store list falls back to the current scope rather than throwing.
     *
     * getStores() touches the database, and a system message renders on admin pages that must
     * stay usable - including the pages an admin would use to repair a broken store table. A
     * missed notice beats an unusable admin, and the fallback still catches the default-scope
     * case, which is the common one.
     */
    public function testAnUnreadableStoreListFallsBackToTheCurrentScope(): void
    {
        $this->config = [
            'loqate_settings/address_settings/enable_create_order_admin' => '0',
            'loqate_settings/address_settings/enable_checkout' => '1',
        ];
        $this->storeIds = null;

        $this->assertTrue(
            $this->message()->isDisplayed(),
            'With the store list unreadable, the default-scope combination must still be detected and the '
            . 'notice must not throw out of an admin page render.'
        );
    }

    /**
     * The message under test, reading config through a stub of the module's own helper.
     *
     * @return UnverifiedAdminOrderMessage
     */
    private function message(): UnverifiedAdminOrderMessage
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(
            fn ($configPath) => $this->config[$configPath] ?? null
        );
        // Falls back to the default-scope value when a store has no override of its own, which
        // is what Magento's config inheritance does.
        $helper->method('getConfigValueForStore')->willReturnCallback(
            fn ($configPath, $storeId) => $this->storeConfig[$storeId][$configPath]
                ?? $this->config[$configPath]
                ?? null
        );

        $storeManager = $this->createMock(StoreManagerInterface::class);
        if ($this->storeIds === null) {
            $storeManager->method('getStores')->willThrowException(new \RuntimeException('no store table'));
        } else {
            $stores = [];
            foreach ($this->storeIds as $storeId) {
                $store = new class ($storeId) {
                    /** @var int */
                    private $id;

                    public function __construct(int $id)
                    {
                        $this->id = $id;
                    }

                    public function getId(): int
                    {
                        return $this->id;
                    }
                };
                $stores[] = $store;
            }
            $storeManager->method('getStores')->willReturn($stores);
        }

        return new UnverifiedAdminOrderMessage($helper, $storeManager);
    }
}
