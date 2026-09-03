<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use Fiber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The original, identical-casing override behavior this fix must
     * not disturb.
     */
    public function test_a_per_call_header_overrides_a_configured_one_of_the_same_case(): void
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => 'configured-value'])
            ->send('GET', '/me', ['headers' => ['Authorization' => 'per-call-value']]);

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
     * genuinely different names simply accumulate.
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
     * A header intentionally given several values for the same name
     * (Symfony's own accepted `'Name' => ['v1', 'v2']` form) must send
     * every value, not be collapsed to one by the case-insensitive
     * merge — the merge only collapses *different* PHP array keys that
     * name the *same* HTTP field case-insensitively, never a
     * deliberately repeated single header. PHP's own SAPI joins
     * genuinely repeated incoming header lines with ", " (RFC 7230
     * §3.2.2), so the comma-joined value reaching the server is exactly
     * what proves both lines were actually sent, not just one.
     */
    public function test_repeated_intentional_header_values_all_survive(): void
    {
        $response = $this->http()->send('GET', '/me', ['headers' => ['X-Custom' => ['first', 'second']]]);

        self::assertSame('first, second', $response->jsonPath('headers.x-custom'));
    }

    /**
     * `send()` accepts raw Symfony options, including the numerically-
     * indexed "Name: value" line form for headers — a per-call override
     * given this way must still deterministically win over a configured
     * header given the ordinary associative form, for the same name.
     */
    public function test_a_numeric_line_per_call_header_overrides_a_configured_associative_one(): void
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => 'configured-value'])
            ->send('GET', '/me', ['headers' => [0 => 'Authorization: per-call-value']]);

        self::assertSame('per-call-value', $response->jsonPath('headers.authorization'));
    }

    /**
     * The header name is read up to only the *first* colon in a raw
     * "Name: value" line, matching Symfony's own normalizeHeaders() —
     * a value that itself contains a colon (a URL, here) must not be
     * misread as part of the header name and must reach the server
     * intact.
     */
    public function test_a_numeric_line_headers_value_may_contain_its_own_colon(): void
    {
        $response = $this->http()->send('GET', '/me', ['headers' => [0 => 'X-Custom: http://example.com']]);

        self::assertSame('http://example.com', $response->jsonPath('headers.x-custom'));
    }

    /**
     * A numeric header entry that isn't a real "Name: value" string
     * can't be merged safely — rejected clearly here, rather than
     * silently producing a wrong split or a vaguer failure from
     * Symfony's own normalization further down the line.
     */
    public function test_a_malformed_numeric_header_entry_is_rejected_clearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A numeric header entry must be a "Name: value" string');

        $this->http()->send('GET', '/me', ['headers' => [0 => 'NoColonHere']]);
    }

    /**
     * Two *separate* numeric "Name: value" lines for the same header
     * name, within one array, is how a deliberate repeat is expressed
     * in raw-line form — both values must reach the server, not just
     * whichever one Symfony's own per-entry-reset normalization would
     * otherwise have kept. Confirmed via the real reflect-server rather
     * than the merged array's own shape, so this actually proves the
     * value on the wire, not just an intermediate representation.
     */
    public function test_two_numeric_lines_for_the_same_name_both_reach_the_server(): void
    {
        $response = $this->http()->send('GET', '/me', [
            'headers' => [0 => 'X-Custom: first', 1 => 'X-Custom: second'],
        ]);

        self::assertSame('first, second', $response->jsonPath('headers.x-custom'));
    }

    /**
     * Two *associative* case-variant keys for the same header name,
     * within one array, is a same-array casing collision, not a
     * deliberate repeat — the last occurrence must win outright, with
     * only its own value reaching the server.
     */
    public function test_same_array_associative_case_variants_resolve_to_only_the_last_one(): void
    {
        $response = $this->http()->send('GET', '/me', [
            'headers' => ['Authorization' => 'first', 'authorization' => 'second'],
        ]);

        self::assertSame('second', $response->jsonPath('headers.authorization'));
    }

    /**
     * A header name given via *both* the associative and raw-line form
     * within one array has no single principled resolution — rejected
     * outright rather than arbitrarily preferring one form over the
     * other.
     */
    public function test_mixing_associative_and_numeric_line_forms_for_the_same_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Header "authorization" is given in both associative and raw "Name: value" line form',
        );

        $this->http()->send('GET', '/me', [
            'headers' => ['Authorization' => 'assoc-value', 0 => 'authorization: raw-value'],
        ]);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function emptyNumericHeaderNameProvider(): iterable
    {
        yield 'no name before the colon' => [': value'];
        yield 'whitespace-only name before the colon' => ['   : value'];
    }

    /**
     * A numeric line with nothing (or only whitespace) before the colon
     * has no real header name — the colon-only check alone would accept
     * this, so the name portion is validated separately after
     * extraction.
     */
    #[DataProvider('emptyNumericHeaderNameProvider')]
    public function test_a_numeric_header_entry_with_an_empty_name_is_rejected_clearly(string $line): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not have an empty header name');

        $this->http()->send('GET', '/me', ['headers' => [0 => $line]]);
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
        // getMessage() deliberately excludes the response body — see
        // test_the_full_diagnostic_detail_is_reachable_but_not_in_getMessage()
        // for where that detail actually lives.
        $this->expectExceptionMessage('GET http://127.0.0.1:8099/status/500 returned HTTP 500.');

        $this->http()->get('/status/500')->throw();
    }

    public function test_the_full_diagnostic_detail_is_reachable_but_not_in_getmessage(): void
    {
        try {
            $this->http()->get('/status/500')->throw();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertStringNotContainsString('deliberate failure', $e->getMessage());
            self::assertSame('http://127.0.0.1:8099/status/500', $e->diagnosticUrl());
            self::assertSame('{"error":"deliberate failure"}', $e->diagnosticBody());
            self::assertStringContainsString('deliberate failure', $e->diagnosticMessage());
        }
    }

    public function test_getmessage_strips_userinfo_and_the_query_string_but_diagnostic_url_keeps_them(): void
    {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('nope', ['http_code' => 403]),
        ));

        try {
            $http->get('https://user:secret@api.example.com/orders?signature=abc123')->throw();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('signature=abc123', $e->getMessage());
            self::assertSame('GET https://api.example.com/orders returned HTTP 403.', $e->getMessage());
            self::assertSame('https://user:secret@api.example.com/orders?signature=abc123', $e->diagnosticUrl());
        }
    }

    /**
     * diagnosticUrl()/diagnosticBody() are private-backed accessor
     * methods, not public properties — json_encode() (or any other
     * implicit reflection over an object's public state, the way a
     * generic structured-logging pipeline commonly serializes a caught
     * exception) must never expose the raw URL or response body unless
     * something explicitly calls those methods.
     */
    public function test_json_encoding_the_exception_never_exposes_the_raw_url_or_body(): void
    {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{"secret":"BODYSECRET"}', ['http_code' => 500]),
        ));

        try {
            $http->get('https://user:pass@example.test/orders?token=TOPSECRET')->throw();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            $encoded = json_encode($e, JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString('TOPSECRET', $encoded);
            self::assertStringNotContainsString('pass', $encoded);
            self::assertStringNotContainsString('BODYSECRET', $encoded);
            self::assertSame('{"status":500}', $encoded);
        }
    }

    /**
     * A real transport exception commonly names the URL it failed to
     * reach, userinfo and all — copying $previous->getMessage() verbatim
     * into this class's own message (the pre-fix behavior) would leak
     * exactly the secret getMessage() elsewhere in this file is proven to
     * redact. The full original message is still reachable, deliberately,
     * via getPrevious().
     */
    public function test_a_transport_failures_own_message_never_leaks_into_getmessage(): void
    {
        $http = new Http(new MockHttpClient(static fn (): MockResponse => new MockResponse('', [
            'error' => 'connect failed for https://user:pass@example.test/orders?token=TOPSECRET',
        ])));

        try {
            $http->get('https://example.test/orders')->body();
            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertStringNotContainsString('TOPSECRET', $e->getMessage());
            self::assertStringNotContainsString('pass', $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertStringContainsString('TOPSECRET', $e->getPrevious()->getMessage());
        }
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
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function scalarTopLevelJsonProvider(): iterable
    {
        yield 'string' => ['"a-secret-string-value"', 'string'];
        yield 'integer' => ['123456789', 'int'];
        yield 'float' => ['3.14159', 'float'];
        yield 'true' => ['true', 'bool'];
        yield 'false' => ['false', 'bool'];
    }

    /**
     * A JSON string, number, or boolean is all syntactically valid JSON
     * at the top level, so json_decode() never throws for any of these —
     * distinct from test_a_non_json_body_fails_with_a_clear_error() above,
     * which covers a body that fails to parse at all. Before this fix, a
     * bare `return $decoded;` for one of these let PHP's own TypeError
     * escape instead of this package's own documented exception.
     * getMessage() reports only the decoded value's own type category —
     * the same safe-by-default policy this class already applies to a
     * response body/transport-failure message — never the raw value
     * itself, which is only reachable via diagnosticBody().
     */
    #[DataProvider('scalarTopLevelJsonProvider')]
    public function test_a_scalar_top_level_json_body_throws_a_clear_error_instead_of_a_type_error(
        string $body,
        string $expectedType,
    ): void {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]),
        ));

        try {
            $http->get('https://example.test/value')->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(200, $e->status);
            self::assertStringNotContainsString($body, $e->getMessage());
            self::assertStringContainsString($expectedType, $e->getMessage());
            self::assertStringContainsString('not an object or array', $e->getMessage());
            self::assertSame($body, $e->diagnosticBody());
        }
    }

    /**
     * A top-level JSON null is covered separately from the scalar
     * provider above: its own decoded PHP type name ("null") and its raw
     * JSON text ("null") are the identical string, so there's no separate
     * "raw value" to prove absent from getMessage() the way there is for
     * a string/number/boolean — only that the type is reported and the
     * real body is still reachable via diagnosticBody().
     */
    public function test_a_top_level_json_null_throws_a_clear_error_instead_of_a_type_error(): void
    {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('null', ['http_code' => 200]),
        ));

        try {
            $http->get('https://example.test/value')->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(200, $e->status);
            self::assertStringContainsString('null', $e->getMessage());
            self::assertStringContainsString('not an object or array', $e->getMessage());
            self::assertSame('null', $e->diagnosticBody());
        }
    }

    /**
     * jsonPath() calls json() internally — proving it fails with the same
     * package exception (not a TypeError) for a scalar top-level body,
     * rather than assuming the fix in json() propagates correctly.
     */
    public function test_json_path_fails_with_the_same_exception_for_a_scalar_top_level_body(): void
    {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('"just a string"', ['http_code' => 200]),
        ));

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('not an object or array');
        $http->get('https://example.test/value')->jsonPath('anything');
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function emptyTopLevelJsonContainerProvider(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
    }

    /**
     * associative: true decodes both an empty JSON object and an empty
     * JSON array to the identical empty PHP array — there's no way to
     * tell them apart once decoded, and json()'s own array-oriented
     * contract doesn't need to: both are valid, successful top-level
     * containers, not the scalar/null case this fix rejects.
     */
    #[DataProvider('emptyTopLevelJsonContainerProvider')]
    public function test_an_empty_top_level_json_container_decodes_successfully(string $body): void
    {
        $http = new Http(new MockHttpClient(
            static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]),
        ));

        self::assertSame([], $http->get('https://example.test/value')->json());
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
