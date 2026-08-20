<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use Fiber;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Against a real HTTP server, like this package's other test: what the
 * client claims to send is asserted from the server's own view of the
 * request, not from the client's internal state.
 *
 * Requests here run from plain top-level code — the same path a PHP-FPM
 * consumer without concurrently() takes — so this suite also pins that
 * HttpResponse's event-loop await keeps that path fast rather than
 * falling into Symfony's one-second transport poll tick.
 */
final class HttpTest extends TestCase
{
    private const HOST = '127.0.0.1:8099';
    private const BASE = 'http://127.0.0.1:8099';

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

    /**
     * One transport for the whole class: a fresh Http() builds its own
     * connection pool, and against a keep-alive server that costs a new
     * connection per test for no added coverage.
     */
    private function http(): Http
    {
        return new Http(self::$transport ??= AmpHttpClientFactory::create())->withBaseUrl(self::BASE);
    }

    public function test_get_sends_the_method_path_and_query(): void
    {
        $response = $this->http()->get('/orders', ['status' => 'open', 'page' => 2]);

        self::assertTrue($response->successful());
        self::assertSame(200, $response->status());
        self::assertSame('GET', $response->jsonPath('method'));
        self::assertSame('/orders', $response->jsonPath('path'));
        self::assertSame(['status' => 'open', 'page' => '2'], $response->jsonPath('query'));
    }

    public function test_post_sends_a_json_body_by_default(): void
    {
        $response = $this->http()->post('/orders', ['sku' => 'A1', 'quantity' => 2]);

        self::assertSame('POST', $response->jsonPath('method'));
        self::assertStringContainsString('application/json', (string) $response->jsonPath('contentType'));
        self::assertSame('{"sku":"A1","quantity":2}', $response->jsonPath('body'));
    }

    public function test_as_form_sends_urlencoded_instead(): void
    {
        $response = $this->http()->asForm()->post('/token', ['grant_type' => 'client_credentials']);

        self::assertStringContainsString('application/x-www-form-urlencoded', (string) $response->jsonPath('contentType'));
        self::assertSame('grant_type=client_credentials', $response->jsonPath('body'));
    }

    public function test_put_patch_and_delete_reach_the_server(): void
    {
        self::assertSame('PUT', $this->http()->put('/orders/1', ['a' => 1])->jsonPath('method'));
        self::assertSame('PATCH', $this->http()->patch('/orders/1', ['a' => 1])->jsonPath('method'));
        self::assertSame('DELETE', $this->http()->delete('/orders/1')->jsonPath('method'));
    }

    public function test_with_token_sets_an_authorization_header(): void
    {
        $response = $this->http()->withToken('secret-key')->get('/me');

        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
    }

    public function test_with_headers_accumulates_rather_than_replacing(): void
    {
        $response = $this->http()
            ->withToken('secret-key')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->get('/me', []);

        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
        self::assertSame('acme', $response->jsonPath('headers.x-tenant'));
    }

    public function test_a_per_call_header_keeps_the_clients_own(): void
    {
        $response = $this->http()
            ->withToken('secret-key')
            ->send('GET', '/me', ['headers' => ['X-Request-Id' => 'abc']]);

        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
        self::assertSame('abc', $response->jsonPath('headers.x-request-id'));
    }

    public function test_with_query_merges_into_every_request(): void
    {
        $response = $this->http()->withQuery(['api_version' => '2'])->get('/orders', ['page' => '1']);

        self::assertSame(['api_version' => '2', 'page' => '1'], $response->jsonPath('query'));
    }

    public function test_with_methods_do_not_mutate_the_original_client(): void
    {
        $base = $this->http();
        $authorized = $base->withToken('secret-key');

        self::assertNull($base->get('/me')->jsonPath('headers.authorization'));
        self::assertSame('Bearer secret-key', $authorized->get('/me')->jsonPath('headers.authorization'));
    }

    public function test_an_error_status_is_returned_rather_than_thrown(): void
    {
        $response = $this->http()->get('/status/404');

        self::assertTrue($response->failed());
        self::assertTrue($response->clientError());
        self::assertFalse($response->serverError());
        self::assertSame(404, $response->status());
        self::assertSame('deliberate failure', $response->jsonPath('error'));
    }

