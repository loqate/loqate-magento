<?php

namespace Loqate\ApiIntegration\Test\Unit\Dependency;

use Loqate\ApiConnector\Client\Capture;
use Loqate\ApiConnector\Client\Http\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE MERCHANT-READINESS GUARANTEE for the installed lqt/api-connector (LOQ-17709).
 *
 * WHAT IS BEING GUARANTEED, in the merchant's terms. The version of the SDK that Composer
 * actually resolved into vendor/ can complete an HTTP request and hand back a parsed body, on
 * the PHP version the suite is running on, inside the error handler a live Magento
 * installation has installed. Every Loqate feature the module offers is that one call: address
 * lookup and retrieve, the email and phone checks, single and batch address verification and
 * set-country-by-IP all reach the API through HttpClient::get() or ::post() and nothing else.
 * If this test is red, a merchant on this PHP version gets an error envelope from all of them.
 *
 * WHY THE MODULE NEEDS ITS OWN COPY OF A GUARD THAT ALSO EXISTS UPSTREAM. The SDK's suite
 * proves the SDK's own source is sound; it says nothing about what this module's composer.json
 * lets a merchant install. Those are different questions and LOQ-17709 is exactly the gap
 * between them: `"lqt/api-connector": "^1.1"` was equally satisfied by the fixed 1.1.3 and the
 * broken 1.1.2, so an upstream release could not reach a merchant who had already resolved
 * 1.1.2 and had no reason to move. This test is the module-side half - it asks what is in
 * vendor/ - and it is what makes the `^1.1.4` constraint in composer.json an enforced
 * guarantee rather than a hopeful one.
 *
 * WHY IT DRIVES THE REAL VENDORED CLASS AND A REAL SOCKET. A double cannot answer the
 * question. The defect was PHP 8.5 deprecating curl_close(), which HttpClient called after
 * every request, and a deprecation is only raised when the real function is really called - so
 * a test double, or a real HttpClient pointed at nothing, is green against the version that
 * takes every Loqate feature in a store offline. This is the only test in the suite that opens
 * a socket, and it earns that because the bytes on the wire are the subject: what is asserted
 * is that a decoded body comes back, not that a method was called.
 *
 * WHY IT NEVER TOUCHES api.addressy.com. It answers itself from a stub served by a child PHP
 * process on 127.0.0.1, so it needs no API key, no network and no billable request, and it
 * cannot go red because Loqate had a bad afternoon. Utils\API::BASE_URL is a const pointing at
 * the live API, which is why the test drives HttpClient directly with its own URL instead of
 * going through Client\Capture or Client\Verify.
 *
 * HOW IT FAILS, AND WHAT THAT LOOKS LIKE IN A STORE. On PHP 8.5 against the unfixed 1.1.2 the
 * deprecation is raised inside get()/post(), Magento's error handler turns it into an
 * ErrorException, and it escapes before either method returns its parsed body. A merchant
 * never sees that exception: Client\Capture and Client\Verify wrap each call in
 * `catch (Throwable)` and return `['error' => true, 'message' => …]`, so the store shows a
 * lookup with no suggestions and a verification that came back as a fault. This test therefore
 * asserts the DECODED BODY and reports an escaping Throwable as the envelope the shopper would
 * have been served.
 */
class ApiConnectorCompletesRequestsTest extends TestCase
{
    /**
     * What the stub answers, in the shape a Capture Find response arrives in.
     *
     * Deliberately free of the two markers HttpClient::searchForError() raises on -
     * `Items[0].Error` and a top-level `Number` - so that the only thing that can stop the
     * parsed body being returned is the defect under test rather than the payload.
     */
    private const STUB_RESPONSE_BODY = '{"Items":[{"Id":"GB|RM|A|1","Type":"Address","Text":"1 High Street"}]}';

    /** How long the stub waits for the request, and the parent for the stub's port, in seconds. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * The explicit total limit the never-answers cases pass to get()/post(), in seconds. Short,
     * so the suite stays fast; what is proven is that the limit is honoured, not its size.
     */
    private const GIVE_UP_AFTER_SECONDS = 1;

