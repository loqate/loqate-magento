<?php

namespace Loqate\ApiIntegration\Helper;

use Loqate\ApiIntegration\Logger\Logger;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Throwable;

/**
 * Decides whether a storefront address lookup (loqate/capture/find and retrieve) may run.
 *
 * Those routes are public and every request is a Loqate lookup on the merchant's API key, so
 * they answer 404 while storefront lookup is switched off and are rate limited per client.
 * The admin routes call the same Helper\Controller methods and deliberately do not use this.
 */
class StorefrontCaptureGate
{
    /** Lookups, Find and Retrieve together, one client may make per window when rate_limit is unset or invalid. */
    const DEFAULT_LIMIT = 200;

    /** Length of the fixed rate-limit window, in seconds. */
    const WINDOW_SECONDS = 600;

    const CACHE_KEY_PREFIX = 'loqate_capture_rate_';

    private Data $helper;

    private CacheInterface $cache;

    private RemoteAddress $remoteAddress;

    private DateTime $dateTime;

    private ResultFactory $resultFactory;

    private Logger $logger;

    /**
     * StorefrontCaptureGate constructor
     *
     * @param Data $helper
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     * @param DateTime $dateTime
     * @param ResultFactory $resultFactory
     * @param Logger $logger
     */
    public function __construct(
        Data $helper,
        CacheInterface $cache,
        RemoteAddress $remoteAddress,
        DateTime $dateTime,
        ResultFactory $resultFactory,
        Logger $logger
    ) {
        $this->helper = $helper;
        $this->cache = $cache;
        $this->remoteAddress = $remoteAddress;
        $this->dateTime = $dateTime;
        $this->resultFactory = $resultFactory;
        $this->logger = $logger;
    }

    /**
     * Get the response that refuses this lookup, or null when the lookup may go ahead
     *
     * A refused lookup is not sent to Loqate. Storefront lookup switched off in both checkout
     * and customer account gives Magento's standard 404 and is not counted; a client over the
     * limit gets HTTP 429.
     *
     * @return ResultInterface|null
     */
    public function refusal(): ?ResultInterface
    {
        if (!$this->helper->getConfigValue('loqate_settings/capture_settings/enable_checkout')
            && !$this->helper->getConfigValue('loqate_settings/capture_settings/enable_customer_account')
        ) {
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
            $forward->forward('noroute');
            return $forward;
        }

        if (!$this->isOverLimit()) {
            return null;
        }

        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $json->setHttpResponseCode(429);
        $json->setData([
            'error' => true,
            'message' => __('Too many address lookups. Please wait a few minutes and try again.')
        ]);
        return $json;
    }

    /**
     * Count this request against the client's current window and tell whether it is over the limit
     *
     * A configured limit of 0 turns the limit off: nothing is counted. A request from a private
     * or reserved address comes from the merchant's own proxy, not a shopper, so it is not
     * counted. Fails open: when the cache cannot be read or written the request is allowed, so
     * a broken cache never breaks address lookup in checkout.
     *
     * @return bool
     */
    private function isOverLimit(): bool
    {
        $limit = $this->limit();
        if ($limit === 0) {
            return false;
        }

        $address = $this->clientAddress((string) $this->remoteAddress->getRemoteAddress());
        $window = intdiv((int) $this->dateTime->gmtTimestamp(), self::WINDOW_SECONDS);

        if ($this->isProxyAddress($address)) {
            $this->warnOncePerWindow($address, $window);
            return false;
        }

        $bucket = $this->bucket($address);
        // Hashed because Magento's cache-id normalisation maps "." and ":" to "_", so raw addresses could collide.
        $key = self::CACHE_KEY_PREFIX . hash('sha256', $bucket);

        try {
            // shortcut: load-then-save is not atomic, so a client sending N requests in parallel can get up to about N × limit through per window (capped by PHP-FPM workers); move to an atomic counter (e.g. Redis INCR) if the key's usage in the Loqate account is well above what the refusal log suggests.
            // Stored as "<window>|<count>", with "|1" appended once this window's refusal has been logged.
            $stored = explode('|', (string) $this->cache->load($key), 3);
            // A count from a different window, or a malformed value, starts the count again.
            $current = count($stored) >= 2 && $stored[0] === (string) $window;
            $count = $current ? (int) $stored[1] + 1 : 1;
            $wasLogged = $current && ($stored[2] ?? '') === '1';
            // Not "count === limit + 1": a limit lowered mid-window can leave the count already past it.
            $logNow = $count > $limit && !$wasLogged;
            $this->cache->save(
                $window . '|' . $count . ($wasLogged || $logNow ? '|1' : ''),
                $key,
                [],
                self::WINDOW_SECONDS
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Loqate storefront lookup rate limit skipped, the cache failed: ' . $e->getMessage()
            );
            return false;
        }

        if ($logNow) {
            $this->logger->warning(sprintf(
                'Loqate storefront lookup rate limit reached: client IP %s made more than %d lookups'
                . ' in %d seconds; further lookups are refused until the window ends.',
                $bucket !== '' ? $bucket : '(unknown)',
                $limit,
                self::WINDOW_SECONDS
            ));
        }

        return $count > $limit;
    }

