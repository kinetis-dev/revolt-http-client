<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\InterceptedHttpClient;
use Amp\Http\Client\Interceptor\SetRequestHeader;
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
    private const string HOST = '127.0.0.1:8098';

    /** @var resource */
    private static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/echo-server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://' . self::HOST, timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        self::fail('The fixture server at ' . self::HOST . ' never started accepting connections.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
    }

    public function test_a_real_request_through_the_created_client_round_trips(): void
    {
        $client = AmpHttpClientFactory::create(['headers' => ['X-Test-Header' => 'present']]);
        $response = $client->request('GET', 'http://' . self::HOST . '/');

        self::assertInstanceOf(AmpHttpClient::class, $client);
        self::assertInstanceOf(HttpClientInterface::class, $client);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pong', $response->getContent());
    }

    /**
     * The delegate every request runs through: the pooled client handed
     * back untouched, so no Amp interceptor repeats a request beneath
     * the caller that owns the retry decision.
     */
    public function test_the_default_delegate_is_the_pooled_client_untouched(): void
    {
        $pooled = new PooledHttpClient();
        $configured = self::clientConfiguratorOf(AmpHttpClientFactory::create())($pooled);

        self::assertSame($pooled, $configured);
        self::assertNotInstanceOf(InterceptedHttpClient::class, $configured);
    }

    /**
     * A supplied configurator is the delegate, so a caller that wants
     * interceptors of its own gets exactly the ones it installs.
     */
    public function test_an_explicit_configurator_replaces_the_default_delegate(): void
    {
        $chosen = new InterceptedHttpClient(new PooledHttpClient(), new SetRequestHeader('X-Chosen', 'yes'), []);

        $configured = self::clientConfiguratorOf(
            AmpHttpClientFactory::create([], static fn (PooledHttpClient $pooled): DelegateHttpClient => $chosen),
        )(new PooledHttpClient());

        self::assertSame($chosen, $configured);
    }

    /**
     * What {@see Http} runs on: the same interceptor-free delegate, so
     * the only retry layer in the stack is the one `withRetries()` owns
     * and counts against the total deadline.
     */
    public function test_the_facades_own_transport_carries_no_amp_retry_interceptor(): void
    {
        $transport = new ReflectionProperty(Http::class, 'transport')->getValue(new Http());

        self::assertInstanceOf(AmpHttpClient::class, $transport);

        $configured = self::clientConfiguratorOf($transport)(new PooledHttpClient());

        self::assertInstanceOf(PooledHttpClient::class, $configured);
        self::assertNotInstanceOf(InterceptedHttpClient::class, $configured);
    }

    /**
     * The closure `AmpHttpClient` will build its Amp delegate with. It is
     * private state two levels down, and reaching it is the only way to
     * see the delegate a client would build without making it build one
     * against a real host.
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
}
