<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use SplObjectStorage;

/**
 * What a failure from this package is allowed to carry, checked the hard
 * way: every path plants sentinels in the base URL, the credential, the
 * headers, the query, the path, and the body, and then every rendering
 * of the resulting exception — message, string cast, trace, trace
 * string, chained exceptions, print_r, var_export, serialize,
 * json_encode, and an ordinary log line — is searched for them.
 *
 * The whole suite runs with `zend.exception_ignore_args=0`, the setting
 * that puts call arguments into traces. With it off, every one of these
 * assertions would pass for the wrong reason.
 */
final class TraceSecrecyTest extends TestCase
{
    private const string SENTINEL = 'SENTINEL';

    private string|false $restoreIgnoreArgs = false;

    protected function setUp(): void
    {
        $this->restoreIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->restoreIgnoreArgs === false ? '1' : $this->restoreIgnoreArgs);
    }

    /**
     * A client with a sentinel planted in every place caller input can
     * reach: the credential, a header, the query, and later the path and
     * the body of the call itself.
     *
     * @param list<array<string, mixed>> $script
     */
    private static function loadedClient(array $script): Http
    {
        return new Http(new ScriptedTransport($script))
            ->withBaseUrl('https://api.example.com')
            ->withToken('SENTINELTOKEN')
            ->withHeaders(['X-Api-Key' => 'SENTINELHEADER'])
            ->withQuery(['signature' => 'SENTINELQUERY']);
    }

    /**
     * @return iterable<string, array{failure: Closure(): mixed, category: HttpFailure}>
     */
    public static function secretBearingFailureProvider(): iterable
    {
        yield 'a base URL carrying userinfo' => [
            'failure' => static fn () => new Http(new ScriptedTransport([[]]))
                ->withBaseUrl('https://SENTINELUSER:SENTINELPASS@api.example.com/v1'),
            'category' => HttpFailure::InvalidConfiguration,
        ];

        yield 'a base URL whose backslash hides the real host' => [
            'failure' => static fn () => new Http(new ScriptedTransport([[]]))
                ->withBaseUrl('https://api.example.com\\@SENTINELHOST.example.net'),
            'category' => HttpFailure::InvalidConfiguration,
        ];

        yield 'a request URL carrying userinfo' => [
            'failure' => static fn () => new Http(new ScriptedTransport([[]]))
                ->get('https://SENTINELUSER:SENTINELPASS@api.example.com/orders'),
            'category' => HttpFailure::InvalidRequest,
        ];

        yield 'an option this client cannot check' => [
            'failure' => static fn () => self::loadedClient([[]])->send('POST', '/orders/SENTINELPATH', [
                'json' => ['card' => 'SENTINELBODY'],
                'proxy' => 'SENTINELOPTION',
            ]),
            'category' => HttpFailure::InvalidRequest,
        ];

        yield 'a transport that refuses to build the request' => [
            'failure' => static fn () => self::loadedClient([['throw' => true]])
                ->post('/orders/SENTINELPATH', ['card' => 'SENTINELBODY']),
            'category' => HttpFailure::Transport,
        ];

        yield 'a transport failure that outlives every retry' => [
            'failure' => static fn () => self::loadedClient([['throw' => true]])
                ->withRetries(1)
                ->post('/orders/SENTINELPATH', ['card' => 'SENTINELBODY']),
            'category' => HttpFailure::Transport,
        ];

        yield 'a retrying operation that runs out of its deadline' => [
            'failure' => static fn () => self::loadedClient([['status' => 503]])
                ->withRetries(5)
                ->withTimeout(0.25)
                ->post('/orders/SENTINELPATH', ['card' => 'SENTINELBODY']),
            'category' => HttpFailure::Timeout,
        ];

        yield 'a body that never arrives' => [
            'failure' => static fn () => self::loadedClient([[
                'readFailure' => 'reading https://SENTINELUSER:SENTINELPASS@api.example.com/SENTINELPATH failed',
            ]])->get('/orders/SENTINELPATH')->body(),
            'category' => HttpFailure::Transport,
        ];

        yield 'a body past the ceiling' => [
            'failure' => static fn () => self::loadedClient([['chunks' => ['SENTINELBODYSENTINELBODY']]])
                ->withMaxResponseBytes(4)
                ->get('/orders/SENTINELPATH')
                ->body(),
            'category' => HttpFailure::ResponseTooLarge,
        ];

        yield 'an error status a caller raised on' => [
            'failure' => static fn () => self::loadedClient([['status' => 500, 'chunks' => ['{"d":"SENTINELRESPONSE"}']]])
                ->post('/orders/SENTINELPATH', ['card' => 'SENTINELBODY'])
                ->throw(),
            'category' => HttpFailure::ErrorStatus,
        ];

        yield 'a read of a response that was given back' => [
            'failure' => static function (): mixed {
                $response = self::loadedClient([[]])->get('/orders/SENTINELPATH');
                $response->discard();

                return $response->body();
            },
            'category' => HttpFailure::Discarded,
        ];
    }

    /**
     * @param Closure(): mixed $failure
     */
    #[DataProvider('secretBearingFailureProvider')]
    public function test_no_rendering_of_a_failure_carries_a_secret(Closure $failure, HttpFailure $category): void
    {
        try {
            $failure();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame($category, $e->category);
            self::assertNull($e->getPrevious());
            self::assertNoSecretAnywhereIn($e);
        }
    }

    /**
     * The redaction has to be doing the work, not an empty trace. At
     * least one argument on the way down is a `SensitiveParameterValue`,
     * which is where PHP itself stopped holding a value this package
     * marked.
     */
    public function test_the_trace_holds_redaction_markers_rather_than_nothing_at_all(): void
    {
        try {
            self::loadedClient([[]])->send('POST', '/orders/SENTINELPATH', [
                'json' => ['card' => 'SENTINELBODY'],
                'proxy' => 'SENTINELOPTION',
            ]);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertGreaterThan(0, self::walk($e->getTrace(), new SplObjectStorage()));
        }
    }

    /**
     * Serialization carries the safe scalars and nothing else: the
     * original trace, and every argument in it, is dropped rather than
     * travelling with an exception queued into a job payload or a log
     * transport.
     */
    public function test_an_unserialized_failure_keeps_the_category_and_has_no_trace_to_leak(): void
    {
        try {
            self::loadedClient([['status' => 503]])->post('/orders/SENTINELPATH', ['card' => 'SENTINELBODY'])->throw();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            $restored = unserialize(serialize($e));

            self::assertInstanceOf(HttpRequestException::class, $restored);
            self::assertSame(HttpFailure::ErrorStatus, $restored->category);
            self::assertSame(503, $restored->status);
            self::assertSame($e->getMessage(), $restored->getMessage());
            self::assertNoSecretAnywhereIn($restored);
        }
    }

    /**
     * A structured-logging pipeline that reflects over an exception's
     * public state finds the category and the status, and nothing that
     * needed a decision to log.
     */
    public function test_json_encoding_a_failure_exposes_only_the_safe_summary(): void
    {
        try {
            self::loadedClient([['status' => 500, 'chunks' => ['{"d":"SENTINELRESPONSE"}']]])
                ->get('/orders/SENTINELPATH')
                ->throw();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame('{"category":"error-status","status":500}', json_encode($e, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Every way an exception turns into text, plus everything its trace
     * and its chain hold, searched for a planted secret.
     */
    private static function assertNoSecretAnywhereIn(HttpRequestException $e): void
    {
        $renderings = [
            $e->getMessage(),
            (string) $e,
            $e->getTraceAsString(),
            print_r($e, true),
            var_export($e, true),
            serialize($e),
            (string) json_encode($e),
            sprintf('[%s] %s (status %d)', $e->category->value, $e->getMessage(), $e->status),
        ];

        foreach ($renderings as $rendered) {
            self::assertStringNotContainsString(self::SENTINEL, $rendered);
        }

        self::walk($e->getTrace(), new SplObjectStorage());
    }

    /**
     * Walks everything a trace holds, asserting no planted secret is
     * reachable. A `SensitiveParameterValue` is where PHP itself stopped
     * holding the argument, so it is counted and not descended into; the
     * count is returned so a caller can assert the redaction happened
     * rather than that the trace was empty.
     *
     * @param SplObjectStorage<object, null> $seen
     */
    private static function walk(mixed $value, SplObjectStorage $seen): int
    {
        if (is_string($value)) {
            self::assertStringNotContainsString(self::SENTINEL, $value);

            return 0;
        }

        if ($value instanceof SensitiveParameterValue) {
            return 1;
        }

        if (is_object($value)) {
            if ($seen->contains($value)) {
                return 0;
            }

            $seen->attach($value);
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return 0;
        }

        $redacted = 0;

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                self::assertStringNotContainsString(self::SENTINEL, $key);
            }

            $redacted += self::walk($nested, $seen);
        }

        return $redacted;
    }
}
