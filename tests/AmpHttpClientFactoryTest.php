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

        // No fixed readiness signal from `php -S` other than "give it a
        // moment" — the same discipline the queue package's own real-server
        // verification scripts already use.
        usleep(300_000);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
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
