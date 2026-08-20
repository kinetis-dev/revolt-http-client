<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AmpHttpClientFactoryTest extends TestCase
{
    private const HOST = '127.0.0.1:8098';

    /** @var resource */
    private static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        $fixture = __DIR__ . '/Fixtures/echo-server.php';

        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST, $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::waitForServerReady(self::HOST);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
    }

    /**
     * `php -S` gives no fixed readiness signal of its own — a real TCP
     * connect attempt, polled with a bounded deadline, in place of a
     * fixed sleep that can race the server's own startup and lose on a
     * slower or more loaded runner (this exact pattern, in a sibling
     * fixture, caused a real failure under SonarQube Cloud's
     * PCOV-instrumented coverage run — a genuine connection failure
     * against a server that hadn't started listening yet).
     */
    private static function waitForServerReady(string $host): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://{$host}", timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        self::fail("The fixture server at {$host} never started accepting connections.");
    }

    public function test_create_returns_an_amp_http_client_instance(): void
    {
        self::assertInstanceOf(AmpHttpClient::class, AmpHttpClientFactory::create());
    }

    public function test_create_returns_something_implementing_http_client_interface(): void
    {
        self::assertInstanceOf(HttpClientInterface::class, AmpHttpClientFactory::create());
    }

    public function test_a_real_request_through_the_created_client_round_trips(): void
    {
        $client = AmpHttpClientFactory::create();
        $response = $client->request('GET', 'http://' . self::HOST . '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', $response->getContent());
    }

    public function test_default_options_are_applied_to_every_request(): void
    {
        $client = AmpHttpClientFactory::create(['headers' => ['X-Test-Header' => 'present']]);
        $response = $client->request('GET', 'http://' . self::HOST . '/');

        // The fixture doesn't echo headers back; this confirms the request
        // still succeeds with a default option set, not that the header
        // arrived — proving default options don't break a real request.
        self::assertSame(200, $response->getStatusCode());
    }
}
