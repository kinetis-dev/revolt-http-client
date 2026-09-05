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
 *
 * Requests here run from plain top-level code — the same path a PHP-FPM
 * consumer without concurrently() takes — so this suite also pins that
 * the event-loop await keeps that path fast rather than falling into
 * Symfony's one-second transport poll tick.
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
        self::assertSame(200, $response->status());
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
        yield 'no trailing slash, bare target' => ['base' => self::BASE . '/v1', 'target' => 'orders'];
        yield 'trailing slash, rooted target' => ['base' => self::BASE . '/v1/', 'target' => '/orders'];
        yield 'trailing slash, bare target' => ['base' => self::BASE . '/v1/', 'target' => 'orders'];
    }

    /**
     * A base path is a prefix that a relative target extends, never one
     * a rooted target replaces — the same URL comes out whichever side
     * carries the slash.
     */
    #[DataProvider('basePathJoinProvider')]
    public function test_a_base_path_is_a_prefix_the_target_extends(string $base, string $target): void
    {
        self::assertSame('/v1/orders', $this->http($base)->get($target)->jsonPath('path'));
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

    public function test_a_caller_supplied_content_type_wins_over_the_encoding_default(): void
    {
        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
            ->post('/orders', ['sku' => 'A1']);

        self::assertStringContainsString('application/vnd.api+json', (string) $response->jsonPath('contentType'));
        self::assertSame('{"sku":"A1"}', $response->jsonPath('body'));
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

    public function test_with_basic_auth_sets_an_authorization_header(): void
    {
        $response = $this->http()->withBasicAuth('user', 'pass')->get('/me');

        self::assertSame('Basic ' . base64_encode('user:pass'), $response->jsonPath('headers.authorization'));
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

    /**
     * @return iterable<string, array{configured: string, perCall: string}>
     */
    public static function differentlyCasedHeaderNameProvider(): iterable
    {
        yield 'lowercase configured, uppercase per-call' => ['configured' => 'authorization', 'perCall' => 'Authorization'];
        yield 'uppercase configured, lowercase per-call' => ['configured' => 'Authorization', 'perCall' => 'authorization'];
    }

    /**
     * HTTP field names are case-insensitive — a per-call header must
     * override a configured one for the same name regardless of which
     * casing either side used, so only one value ever reaches the
     * server, never both as ambiguous duplicates.
     */
    #[DataProvider('differentlyCasedHeaderNameProvider')]
    public function test_a_per_call_header_overrides_a_configured_one_regardless_of_casing(
        string $configured,
        string $perCall,
    ): void {
        $response = $this->http()
            ->withHeaders([$configured => 'configured-value'])
            ->send('GET', '/me', ['headers' => [$perCall => 'per-call-value']]);

        self::assertSame('per-call-value', $response->jsonPath('headers.authorization'));
    }

    /**
     * A second withHeaders() call overrides the first for the same
     * header name, case-insensitively — chained client-level
     * configuration, not just a per-call override.
     */
    public function test_a_later_with_headers_call_overrides_an_earlier_one_regardless_of_casing(): void
    {
        $response = $this->http()
            ->withHeaders(['X-Tenant' => 'first'])
            ->withHeaders(['x-tenant' => 'second'])
            ->get('/me');

        self::assertSame('second', $response->jsonPath('headers.x-tenant'));
    }

    /**
     * Overriding one header name must never disturb an unrelated one —
     * different names accumulate.
     */
    public function test_unrelated_headers_accumulate_across_configured_and_per_call(): void
    {
        $response = $this->http()
            ->withHeaders(['X-Tenant' => 'acme'])
            ->send('GET', '/me', ['headers' => ['X-Request-Id' => 'abc']]);

        self::assertSame('acme', $response->jsonPath('headers.x-tenant'));
        self::assertSame('abc', $response->jsonPath('headers.x-request-id'));
    }

    /**
     * A header given several values for the same name — the documented
     * list form — sends every value. PHP's own SAPI joins repeated
     * incoming header lines with ", " (RFC 9110), so the
     * comma-joined value reaching the server is what proves both lines
     * were sent, not just one.
     */
    public function test_a_header_value_list_sends_every_value(): void
    {
        $response = $this->http()->send('GET', '/me', ['headers' => ['X-Custom' => ['first', 'second']]]);

        self::assertSame('first, second', $response->jsonPath('headers.x-custom'));
    }

    /**
     * The raw `"Name: value"` line form is the other documented way to
     * write a header; a per-call override given that way still wins over
     * a configured associative one for the same name.
     */
    public function test_a_raw_line_per_call_header_overrides_a_configured_associative_one(): void
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => 'configured-value'])
            ->send('GET', '/me', ['headers' => [0 => 'Authorization: per-call-value']]);

        self::assertSame('per-call-value', $response->jsonPath('headers.authorization'));
    }

    /**
     * The header name is read up to only the first colon, so a value
     * that contains its own colon — a URL, here — reaches the server
     * intact rather than being misread as part of the name.
     */
    public function test_a_raw_line_headers_value_may_contain_its_own_colon(): void
    {
        $response = $this->http()->send('GET', '/me', ['headers' => [0 => 'X-Custom: http://example.com']]);

        self::assertSame('http://example.com', $response->jsonPath('headers.x-custom'));
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

    /**
     * A 3xx is terminal: the client reports it and hands over the
     * Location it was given, rather than reissuing the request — with
     * this client's Authorization header on it — against whatever origin
     * that header names.
     */
    public function test_a_redirect_is_a_terminal_response_rather_than_a_second_request(): void
    {
        $response = $this->http()->withToken('secret-key')->get('/redirect');

        self::assertSame(302, $response->status());
        self::assertTrue($response->redirect());
        self::assertSame('/me', $response->header('Location'));
        self::assertTrue($response->jsonPath('redirected'));
    }

    public function test_throw_raises_on_an_error_status_and_names_only_method_origin_and_status(): void
    {
        try {
            $this->http()->get('/status/500')->throw();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ErrorStatus, $e->category);
            self::assertSame(500, $e->status);
            self::assertSame('GET http://127.0.0.1:8099 returned HTTP 500.', $e->getMessage());
            self::assertStringNotContainsString('/status/500', $e->getMessage());
            self::assertStringNotContainsString('deliberate failure', $e->getMessage());
        }
    }

    /**
     * The upstream's own error payload is where an API explains itself,
     * and it is read from the response — the one place taking it is a
     * decision — never from an exception that a log pipeline records by
     * default.
     */
    public function test_the_error_body_stays_readable_on_the_response(): void
    {
        $response = $this->http()->get('/status/500');

        self::assertSame('deliberate failure', $response->jsonPath('error'));
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

    /**
     * An id wider than PHP's own int type keeps every digit instead of
     * being rounded into a float; an id that does fit stays an int, so
     * the flag costs nothing for ordinary numbers.
     */
    public function test_an_integer_too_wide_for_php_is_decoded_as_a_string(): void
    {
        $body = $this->http()->get('/big-int')->json();

        self::assertSame('12345678901234567890123', $body['id']);
        self::assertSame(9007199254740993, $body['safe']);
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

    /**
     * Discarding a real response releases it against a real connection
     * and stays quiet about it; the pool behind this transport serves
     * every later test in this class, which is what proves the release
     * left it usable.
     */
    public function test_discarding_a_real_response_releases_it_and_leaves_the_client_usable(): void
    {
        $response = $this->http()->get('/orders');

        self::assertSame(200, $response->status());

        $response->discard();
        $response->discard();

        self::assertSame(200, $this->http()->get('/ok')->status());
    }

    /**
     * A body exactly at the ceiling comes back whole, over a real
     * transport and a real connection.
     */
    public function test_a_body_exactly_at_the_ceiling_comes_back_whole(): void
    {
        $response = $this->http()->withMaxResponseBytes(4096)->get('/bytes/4096');

        self::assertSame(4096, strlen($response->body()));
    }

    /**
     * A response whose declared length is past the ceiling is refused
     * before the body is fetched.
     */
    public function test_a_declared_length_past_the_ceiling_is_refused(): void
    {
        $response = $this->http()->withMaxResponseBytes(1024)->get('/bytes/8192');

        self::assertSame('8192', $response->header('Content-Length'));

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('past the 1024-byte ceiling');
        $response->body();
    }

    /**
     * The server flushes this one as it goes, so there is no
     * Content-Length to check and nothing to believe: the ceiling has to
     * hold on the transfer itself, through the progress hook this client
     * hands the real transport.
     */
    public function test_a_response_with_no_declared_length_is_bounded_by_the_transfer(): void
    {
        $response = $this->http()->withMaxResponseBytes(2048)->get('/stream/65536');

        self::assertNull($response->header('Content-Length'));

        try {
            $response->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        // The pool behind this transport serves every later test in this
        // class, so a usable client afterwards is what proves the
        // aborted transfer was cleaned up rather than left half-read.
        self::assertSame(200, $this->http()->get('/ok')->status());
    }

    /**
     * Every request asks for identity encoding, which is what makes the
     * byte ceiling a bound on memory: with no Accept-Encoding of its own
     * a Symfony response inflates a compressed body transparently, and a
     * kilobyte off the wire becomes a megabyte held before anything can
     * measure it.
     */
    public function test_every_request_asks_the_server_for_identity_encoding(): void
    {
        self::assertSame('identity', $this->http()->get('/me')->jsonPath('headers.accept-encoding'));
    }

    /**
     * A megabyte that arrives as a kilobyte of gzip is a kilobyte held.
     * The ceiling here is far below the decoded size and far above the
     * wire size: the body comes back, and what came back is the
     * compressed bytes, not the megabyte they stand for.
     */
    public function test_a_compressed_body_is_bounded_by_its_wire_size_rather_than_its_decoded_size(): void
    {
        $response = $this->http()->withMaxResponseBytes(64 * 1024)->get('/gzip/1048576');

        self::assertSame('gzip', $response->header('Content-Encoding'));

        $body = $response->body();

        self::assertLessThanOrEqual(64 * 1024, strlen($body));
        self::assertSame(1048576, strlen((string) gzdecode($body)));
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function compressedCeilingProvider(): iterable
    {
        yield 'exactly at the wire size' => [0, true];
        yield 'one byte under the wire size' => [-1, false];
    }

    /**
     * The boundary is the bytes that arrived, compressed or not: exactly
     * the ceiling passes, one byte over throws. The wire size is
     * measured first rather than assumed, since it is gzip's to decide.
     *
     * @param int $offset added to the wire size to make the ceiling
     */
    #[DataProvider('compressedCeilingProvider')]
    public function test_a_compressed_body_meets_the_ceiling_at_its_wire_size(int $offset, bool $passes): void
    {
        $wireSize = (int) $this->http()->get('/gzip/65536')->header('Content-Length');

        self::assertGreaterThan(0, $wireSize);

        $response = $this->http()->withMaxResponseBytes($wireSize + $offset)->get('/gzip/65536');

        if ($passes) {
            self::assertSame($wireSize, strlen($response->body()));

            return;
        }

        try {
            $response->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    /**
     * A megabyte behind a retrying client and a 512-byte ceiling, over a
     * real transport: the refusal is the same whether the transport had
     * already buffered past the ceiling while answering the status wait
     * or only does so at the read. What is asserted here is the part
     * that holds either way — a body past the ceiling is never returned,
     * and the failure is the ceiling's rather than anything else's.
     *
     * Which of the two routes reports it is pinned deterministically in
     * `HttpResponseSizeTest`, where the transport reports its own bytes
     * instead of a real one being timed.
     */
    public function test_a_retrying_client_never_returns_a_compressed_body_past_the_ceiling(): void
    {
        try {
            $body = $this->http()->withRetries(1)->withMaxResponseBytes(512)->get('/gzip/1048576')->body();

            self::fail(sprintf('Expected HttpRequestException; %d bytes came back instead.', strlen($body)));
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    /**
     * An error payload is untrusted data whether or not it is
     * compressed, so the ceiling holds for the body a caller reads to
     * find out what went wrong — and leaves a small one readable.
     */
    public function test_an_error_body_is_bounded_by_the_ceiling_and_a_small_one_still_reads(): void
    {
        $bounded = $this->http()->withMaxResponseBytes(8);

        self::assertSame(500, $bounded->get('/status/500')->status());

        try {
            $bounded->get('/status/500')->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(
            'deliberate failure',
            $this->http()->withMaxResponseBytes(4096)->get('/status/500')->jsonPath('error'),
        );
    }

    public function test_a_retrying_client_leaves_a_successful_request_alone(): void
    {
        $response = $this->http()->withToken('secret-key')->withRetries(2)->get('/me');

        self::assertSame(200, $response->status());
        self::assertSame('Bearer secret-key', $response->jsonPath('headers.authorization'));
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
