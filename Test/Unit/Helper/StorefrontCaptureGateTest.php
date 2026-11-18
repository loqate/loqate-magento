<?php

namespace Loqate\ApiIntegration\Test\Unit\Helper;

use Loqate\ApiIntegration\Controller\Capture\Find;
use Loqate\ApiIntegration\Controller\Capture\Retrieve;
use Loqate\ApiIntegration\Helper\Controller;
use Loqate\ApiIntegration\Helper\Data;
use Loqate\ApiIntegration\Helper\StorefrontCaptureGate;
use Loqate\ApiIntegration\Logger\Logger;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The storefront address-lookup routes are switched off with storefront lookup and rate
 * limited per client (LOQ-20339).
 */
class StorefrontCaptureGateTest extends TestCase
{
    /** @var array<string, string> In-memory cache behind the CacheInterface double. */
    private array $cacheEntries = [];

    /** @var array<string, string|null> */
    private array $config = [];

    /** @var array<int, int|null> Lifetime passed to each cache save(). */
    private array $savedLifetimes = [];

    /** What RemoteAddress::getRemoteAddress() returns; false when the client has no address. */
    private string|false $clientIp = '203.0.113.7';

    /** The start of a window, so the window-length test can step to its last second. */
    private int $now = 3000000 * StorefrontCaptureGate::WINDOW_SECONDS;

    protected function setUp(): void
    {
        $this->config = [
            'loqate_settings/capture_settings/enable_checkout' => '1',
            'loqate_settings/capture_settings/enable_customer_account' => '1',
        ];
    }

    public function testAllowsLookupsUpToTheLimit(): void
    {
        $gate = $this->gate();

        for ($i = 0; $i < StorefrontCaptureGate::LIMIT; $i++) {
            $this->assertNull($gate->refusal());
        }
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function controllers(): array
    {
        return [
            'find' => [Find::class, 'find'],
            'retrieve' => [Retrieve::class, 'retrieve'],
        ];
    }

    #[DataProvider('controllers')]
    public function testOverTheLimitAnswers429AndTheControllerMakesNoLookup(string $class, string $method): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains($this->clientIp));
        $lookup = new stdClass();
        $helper = $this->createMock(Controller::class);
        $helper->expects($this->exactly(StorefrontCaptureGate::LIMIT))
            ->method($method)
            ->willReturn($lookup);
        $json = $this->createMock(Json::class);
        $json->expects($this->exactly(2))->method('setHttpResponseCode')->with(429);
        $json->expects($this->exactly(2))->method('setData')->with([
            'error' => true,
            'message' => 'Too many address lookups. Please wait a few minutes and try again.'
        ]);
        $controller = new $class($helper, $this->gate($logger, $json));

        for ($i = 0; $i < StorefrontCaptureGate::LIMIT; $i++) {
            $this->assertSame($lookup, $controller->execute());
        }