    /** Slack allowed on top of a limit before it counts as not honoured, in seconds. */
    private const MARGIN_SECONDS = 1.0;

    /**
     * How long the never-answers stub holds a connection before closing it, in seconds. Longer
     * than GIVE_UP_AFTER_SECONDS + MARGIN_SECONDS, so a client that honours its limit has given
     * up well before; short, so a client with no limit (1.1.3) fails the test instead of
     * hanging the suite.
     */
    private const SILENT_HOLD_SECONDS = 3;

    /**
     * The two ways this module reaches Loqate, both of which called the deprecated
     * curl_close(): get() carries lookup, retrieve, the email and phone checks and
     * set-country-by-IP; post() carries single and batch address verification. They are
     * separate cases because they were separate call sites - a fix to one and not the other
     * leaves half the module's features broken in a store.
     *
     * @return array<string, array{string}>
     */
    public static function requestMethodProvider(): array
    {
        return [
            'get - lookup, retrieve, email, phone, ip2country' => ['get'],
            'post - single and batch address verification' => ['post'],
        ];
    }

    /**
     * @param string $method HttpClient method to exercise, 'get' or 'post'.
     */
    #[DataProvider('requestMethodProvider')]
    public function testVendoredHttpClientReturnsADecodedBodyOnThisPhpVersion(string $method): void
    {
        $stub = $this->startStubEndpoint();

        try {
            $response = $this->requestThroughMagentosErrorHandler(
                $method,
                'http://' . $stub['address'] . '/Capture/Interactive/Find/v1.10/json3.ws'
            );
        } finally {
            $this->stopStubEndpoint($stub);
        }

        $this->assertSame(
            json_decode(self::STUB_RESPONSE_BODY, true),
            $response,
            sprintf(
                'The vendored lqt/api-connector answered HttpClient::%s() with something other than the '
                . 'body the endpoint sent. Every Loqate feature in this module reads that return value, so '
                . 'whatever a merchant on PHP %s would see here, they would see in checkout.',
                $method,
                PHP_VERSION
            )
        );
    }

    /**
     * LOQ-20334: AN UPSTREAM THAT ACCEPTS AND NEVER ANSWERS MUST NOT HOLD A SHOPPER FOREVER.
     *
     * Up to 1.1.3 HttpClient set no cURL timeout at all, so a Loqate endpoint that took the
     * connection and then stalled held the PHP worker - and the shopper's checkout - for as long
     * as the network let it. 1.1.4 sets CURLOPT_TIMEOUT from get()/post()'s third argument and
     * turns the failure into an Exception("cURL error 28: …"), which Client\Capture and
     * Client\Verify already turn into an error envelope. The stub here reads the request and
     * sends nothing; the test asserts the SDK gives up inside the limit it was handed, and says
     * so with an exception rather than a value.
     */
    #[DataProvider('requestMethodProvider')]
    public function testVendoredHttpClientGivesUpOnAServerThatNeverAnswers(string $method): void
    {
        $stub = $this->startStubEndpoint(false);

        try {
            [$response, $thrown, $seconds] = $this->attemptThroughMagentosErrorHandler(
                $method,
                'http://' . $stub['address'] . '/Capture/Interactive/Find/v1.10/json3.ws',
                self::GIVE_UP_AFTER_SECONDS
            );
        } finally {
            $this->stopStubEndpoint($stub);
        }

        $this->assertCurlFailure(
            $method,
            28,
            self::GIVE_UP_AFTER_SECONDS + self::MARGIN_SECONDS,
            $response,
            $thrown,
            $seconds,
            sprintf('a server that accepted the request and never answered, with a %d s limit', self::GIVE_UP_AFTER_SECONDS)
        );
    }