    /**
     * Get the configured lookups per client per window
     *
     * rate_limit is read at default scope only, because one counter per client is shared by every store;
     * a website or store value, which the admin does not show, is ignored.
     *
     * @return int rate_limit; DEFAULT_LIMIT when it is unset or not a non-negative integer
     */
    private function limit(): int
    {
        // The (string) cast matters: ctype_digit(null) and trim(null) raise a deprecation, which Magento's
        // ErrorHandler turns into an exception on every lookup.
        $value = trim((string) $this->helper->getDefaultConfigValue('loqate_settings/capture_settings/rate_limit'));

        // bin/magento config:set skips the admin field's validation, and garbage must not turn the limit off.
        return ctype_digit($value) ? (int) $value : self::DEFAULT_LIMIT;
    }

    /**
     * Tell whether an address from clientAddress() is the merchant's own proxy rather than a shopper
     *
     * @param string $address
     * @return bool True for a private or reserved address; false for a public one or ''
     */
    private function isProxyAddress(string $address): bool
    {
        return $address !== ''
            && (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                // 100.64.0.0/10 (RFC 6598 shared address space) is internal infrastructure too; PHP's flags miss it.
                || ((int) ip2long($address) & 0xFFC00000) === 0x64400000);
    }

    /**
     * Log, at most once per window across all proxy addresses, that a proxy address skipped the limit
     *
     * One key for every address, so forged private addresses cannot write a log line each; the
     * message names the first address seen in the window.
     *
     * @param string $address
     * @param int $window
     * @return void
     */
    private function warnOncePerWindow(string $address, int $window): void
    {
        $key = self::CACHE_KEY_PREFIX . 'private';

        try {
            if ($this->cache->load($key) === (string) $window) {
                return;
            }
            $this->cache->save((string) $window, $key, [], self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            // Only the warning is lost; the request is let through either way.
            return;
        }

        $this->logger->warning(
            'Loqate storefront lookup rate limit skipped: request came from private/reserved address ' . $address
            . '; configure Magento to see the real client IP'
        );
    }

    /**
     * Get the validated client address, with an IPv4-mapped IPv6 address (::ffff:a.b.c.d) unwrapped
     *
     * @param string $ip
     * @return string The address, or '' when it is empty or invalid
     */
    private function clientAddress(string $ip): string
    {
        // Validate before inet_pton(): its warning on bad input becomes an exception in Magento.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $bytes = inet_pton($ip);
        if (substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($bytes, 12));
        }

        return $ip;
    }

    /**
     * Get the rate-limit bucket for a client address from clientAddress()
     *
     * An IPv4 address is its own bucket. An IPv6 address is bucketed by its /64, because one
     * host controls a whole /64 and could otherwise rotate addresses to get round the limit.
     * '' (no valid address) stays '', one bucket shared by all such clients.
     *
     * @param string $address
     * @return string The IPv4 address, the IPv6 network as "prefix::/64", or ''
     */
    private function bucket(string $address): string
    {
        if (strpos($address, ':') === false) {
            return $address;
        }

        return inet_ntop(substr(inet_pton($address), 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
