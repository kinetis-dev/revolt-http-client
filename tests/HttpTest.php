<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Fiber;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Against a real HTTP server: what the client claims to send is asserted
 * from the server's own view of the request, not from the client's
 * internal state.
 */
final class HttpTest extends TestCase
{
    private const string HOST = '127.0.0.1:8099';
    private const string BASE = 'http://127.0.0.1:8099';

    /** @var resource */
    private static $serverProcess;

    private static ?HttpClientInterface $transport = null;

    public static function setUpBeforeClass(): void
    {
        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/reflect-server.php'],
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
     * fixed sleep that races the server's own startup.
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

    /**
     * One transport for the whole class: a fresh Http() builds its own
     * connection pool, and against a keep-alive server that costs a new
     * connection per test for no added coverage.
     */
    private function http(string $baseUrl = self::BASE): Http
    {
        return new Http(self::$transport ??= AmpHttpClientFactory::create())->withBaseUrl($baseUrl);
    }

    public function test_get_sends_the_method_path_and_query(): void
    {
        $response = $this->http()->get('/orders', ['status' => 'open', 'page' => 2]);

        self::assertTrue($response->successful());
        self::assertSame('GET', $response->jsonPath('method'));
        self::assertSame('/orders', $response->jsonPath('path'));
        self::assertSame(['status' => 'open', 'page' => '2'], $response->jsonPath('query'));
    }

    /**
     * @return iterable<string, array{base: string, target: string}>
     */
    public static function basePathJoinProvider(): iterable
    {
        yield 'no trailing slash, rooted target' => ['base' => self::BASE . '/v1', 'target' => '/orders'];
        yield 'trailing slash, rooted target' => ['base' => self::BASE . '/v1/', 'target' => '/orders'];
        yield 'no trailing slash, bare target' => ['base' => self::BASE . '/v1', 'target' => 'orders'];
        yield 'trailing slash, bare target' => ['base' => self::BASE . '/v1/', 'target' => 'orders'];
    }

    #[DataProvider('basePathJoinProvider')]
    public function test_a_base_path_is_a_prefix_the_target_extends(string $base, string $target): void
    {
        self::assertSame('/v1/orders', $this->http($base)->get($target)->jsonPath('path'));
    }

    public function test_put_patch_and_delete_reach_the_server(): void
    {
        self::assertSame('PUT', $this->http()->put('/orders/1', ['a' => 1])->jsonPath('method'));
        self::assertSame('PATCH', $this->http()->patch('/orders/1', ['a' => 1])->jsonPath('method'));
        self::assertSame('DELETE', $this->http()->delete('/orders/1')->jsonPath('method'));
    }

    public function test_post_sends_a_json_body_by_default(): void
    {
        $response = $this->http()->post('/orders', ['sku' => 'A1', 'quantity' => 2]);

        self::assertStringContainsString('application/json', (string) $response->jsonPath('contentType'));
        self::assertSame(['sku' => 'A1', 'quantity' => 2], json_decode((string) $response->jsonPath('body'), true));
    }

    public function test_as_form_sends_urlencoded_instead(): void
    {
        $response = $this->http()->asForm()->post('/orders', ['sku' => 'A1', 'quantity' => 2]);

        self::assertStringContainsString('application/x-www-form-urlencoded', (string) $response->jsonPath('contentType'));
        self::assertSame('sku=A1&quantity=2', $response->jsonPath('body'));
    }

    public function test_a_caller_supplied_content_type_wins_over_the_encoding_default(): void
    {
        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
            ->post('/orders', ['sku' => 'A1']);

        self::assertStringContainsString('application/vnd.api+json', (string) $response->jsonPath('contentType'));
    }

    public function test_credentials_and_headers_reach_the_server_as_configured(): void
    {
        $response = $this->http()
            ->withToken('secret-key')
            ->withHeaders(['X-Tenant' => 'acme', 'X-Feature' => ['beta', 'preview']])
            ->withQuery(['trace' => 'on'])
            ->get('/me');

        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
        self::assertSame('acme', $response->jsonPath('headers.x-tenant'));
        self::assertSame('beta, preview', $response->jsonPath('headers.x-feature'));
        self::assertSame(['trace' => 'on'], $response->jsonPath('query'));
    }

    public function test_with_basic_auth_sets_an_authorization_header(): void
    {
        $response = $this->http()->withBasicAuth('alice', 'hunter2')->get('/me');

        self::assertSame('Basic ' . base64_encode('alice:hunter2'), $response->jsonPath('headers.authorization'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function differentlyCasedHeaderNameProvider(): iterable
    {
        yield 'lowercase' => ['authorization'];
        yield 'uppercase' => ['AUTHORIZATION'];
        yield 'mixed case' => ['AuThOrIzAtIoN'];
    }

    #[DataProvider('differentlyCasedHeaderNameProvider')]
    public function test_a_per_call_header_overrides_a_configured_one_regardless_of_casing(string $name): void
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => 'Bearer old', 'X-Tenant' => 'acme'])
            ->send('GET', '/me', ['headers' => [$name => 'Bearer new']]);

        self::assertSame('Bearer new', $response->jsonPath('headers.authorization'));
        // The unrelated configured header survives the override.
        self::assertSame('acme', $response->jsonPath('headers.x-tenant'));
    }

    public function test_with_methods_do_not_mutate_the_original_client(): void
    {
        $base = $this->http();
        $base->withToken('secret-key')->withQuery(['trace' => 'on']);

        $response = $base->get('/me');

        self::assertNull($response->jsonPath('headers.authorization'));
        self::assertSame([], $response->jsonPath('query'));
    }

    public function test_an_error_status_is_returned_rather_than_thrown(): void
    {
        $response = $this->http()->get('/status/404');

        self::assertTrue($response->failed());
        self::assertTrue($response->clientError());
        self::assertFalse($response->serverError());
        self::assertSame(404, $response->status());
        // The upstream's own payload stays readable on the response.
        self::assertSame('deliberate failure', $response->jsonPath('error'));
    }

    public function test_a_redirect_is_a_terminal_response_rather_than_a_second_request(): void
    {
        $response = $this->http()->get('/redirect');

        self::assertTrue($response->redirect());
        self::assertSame(302, $response->status());
        self::assertSame('/me', $response->header('Location'));
    }

    public function test_throw_names_only_method_origin_and_status(): void
    {
        try {
            $this->http()->withToken('secret-key')->get('/status/500')->throw();
            self::fail('An error status was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ErrorStatus, $e->category);
            self::assertSame(500, $e->status);
            self::assertSame('GET ' . self::BASE . ' returned HTTP 500.', $e->getMessage());
        }
    }

    public function test_throw_returns_the_response_when_successful(): void
    {
        self::assertSame('/me', $this->http()->get('/me')->throw()->jsonPath('path'));
    }

    public function test_a_non_json_body_fails_with_the_conversion_category(): void
    {
        try {
            $this->http()->get('/not-json')->json();
            self::fail('A body that is not JSON was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Conversion, $e->category);
            self::assertStringNotContainsString('definitely not json', $e->getMessage());
        }
    }

    public function test_an_integer_too_wide_for_php_is_decoded_as_a_string(): void
    {
        self::assertSame('12345678901234567890123', $this->http()->get('/big-int')->jsonPath('id'));
    }

    public function test_the_body_is_read_once_and_reusable(): void
    {
        $response = $this->http()->get('/me');

        self::assertSame($response->body(), $response->body());
        self::assertSame('/me', $response->jsonPath('path'));
        self::assertSame('yes', $response->header('x-reflected'));
        self::assertSame('fallback', $response->jsonPath('nothing.here', 'fallback'));
        self::assertSame(7, $response->jsonPath('nested.items.0.id'));
    }

    public function test_discarding_a_real_response_releases_it_and_leaves_the_client_usable(): void
    {
        $response = $this->http()->send('HEAD', '/bytes/4096');
        self::assertTrue($response->successful());
        $response->discard();
        $response->discard();

        try {
            $response->body();
            self::fail('A read after discard was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Discarded, $e->category);
        }

        self::assertSame(200, $this->http()->get('/me')->status());
    }

    public function test_a_body_exactly_at_the_ceiling_comes_back_whole(): void
    {
        self::assertSame(4096, strlen($this->http()->withMaxResponseBytes(4096)->get('/bytes/4096')->body()));
    }

    public function test_a_declared_length_past_the_ceiling_is_refused(): void
    {
        $response = $this->http()->withMaxResponseBytes(4096)->get('/bytes/4097');

        // The status arrives; only materializing the body is refused.
        self::assertSame(200, $response->status());

        try {
            $response->body();
            self::fail('An over-large declared length was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    public function test_a_response_with_no_declared_length_is_bounded_by_the_transfer(): void
    {
        try {
            $this->http()->withMaxResponseBytes(4096)->get('/stream/65536')->body();
            self::fail('An over-large transfer was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    public function test_every_request_asks_the_server_for_identity_encoding(): void
    {
        self::assertSame('identity', $this->http()->get('/me')->jsonPath('headers.accept-encoding'));
    }

    /**
     * A gzipped body is bounded by its wire size, since identity
     * encoding is what keeps the counted bytes and the held bytes the
     * same bytes. 64 KiB of one repeated character compresses to a
     * couple of hundred, so a ceiling between the two proves which one
     * is measured.
     */
    public function test_a_compressed_body_is_bounded_by_its_wire_size(): void
    {
        $body = $this->http()->withMaxResponseBytes(4096)->get('/gzip/65536')->body();

        self::assertLessThanOrEqual(4096, strlen($body));
        self::assertSame(65536, strlen((string) gzdecode($body)));
    }

    public function test_a_request_suspends_rather_than_blocking_the_process(): void
    {
        // Raced against a timer rather than a second request: PHP's
        // built-in server answers one request at a time, so two requests
        // would be serialized by the *server* and prove nothing about the
        // client. A timer firing while the request is still in flight can
        // only happen if the request suspended.
        $start = microtime(true);
        $timerFiredAfter = null;

        EventLoop::delay(0.1, static function () use ($start, &$timerFiredAfter): void {
            $timerFiredAfter = microtime(true) - $start;
        });

        $fiber = new Fiber(fn (): int => $this->http()->get('/slow')->status());
        $fiber->start();

        EventLoop::run();

        self::assertSame(200, $fiber->getReturn());
        // /slow sleeps 0.4s server-side; a blocking client would keep the
        // loop from running the timer until after that.
        self::assertNotNull($timerFiredAfter);
        self::assertLessThan(0.3, $timerFiredAfter);
    }
}