    /**
     * LOQ-20334: A REFUSED CONNECTION IS A FAILURE, NOT AN EMPTY ANSWER.
     *
     * 1.1.3 handed curl_exec()'s false straight to json_decode() and returned null, so a request
     * that never reached Loqate looked to HttpClient's caller like a response with nothing in it
     * (Capture, Verify and Extras then answered their generic 'Unexpected error occurred.'). 1.1.4
     * throws Exception("cURL error 7: …"). The address is one this process bound and released
     * a moment ago, so nothing is listening on it and the kernel refuses at once.
     */
    #[DataProvider('requestMethodProvider')]
    public function testVendoredHttpClientReportsARefusedConnectionAsAnException(string $method): void
    {
        [$response, $thrown, $seconds] = $this->attemptThroughMagentosErrorHandler(
            $method,
            'http://' . $this->refusingAddress() . '/Capture/Interactive/Find/v1.10/json3.ws',
            // Short, so a process that grabs the released port and never answers fails this fast.
            self::GIVE_UP_AFTER_SECONDS
        );

        $this->assertCurlFailure(
            $method,
            7,
            self::MARGIN_SECONDS,
            $response,
            $thrown,
            $seconds,
            'a port nothing listens on'
        );
    }

    /**
     * LOQ-20334: an interactive lookup gives up within 8 s. Capture find/retrieve sit in front
     * of a shopper typing an address, where a longer wait is indistinguishable from broken.
     *
     * Asserts what the vendored Capture actually hands HttpClient::get(), not a constant: an SDK
     * that kept Capture::TIMEOUT but stopped passing it would leave lookups on the 30 s default.
     * Same check as the SDK's tests/timeouts.php. A spy replaces Capture's private $httpClient,
     * records each limit and answers from a canned body, so no request leaves the process.
     */
    public function testInstalledCapturePassesAnEightSecondLimitToEveryLookup(): void
    {
        $canned = ['Items' => [['Id' => 'GB|RM|A|1', 'Type' => 'Address', 'Text' => '1 High Street']]];
        $spy = new class ($canned) extends HttpClient {
            /** @var array<int, int|null> Limit each get() was given; null when none was passed. */
            public array $timeouts = [];

            /**
             * @param array $canned What every get() answers.
             */
            public function __construct(private readonly array $canned)
            {
            }

            /**
             * Record the limit and answer without touching the network.
             *
             * @param string $endpoint
             * @param array $params
             * @param int|null $timeout
             * @return array
             */
            public function get(string $endpoint, array $params, ?int $timeout = null)
            {
                $this->timeouts[] = $timeout;

                return $this->canned;
            }
        };

        $capture = new Capture('LOQ-20334-NOT-A-REAL-KEY');
        // Else a renamed property makes the bind add a dynamic one and the real client calls Loqate.
        $this->assertTrue(
            property_exists(Capture::class, 'httpClient'),
            'Capture no longer has a private $httpClient, so the spy cannot be injected; update this test.'
        );
        \Closure::bind(function () use ($spy) {
            $this->httpClient = $spy;
        }, $capture, Capture::class)();

        $lookups = [
            'find' => ['Text' => '1 High Street', 'Country' => 'GB'],
            'retrieve' => ['Id' => 'GB|RM|A|1'],
        ];
        foreach ($lookups as $method => $params) {
            $spy->timeouts = [];

            $this->assertSame($canned['Items'], $capture->$method($params), sprintf(
                'Capture::%s() did not answer from the injected HttpClient, so its limit was not observed.',
                $method
            ));
            $this->assertCount(1, $spy->timeouts, sprintf('Capture::%s() did not call get() exactly once.', $method));

            $limit = $spy->timeouts[0];
            $this->assertNotNull($limit, sprintf(
                'Capture::%s() passes get() no limit, so it waits HttpClient\'s default (none before 1.1.4). '
                . 'Check `composer show lqt/api-connector` reports 1.1.4 or later.',
                $method
            ));
            $this->assertGreaterThan(0, $limit, sprintf('Capture::%s() passes 0, which cURL reads as no limit.', $method));
            $this->assertLessThanOrEqual(8, $limit, sprintf(
                'Capture::%s() passes get() %d s; LOQ-20334 allows an address lookup at most 8 s.',
                $method,
                $limit
            ));
        }
    }

