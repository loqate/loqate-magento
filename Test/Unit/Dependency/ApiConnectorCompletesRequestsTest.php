<?php

namespace Loqate\ApiIntegration\Test\Unit\Dependency;

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
 * vendor/ - and it is what makes the `^1.1.3` constraint in composer.json an enforced
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
        $client = new HttpClient();
        $params = ['Text' => '1 High Street', 'Country' => 'GB'];
        $call = match ($method) {
            'get' => static fn () => $client->get($endpoint, $params),
            'post' => static fn () => $client->post($endpoint, $params),
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

        try {
            return $call();
        } catch (\Throwable $escaped) {
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
     * @return array{process: resource, pipes: array, address: string} Handle for
     *         stopStubEndpoint(), plus the host:port the stub is listening on.
     */
    private function startStubEndpoint(): array
    {
        $child = sprintf(
            'require %s; $test = %s; $test::serveOneRequest();',
            var_export(__DIR__ . '/../../bootstrap.php', true),
            var_export(self::class, true)
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
     * @return void
     */
    public static function serveOneRequest(): void
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

        fwrite($connection, sprintf(
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            strlen(self::STUB_RESPONSE_BODY),
            self::STUB_RESPONSE_BODY
        ));

        fclose($connection);
        fclose($server);
    }
}