    public function test_throw_raises_on_an_error_status_and_names_the_request(): void
    {
        $this->expectException(HttpRequestException::class);
        // The whole message in one assertion — expectExceptionMessage()
        // replaces rather than accumulates, so a second call would
        // silently discard the first. The body belongs in the message
        // because it explains the status.
        $this->expectExceptionMessage(
            'GET http://127.0.0.1:8099/status/500 returned HTTP 500. Response: {"error":"deliberate failure"}',
        );

        $this->http()->get('/status/500')->throw();
    }

    public function test_throw_returns_the_response_when_successful(): void
    {
        self::assertSame(200, $this->http()->get('/ok')->throw()->status());
    }

    public function test_server_error_is_classified_separately(): void
    {
        $response = $this->http()->get('/status/503');

        self::assertTrue($response->serverError());
        self::assertFalse($response->clientError());
    }

    public function test_a_non_json_body_fails_with_a_clear_error(): void
    {
        $response = $this->http()->get('/not-json');

        self::assertSame('definitely not json', $response->body());

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('is not valid JSON');
        $response->json();
    }

    public function test_the_body_is_read_once_and_reusable(): void
    {
        $response = $this->http()->get('/orders');

        self::assertSame($response->body(), $response->body());
        self::assertSame('/orders', $response->jsonPath('path'));
        self::assertSame('/orders', $response->jsonPath('path'));
    }

    public function test_json_path_walks_nested_structures_and_defaults(): void
    {
        $response = $this->http()->get('/orders');

        self::assertSame(7, $response->jsonPath('nested.items.0.id'));
        self::assertNull($response->jsonPath('nested.items.1.id'));
        self::assertSame('fallback', $response->jsonPath('nope.nope', 'fallback'));
    }

    public function test_headers_are_readable_case_insensitively(): void
    {
        $response = $this->http()->get('/orders');

        self::assertSame('yes', $response->header('X-Reflected'));
        self::assertSame('yes', $response->header('x-reflected'));
        self::assertNull($response->header('X-Absent'));
    }

    public function test_retries_are_configurable_without_changing_behavior_on_success(): void
    {
        // The retry decorator wraps the transport; a successful request is
        // unaffected, and the configured client keeps its own options.
        $response = $this->http()->withToken('secret-key')->withRetries(2)->get('/me');

        self::assertSame(200, $response->status());
        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
    }

    public function test_with_basic_auth_and_timeout_reach_the_transport(): void
    {
        // Asserted at the transport boundary via MockHttpClient's
        // callback, which hands over the final resolved options — no
        // server involved.
        $seen = null;
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('{}');
        });

        new Http($transport)->withBasicAuth('user', 'pass')->withTimeout(7.5)->get('https://example.test/');

        self::assertIsArray($seen);
        self::assertSame(7.5, $seen['timeout']);
        // Symfony normalizes auth_basic into the Authorization header.
        self::assertContains('Authorization: Basic ' . base64_encode('user:pass'), $seen['headers']);
    }

    public function test_a_transport_failure_throws_the_same_exception_type(): void
    {
        $http = new Http(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])));

        $response = $http->get('https://unreachable.test/');

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('GET https://unreachable.test/ failed before any response arrived');

        $response->status();
    }

    public function test_a_transport_failure_reports_status_zero(): void
    {
        $http = new Http(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])));

        try {
            $http->get('https://unreachable.test/')->body();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(0, $e->status);
            self::assertNotNull($e->getPrevious());
        }
    }

    public function test_it_accepts_a_mock_transport_for_tests(): void
    {
        // The documented way to test code that calls out over HTTP: swap
        // the transport, touch no network.
        $http = new Http(new MockHttpClient([
            new MockResponse('{"id": 42}', ['http_code' => 200]),
            new MockResponse('nope', ['http_code' => 503]),
        ]));

        self::assertSame(42, $http->get('https://example.test/orders/42')->jsonPath('id'));
        self::assertTrue($http->get('https://example.test/orders/43')->serverError());
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