    /**
     * LOQ-20334: an unreachable Loqate is detected within 2 s, whatever the total limit.
     *
     * Reads the constant only; the SDK's tests/timeouts.php proves get()/post() apply it.
     */
    public function testInstalledSdkCapsTheConnectAtTwoSeconds(): void
    {
        $limit = $this->sdkConstant(HttpClient::class, 'CONNECT_TIMEOUT');

        $this->assertGreaterThan(0, $limit, 'HttpClient::CONNECT_TIMEOUT of 0 means cURL\'s own 300 s.');
        $this->assertLessThanOrEqual(2, $limit, sprintf(
            'HttpClient::CONNECT_TIMEOUT is %d s; LOQ-20334 allows connecting at most 2 s.',
            $limit
        ));
    }

    /**
     * LOQ-20334: the default total stays at least 30 s, and above the longest email Timeout this
     * module can send Loqate.
     *
     * Reads the constant only; the SDK's tests/timeouts.php proves get()/post() fall back to it.
     *
     * Verify::verifyEmail() uses the default. Helper\Validator sends Loqate a `Timeout` in ms
     * from email_validation_timeout_value, and Loqate may legitimately take that long to answer;
     * a client limit at or below it cuts off an answer Loqate is still entitled to give and turns
     * a valid email into a fault. The module's figure is read from both etc/config.xml (the
     * default) and etc/adminhtml/system.xml (the admin form maximum, a client-side range check
     * the server does not enforce), so raising either past the SDK's limit turns this red.
     */
    public function testInstalledSdkDefaultLimitOutlastsTheEmailTimeoutSentToLoqate(): void
    {
        $limit = $this->sdkConstant(HttpClient::class, 'TIMEOUT');
        $emailTimeoutMs = $this->longestEmailTimeoutMs();

        $this->assertGreaterThanOrEqual(30, $limit, sprintf(
            'HttpClient::TIMEOUT is %d s; LOQ-20334 requires the default total to be at least 30 s.',
            $limit
        ));
        $this->assertGreaterThan($emailTimeoutMs, $limit * 1000, sprintf(
            'HttpClient::TIMEOUT is %d s but this module can ask Loqate to spend up to %d ms on an '
            . 'email check, so the SDK would give up on answers Loqate is still allowed to send.',
            $limit,
            $emailTimeoutMs
        ));
    }

    // A normal response through get()/post() is unchanged by LOQ-20334: that is exactly what
    // testVendoredHttpClientReturnsADecodedBodyOnThisPhpVersion() asserts, so it is not repeated.

    /**
     * Assert a request failed the way 1.1.4 reports a transport failure: a plain Exception whose
     * message starts "cURL error <errno>", inside $withinSeconds.
     *
     * @param string $method 'get' or 'post'.
     * @param int $errno Expected cURL error number.
     * @param float $withinSeconds Longest the failure may take.
     * @param mixed $response What the SDK returned, if it returned.
     * @param \Throwable|null $thrown What escaped the SDK, if anything.
     * @param float $seconds How long the call took.
     * @param string $against The situation, for the failure message.
     * @return void
     */
    private function assertCurlFailure(
        string $method,
        int $errno,
        float $withinSeconds,
        mixed $response,
        ?\Throwable $thrown,
        float $seconds,
        string $against
    ): void {
        if ($thrown === null) {
            $this->fail(sprintf(
                'HttpClient::%s() against %s returned %s after %.2f s instead of throwing "cURL error %d". '
                . 'The caller cannot tell that from an empty Loqate answer. '
                . 'Check `composer show lqt/api-connector` reports 1.1.4 or later.',
                $method,
                $against,
                var_export($response, true),
                $seconds,
                $errno
            ));
        }

        $this->assertSame(\Exception::class, get_class($thrown), sprintf(
            'HttpClient::%s() against %s let %s escape ("%s"), not the SDK\'s own Exception.',
            $method,
            $against,
            get_class($thrown),
            $thrown->getMessage()
        ));
        $this->assertStringStartsWith(sprintf('cURL error %d:', $errno), $thrown->getMessage(), sprintf(
            'HttpClient::%s() against %s threw the wrong failure.',
            $method,
            $against
        ));
        $this->assertLessThan($withinSeconds, $seconds, sprintf(
            'HttpClient::%s() against %s took %.2f s to give up; the limit is %.2f s.',
            $method,
            $against,
            $seconds,
            $withinSeconds
        ));
    }