        // The second refused request checks the abuse warning is logged only once per window.
        $this->assertSame($json, $controller->execute());
        $this->assertSame($json, $controller->execute());
    }

    #[DataProvider('controllers')]
    public function testTheControllerMakesNoLookupWhenStorefrontLookupIsOff(string $class, string $method): void
    {
        $this->config = [
            'loqate_settings/capture_settings/enable_checkout' => '0',
            'loqate_settings/capture_settings/enable_customer_account' => '0',
        ];
        $helper = $this->createMock(Controller::class);
        $helper->expects($this->never())->method($method);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');
        $cache->expects($this->never())->method('save');
        $forward = $this->createMock(Forward::class);
        $forward->expects($this->once())->method('forward')->with('noroute');
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_FORWARD)
            ->willReturn($forward);
        $gate = new StorefrontCaptureGate(
            $this->helper(),
            $cache,
            $this->remoteAddress(),
            $this->clock(),
            $resultFactory,
            $this->createStub(Logger::class)
        );

        $this->assertSame($forward, (new $class($helper, $gate))->execute());
    }

    /**
     * Each request builds its own controllers and gate; only the cache is shared.
     */
    public function testFindAndRetrieveShareOneCounter(): void
    {
        $find = new Find($this->createStub(Controller::class), $this->gate());
        $retrieve = new Retrieve($this->createStub(Controller::class), $this->gate());

        for ($i = 0; $i < StorefrontCaptureGate::LIMIT / 2; $i++) {
            $find->execute();
            $retrieve->execute();
        }

        $this->assertNotNull($this->gate()->refusal());
    }

    public function testTheLimitLastsExactlyOneWindow(): void
    {
        $gate = $this->gate();
        $this->spendTheLimit($gate);

        $this->now += StorefrontCaptureGate::WINDOW_SECONDS - 1;
        $this->assertNotNull($gate->refusal());

        $this->now += 1;
        $this->assertNull($gate->refusal());
    }

    public function testSavesTheCountForOneWindow(): void
    {
        $this->gate()->refusal();

        $this->assertSame([StorefrontCaptureGate::WINDOW_SECONDS], $this->savedLifetimes);
    }

    public function testCountsEachClientSeparately(): void
    {
        $gate = $this->gate();
        $this->spendTheLimit($gate);
        $this->assertNotNull($gate->refusal());

        $this->clientIp = '198.51.100.20';

        $this->assertNull($gate->refusal());
    }

    public function testClientsWithNoAddressShareOneCounter(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('(unknown)'));
        $gate = $this->gate($logger);
        $this->clientIp = false;
        $this->spendTheLimit($gate);

        $this->clientIp = '';
        $this->assertNotNull($gate->refusal());

        // An invalid address must not get a bucket of its own, or a forged header could pick a fresh one per request.
        $this->clientIp = 'not-an-ip';
        $this->assertNotNull($gate->refusal());
    }

    /**
     * One host controls a whole IPv6 /64, so rotating addresses inside it must not reset the count.
     */
    public function testCountsIpv6ClientsPerSlash64(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('2a00:1450:4009:81a::/64'));
        $gate = $this->gate($logger);
        $this->clientIp = '2a00:1450:4009:81a::1';
        $this->spendTheLimit($gate);

        $this->clientIp = '2a00:1450:4009:81a:ffff:ffff:ffff:ffff';
        $this->assertNotNull($gate->refusal());

        $this->clientIp = '2a00:1450:4009:81b::1';
        $this->assertNull($gate->refusal());
    }

    /**
     * An IPv4-mapped IPv6 address is its IPv4 client, not a member of the shared ::/64.
     */
    public function testCountsIpv4MappedAddressesAsTheirIpv4Address(): void
    {
        $gate = $this->gate();
        $this->clientIp = '::ffff:1.2.3.4';
        $this->spendTheLimit($gate);

        $this->clientIp = '1.2.3.4';
        $this->assertNotNull($gate->refusal());

        $this->clientIp = '::ffff:5.6.7.8';
        $this->assertNull($gate->refusal());

        // Only ::ffff:0:0/96 is mapped, so the IPv4-compatible ::1.2.3.4 is not 1.2.3.4. Depending on the PHP
        // version it is either reserved (unlimited) or counted in ::/64; allowed here either way.
        $this->clientIp = '::1.2.3.4';
        $this->assertNull($gate->refusal());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function proxyAddresses(): array
    {
        return [
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv4 private' => ['10.0.0.5'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique local' => ['fd00::1'],
            'shared address space, first' => ['100.64.1.1'],
            'shared address space, last' => ['100.127.255.254'],
        ];
    }

    /**
     * A private or reserved address is the merchant's own proxy, which carries every shopper.
     */
    #[DataProvider('proxyAddresses')]
    public function testNeverLimitsAProxyAddressAndWarnsOncePerWindow(string $address): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('private/reserved address ' . $address));
        $gate = $this->gate($logger);
        $this->clientIp = $address;

        for ($i = 0; $i < 300; $i++) {
            $this->assertNull($gate->refusal());
        }

        $this->now += StorefrontCaptureGate::WINDOW_SECONDS;
        $this->assertNull($gate->refusal());
    }

    /**
     * One warning per window across all proxy addresses, so forged private addresses cannot flood the log.
     */
    public function testWarnsOncePerWindowAcrossAllProxyAddresses(): void
    {
        $messages = [];
        $logger = $this->createStub(Logger::class);
        $logger->method('warning')->willReturnCallback(function ($message) use (&$messages) {
            $messages[] = $message;
        });
        $gate = $this->gate($logger);

        foreach (self::proxyAddresses() as [$address]) {
            $this->clientIp = $address;
            $this->assertNull($gate->refusal());
        }
        $this->now += StorefrontCaptureGate::WINDOW_SECONDS;
        $this->clientIp = '10.0.0.5';
        $this->assertNull($gate->refusal());

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('private/reserved address 127.0.0.1;', $messages[0]);
        $this->assertStringContainsString('private/reserved address 10.0.0.5;', $messages[1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'documentation range' => ['203.0.113.7'],
            'just past shared address space' => ['100.128.0.1'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function testStillLimitsAPublicAddressAfterAProxyAddress(string $address): void
    {
        $gate = $this->gate();
        $this->clientIp = '10.0.0.5';
        $this->spendTheLimit($gate);

        $this->clientIp = $address;
        $this->spendTheLimit($gate);

        $this->assertNotNull($gate->refusal());
    }

    public function testStartsAgainFromAMalformedStoredCount(): void
    {
        $gate = $this->gate();
        $this->spendTheLimit($gate);
        $key = array_key_first($this->cacheEntries);

        $this->cacheEntries[$key] = 'garbage';

        $this->assertNull($gate->refusal());
        $this->assertSame($this->window() . '|1', $this->cacheEntries[$key]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function storefrontFlags(): array
    {
        return [
            'checkout only' => ['loqate_settings/capture_settings/enable_customer_account'],
            'customer account only' => ['loqate_settings/capture_settings/enable_checkout'],
        ];
    }

    #[DataProvider('storefrontFlags')]
    public function testAllowsLookupsWhenOneStorefrontFlagIsOn(string $flagSwitchedOff): void
    {
        $this->config[$flagSwitchedOff] = '0';

        $this->assertNull($this->gate()->refusal());
        $this->assertSame([$this->window() . '|1'], array_values($this->cacheEntries));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cacheMethods(): array
    {
        return [
            'load fails' => ['load'],
            'save fails' => ['save'],
        ];
    }

    #[DataProvider('cacheMethods')]
    public function testAllowsLookupsWhenTheCacheFails(string $failingMethod): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method($failingMethod)->willThrowException(new RuntimeException('Redis went away'));
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Redis went away'));

        $gate = new StorefrontCaptureGate(
            $this->helper(),
            $cache,
            $this->remoteAddress(),
            $this->clock(),
            $this->createStub(ResultFactory::class),
            $logger
        );

        $this->assertNull($gate->refusal());
    }

    private function window(): int
    {
        return intdiv($this->now, StorefrontCaptureGate::WINDOW_SECONDS);
    }

    private function spendTheLimit(StorefrontCaptureGate $gate): void
    {
        for ($i = 0; $i < StorefrontCaptureGate::LIMIT; $i++) {
            $gate->refusal();
        }
    }

    /**
     * A gate over an in-memory cache, with the client IP and clock read from this test.
     *
     * @param Logger|null $logger
     * @param Json|null $json The result handed out for an over-limit client.
     * @return StorefrontCaptureGate
     */
    private function gate(?Logger $logger = null, ?Json $json = null): StorefrontCaptureGate
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($key) => $this->cacheEntries[$key] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $key, $tags = [], $lifeTime = null) {
            $this->cacheEntries[$key] = $data;
            $this->savedLifetimes[] = $lifeTime;
            return true;
        });
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($json ?? $this->createStub(Json::class));

        return new StorefrontCaptureGate(
            $this->helper(),
            $cache,
            $this->remoteAddress(),
            $this->clock(),
            $resultFactory,
            $logger ?? $this->createStub(Logger::class)
        );
    }

    private function helper(): Data
    {
        $helper = $this->createStub(Data::class);
        $helper->method('getConfigValue')->willReturnCallback(fn ($path) => $this->config[$path] ?? null);

        return $helper;
    }

    private function remoteAddress(): RemoteAddress
    {
        $remoteAddress = $this->createStub(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturnCallback(fn () => $this->clientIp);

        return $remoteAddress;
    }

    private function clock(): DateTime
    {
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturnCallback(fn () => $this->now);

        return $clock;
    }
}
