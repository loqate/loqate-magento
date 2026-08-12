<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The config-reading seam every other class in this module depends on.
 *
 * WHY THIS FILE EXISTS. Every other test mocks Helper\Data wholesale, so nothing exercised
 * these methods and nothing pinned the SCOPE they read at. That gap was not theoretical: the
 * admin notice for the unverified-order combination was scope-blind precisely because
 * getConfigValue() reads SCOPE_STORE with no store - which resolves to the default store in the
 * admin area - and no test could tell the difference between naming a store and not naming one.
 * The notice's own test stubs Data and models Magento's inheritance itself, so it asserts that
 * model rather than this mechanism: deleting the $storeId argument from
 * getConfigValueForStore() left that test green.
 *
 * These tests therefore assert the ARGUMENTS handed to ScopeConfigInterface, which is the only
 * place the scope of a read is decided.
 */
class DataTest extends TestCase
{
    /** @var array<int, array{path: string, scope: string, scopeCode: mixed}> Every read, in order. */
    private array $reads = [];

    /**
     * A store-scoped read must NAME the store, or it is not store-scoped.
     *
     * This is the assertion that would have failed the scope-blind read. Dropping the third
     * argument, or passing the wrong scope type, is a silent revert of the fix that made the
     * admin notice see per-store-view configuration.
     */
    public function testAStoreScopedReadNamesTheStoreItWasAskedFor(): void
    {
        $helper = $this->helper(['some/path' => 'value-for-store-7']);

        $value = $helper->getConfigValueForStore('some/path', 7);

        $this->assertSame('value-for-store-7', $value, 'The configured value must be returned unchanged.');
        $this->assertSame(
            [['path' => 'some/path', 'scope' => ScopeInterface::SCOPE_STORE, 'scopeCode' => 7]],
            $this->reads,
            'A store-scoped read must pass SCOPE_STORE *and* the store id. Without the id, Magento '
            . 'resolves the current store - the default store in the admin area - so a per-store-view '
            . 'override becomes invisible and any caller reasoning about another store is silently wrong.'
        );
    }

    /**
     * ...and the scope-less read must NOT name one, which is the contrast that gives the test
     * above its meaning.
     *
     * getConfigValue() is deliberately current-scope: it is what the storefront paths want, and
     * pinning it here stops the two methods being quietly collapsed into one.
     */
    public function testTheCurrentScopeReadDoesNotNameAStore(): void
    {
        $helper = $this->helper(['some/path' => 'value']);

        $helper->getConfigValue('some/path');

        $this->assertSame(
            [['path' => 'some/path', 'scope' => ScopeInterface::SCOPE_STORE, 'scopeCode' => null]],
            $this->reads,
            'getConfigValue() must read SCOPE_STORE with NO store code, so Magento resolves the current '
            . 'store. If a store code appears here, the two read methods have become the same method and '
            . 'one of the two call sites is now wrong.'
        );
    }

    /**
     * A store id of 0 must still be passed, not treated as "no store".
     *
     * 0 is the admin store, a real store id. A truthiness guard would silently downgrade this to
     * a current-scope read - the exact bug this method exists to avoid - and 0 is the value a
     * failed store lookup produces, so it is the one most likely to arrive.
     */
    public function testAStoreIdOfZeroIsStillNamed(): void
    {
        $helper = $this->helper(['some/path' => 'admin-value']);

        $helper->getConfigValueForStore('some/path', 0);

        $this->assertSame(
            [['path' => 'some/path', 'scope' => ScopeInterface::SCOPE_STORE, 'scopeCode' => 0]],
            $this->reads,
            'Store 0 is the admin store, not the absence of a store: it must be passed through.'
        );
    }

    /**
     * Helper under test, over a scope config that records every read.
     *
     * @param array<string, mixed> $values Config values keyed by path.
     * @return Data
     */
    private function helper(array $values): Data
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope = null, $scopeCode = null) use ($values) {
                $this->reads[] = ['path' => $path, 'scope' => $scope, 'scopeCode' => $scopeCode];

                return $values[$path] ?? null;
            }
        );

        return new Data(new Context($scopeConfig), $this->createMock(StoreManagerInterface::class));
    }
}