    /**
     * A 127.0.0.1 host:port nothing is listening on: bind port 0, note what the kernel gave,
     * release it. Another process could take it in between; on loopback that is vanishingly rare.
     *
     * @return string
     */
    private function refusingAddress(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->fail(sprintf('Could not bind 127.0.0.1 to find a free port: %s (%d)', $errstr, $errno));
        }

        try {
            return stream_socket_get_name($server, false);
        } finally {
            fclose($server);
        }
    }

    /**
     * Read an integer class constant the installed SDK may not have yet, failing with a clear
     * message - rather than a fatal - when it is missing.
     *
     * @param string $class
     * @param string $name
     * @return int
     */
    private function sdkConstant(string $class, string $name): int
    {
        $this->assertTrue(defined($class . '::' . $name), sprintf(
            '%s::%s is not defined, so the installed lqt/api-connector predates LOQ-20334 and sets no '
            . 'such limit. Check `composer show lqt/api-connector` reports 1.1.4 or later.',
            $class,
            $name
        ));

        $value = constant($class . '::' . $name);
        $this->assertIsInt($value, sprintf('%s::%s is not an integer number of seconds.', $class, $name));

        return $value;
    }

    /**
     * The longest email `Timeout`, in ms, this module can send Loqate: the larger of the shipped
     * default and the top of the range the admin form accepts.
     *
     * @return int
     */
    private function longestEmailTimeoutMs(): int
    {
        $etc = __DIR__ . '/../../../etc';

        $default = simplexml_load_file($etc . '/config.xml')->xpath('//email_settings/email_validation_timeout_value');
        $this->assertCount(1, $default, 'etc/config.xml no longer has a single email_validation_timeout_value.');

        $validate = simplexml_load_file($etc . '/adminhtml/system.xml')
            ->xpath('//field[@id="email_validation_timeout_value"]/validate');
        $this->assertCount(1, $validate, 'etc/adminhtml/system.xml no longer validates email_validation_timeout_value.');
        $this->assertSame(1, preg_match('/digits-range-\d+-(\d+)/', (string)$validate[0], $range), sprintf(
            'email_validation_timeout_value is no longer range-validated ("%s"), so its maximum is unbounded.',
            (string)$validate[0]
        ));

        return max((int)$default[0], (int)$range[1]);
    }

    /**
     * Make one request through the real vendored HttpClient, under the error handler a live
     * Magento installation runs with.
     *
     * WHY THE ERROR HANDLER, AND WHY error_reporting() TOO. This mirrors
     * Test\Unit\Helper\CapturedAddressStoreTest::verifyThrough() and for the same reason:
     * Magento\Framework\App\ErrorHandler THROWS on every PHP error that error_reporting()
     * reports, and app/bootstrap.php sets error_reporting(E_ALL) unconditionally - so in a
     * store a deprecation is not a log line, it is an aborted request. Both halves are needed.
     * PHP calls a user handler for every error regardless of error_reporting(), so it is the
     * handler's own error_reporting() test - Magento's included - that decides whether the
     * error is promoted, and PHPUnit runs tests with E_DEPRECATED excluded from
     * error_reporting(). Installing the handler without widening error_reporting() would
     * reproduce Magento's handler faithfully and its environment not at all, handing the
     * curl_close() deprecation straight back to PHP and passing against a build that takes
     * every Loqate call in a store offline.
     *
     * Both entry points install that handler, which is why there is no mode in which a
     * merchant escapes this: Bootstrap::run() does it on the web path and bin/magento does it
     * for the CLI, each before MAGE_MODE is consulted at all.
     *
     * The handler is restored in a finally, so the rest of the suite runs under PHPUnit's.
     *
     * @param string $method 'get' or 'post'.
     * @param string $endpoint Absolute URL of the local stub.
     * @return mixed Whatever the SDK returned.
     */
    private function requestThroughMagentosErrorHandler(string $method, string $endpoint)
    {
        [$response, $escaped] = $this->attemptThroughMagentosErrorHandler($method, $endpoint);

        if ($escaped !== null) {
            $this->fail(sprintf(
                'The vendored lqt/api-connector cannot complete a request on PHP %s: HttpClient::%s() let '
                . '%s escape - "%s" (%s:%d). In a store this is not logged and moved past: Client\Capture '
                . 'and Client\Verify catch it and return [\'error\' => true], so address lookup offers no '
                . 'suggestions, every address, email and phone verification comes back a fault, and '
                . 'set-country-by-IP stops pre-selecting the shopper\'s country - on 100%% of requests. '
                . 'Check `composer show lqt/api-connector` reports 1.1.3 or later.',
                PHP_VERSION,
                $method,
                get_class($escaped),
                $escaped->getMessage(),
                $escaped->getFile(),
                $escaped->getLine()
            ));
        }

        return $response;
    }

    /**
     * Make one request through the real vendored HttpClient under Magento's error handler, as
     * requestThroughMagentosErrorHandler() describes, and report what happened instead of
     * judging it: the LOQ-20334 cases EXPECT an exception, so a Throwable is a result here.
     *
     * $timeout is passed as get()/post()'s third argument only when given. PHP silently drops
     * surplus arguments to a userland method, so against an SDK without the parameter (1.1.3)
     * the call still runs - with no limit - and the test fails on what comes back, not on a
     * fatal.
     *
     * @param string $method 'get' or 'post'.
     * @param string $endpoint Absolute URL to request.
     * @param int|null $timeout Total limit in seconds to pass through, or null for the default.
     * @return array{0: mixed, 1: \Throwable|null, 2: float} What the SDK returned, what
     *         escaped it, and how many seconds the call took.
     */
    private function attemptThroughMagentosErrorHandler(string $method, string $endpoint, ?int $timeout = null): array
    {
        $client = new HttpClient();
        $params = ['Text' => '1 High Street', 'Country' => 'GB'];
        $arguments = $timeout === null ? [$endpoint, $params] : [$endpoint, $params, $timeout];
        $call = match ($method) {
            'get' => static fn () => $client->get(...$arguments),
            'post' => static fn () => $client->post(...$arguments),
        };

        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) {
            // Exactly Magento\Framework\App\ErrorHandler::handler(): a suppressed or unreported
            // error is handed back to PHP, everything else becomes an exception.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $started = hrtime(true);
        try {
            return [$call(), null, (hrtime(true) - $started) / 1e9];
        } catch (\Throwable $escaped) {
            return [null, $escaped, (hrtime(true) - $started) / 1e9];
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }
    }

    /**
     * Start the stub endpoint in a child PHP process and wait until it is listening.
     *
     * A CHILD PROCESS IS NOT OPTIONAL: curl_exec() blocks this process until it has a
     * response, so nothing in this process can be the thing that answers it. The child binds
     * port 0 and reports back the port the kernel gave it, rather than the parent picking one,
     * so the test cannot fail because a port looked free a moment ago and no longer is.
     *
     * @param bool $answer False for a stub that accepts the request and never answers it.
     * @return array{process: resource, pipes: array, address: string} Handle for
     *         stopStubEndpoint(), plus the host:port the stub is listening on.
     */
    private function startStubEndpoint(bool $answer = true): array
    {
        $child = sprintf(
            'require %s; $test = %s; $test::serveOneRequest(%s);',
            var_export(__DIR__ . '/../../bootstrap.php', true),
            var_export(self::class, true),
            var_export($answer, true)
        );

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $child],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            $this->fail('Could not start the stub endpoint: proc_open() failed.');
        }

        $address = $this->readStubAddress($process, $pipes);

        return ['process' => $process, 'pipes' => $pipes, 'address' => $address];
    }

    /**
     * Read the host:port line the stub prints once it is listening.
     *
     * stream_select() rather than a bare fgets() so that a child which dies before binding -
     * or never speaks - fails this test in seconds with its own stderr quoted, instead of
     * hanging the suite.
     *
     * @param resource $process
     * @param array $pipes
     * @return string
     */
    private function readStubAddress($process, array $pipes): string
    {
        $read = [$pipes[1]];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, self::TIMEOUT_SECONDS) < 1) {
            $this->failStub($process, $pipes, 'it never reported a port');
        }

        $address = trim((string)fgets($pipes[1]));
        if (!preg_match('/^127\.0\.0\.1:\d+$/', $address)) {
            $this->failStub($process, $pipes, sprintf('it reported "%s" instead of a 127.0.0.1 port', $address));
        }

        return $address;
    }

    /**
     * Report a stub that failed to come up, quoting its stderr, and stop it.
     *
     * @param resource $process
     * @param array $pipes
     * @param string $problem
     * @return never
     */
    private function failStub($process, array $pipes, string $problem): never
    {
        $stderr = trim((string)stream_get_contents($pipes[2]));
        $this->stopStubEndpoint(['process' => $process, 'pipes' => $pipes]);
        $this->fail(sprintf('The stub endpoint did not start: %s. Its stderr: %s', $problem, $stderr ?: '(empty)'));
    }

    /**
     * Close the stub down. Called from a finally, so it must tolerate a stub that already
     * exited on its own - which is the normal case, since it serves exactly one request.
     *
     * @param array $stub Handle from startStubEndpoint().
     * @return void
     */
    private function stopStubEndpoint(array $stub): void
    {
        foreach ($stub['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($stub['process'])) {
            proc_terminate($stub['process']);
            proc_close($stub['process']);
        }
    }

    /**
     * THE STUB ENDPOINT, run in the child process by startStubEndpoint(). Serves exactly one
     * HTTP request with self::STUB_RESPONSE_BODY and exits.
     *
     * Public and static because a child `php -r` invocation is its only caller; it is not a
     * test method and PHPUnit does not treat it as one. It lives in this class rather than in
     * a script of its own so that the request it answers and the body the test asserts cannot
     * drift apart - they are the same constant.
     *
     * It reads the request to completion (headers, then Content-Length bytes for the POST
     * case) before answering. Replying to a POST without draining its body can hand curl a
     * write error instead of the response, which would fail this test for a reason that has
     * nothing to do with the SDK.
     *
     * With $answer false it is the LOQ-20334 hung upstream: it reads the request and then sends
     * nothing, holding the connection until the client hangs up or SILENT_HOLD_SECONDS pass.
     * That bound is what stops an SDK with no timeout from hanging the suite.
     *
     * @param bool $answer False to accept the request and never answer it.
     * @return void
     */
    public static function serveOneRequest(bool $answer = true): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, sprintf('could not listen on 127.0.0.1: %s (%d)', $errstr, $errno));
            exit(1);
        }

        // Report the port the kernel chose, and flush: the parent is blocked on this line.
        fwrite(STDOUT, stream_socket_get_name($server, false) . PHP_EOL);
        fflush(STDOUT);

        $connection = stream_socket_accept($server, self::TIMEOUT_SECONDS);
        if ($connection === false) {
            fwrite(STDERR, sprintf('no request arrived within %d seconds', self::TIMEOUT_SECONDS));
            exit(1);
        }

        $bodyLength = 0;
        while (($line = fgets($connection)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $bodyLength = (int)trim(substr($line, strlen('Content-Length:')));
            }
        }

        while ($bodyLength > 0) {
            $chunk = fread($connection, $bodyLength);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bodyLength -= strlen($chunk);
        }

        if (!$answer) {
            // Blocks until the client hangs up (EOF) or the hold runs out.
            stream_set_timeout($connection, self::SILENT_HOLD_SECONDS);
            fread($connection, 1);
            fclose($connection);
            fclose($server);
            return;
        }

        fwrite($connection, sprintf(
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            strlen(self::STUB_RESPONSE_BODY),
            self::STUB_RESPONSE_BODY
        ));

        fclose($connection);
        fclose($server);
    }
}
