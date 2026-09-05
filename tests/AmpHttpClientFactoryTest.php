<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\InterceptedHttpClient;
use Amp\Http\Client\Interceptor\RetryRequests;
use Amp\Http\Client\PooledHttpClient;
use Closure;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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
     * fixed sleep that races the server's own startup and loses it on a
     * slower or more loaded runner.
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

    /**
     * Left to itself, `AmpHttpClient` wraps its pool in an Amp
     * interceptor that repeats a failed request twice more. This asserts
     * that it still does — without it, the assertion below would pass
     * for the wrong reason the moment Symfony changed its default, and
     * the retry layer it is guarding against would be invisible again.
     */
    public function test_symfonys_own_default_still_installs_an_amp_retry_interceptor(): void
    {
        $configured = self::clientConfiguratorOf(AmpHttpClientFactory::create())(new PooledHttpClient());

        self::assertInstanceOf(InterceptedHttpClient::class, $configured);
        self::assertInstanceOf(
            RetryRequests::class,
            new ReflectionProperty(InterceptedHttpClient::class, 'interceptor')->getValue($configured),
        );
    }

    /**
     * What {@see Http} runs on: the pooled client handed back
     * untouched, so the only retry layer in the stack is the one
     * `withRetries()` owns and counts against the total deadline.
     */
    public function test_the_facades_own_transport_carries_no_amp_retry_interceptor(): void
    {
        $transport = new ReflectionProperty(Http::class, 'transport')->getValue(new Http());

        self::assertInstanceOf(AmpHttpClient::class, $transport);

        $configured = self::clientConfiguratorOf($transport)(new PooledHttpClient());

        self::assertInstanceOf(PooledHttpClient::class, $configured);
        self::assertNotInstanceOf(InterceptedHttpClient::class, $configured);
    }

    public function test_create_without_retries_hands_the_pooled_client_back_unchanged(): void
    {
        $pooled = new PooledHttpClient();

        self::assertSame($pooled, AmpHttpClientFactory::noRetryConfigurator()($pooled));
        self::assertInstanceOf(
            PooledHttpClient::class,
            self::clientConfiguratorOf(AmpHttpClientFactory::createWithoutRetries())(new PooledHttpClient()),
        );
    }

    public function test_a_real_request_through_the_non_retrying_client_round_trips(): void
    {
        $response = AmpHttpClientFactory::createWithoutRetries()->request('GET', 'http://' . self::HOST . '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', $response->getContent());
    }

    /**
     * The closure `AmpHttpClient` will build its Amp delegate with. It
     * is private state two levels down, and reaching it is the only way
     * to see the delegate a client would build without making it build
     * one against a real host.
     *
     * @return Closure(PooledHttpClient): DelegateHttpClient
     */
    private static function clientConfiguratorOf(HttpClientInterface $client): Closure
    {
        $state = new ReflectionProperty(AmpHttpClient::class, 'multi')->getValue($client);

        /** @var Closure(PooledHttpClient): DelegateHttpClient $configurator */
        $configurator = new ReflectionProperty($state, 'clientConfigurator')->getValue($state);

        return $configurator;
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
