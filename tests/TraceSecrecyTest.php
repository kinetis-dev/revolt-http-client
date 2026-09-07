<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A failure this package raises is safe to log: its message, its string
 * form, and its rendered trace carry the request method, the origin, a
 * status, and a category, and nothing else the caller passed in.
 *
 * Every value below is a sentinel, so one assertion covers the whole
 * family: a credential, a path, a query value, and a body value are the
 * same kind of secret to a log pipeline.
 */
final class TraceSecrecyTest extends TestCase
{
    private const string TOKEN = 'SENTINELTOKEN';
    private const string PATH = 'SENTINELPATH';
    private const string QUERY = 'SENTINELQUERY';
    private const string BODY = 'SENTINELBODY';

    /**
     * @return iterable<string, array{0: Closure(): mixed}>
     */
    public static function secretBearingFailureProvider(): iterable
    {
        yield 'a credential with no base URL' => [static fn () => new Http(new ScriptedTransport([['status' => 200]]))
            ->withToken(self::TOKEN)
            ->get('https://api.example.com/' . self::PATH)];

        yield 'a target off the base origin' => [static fn () => new Http(new ScriptedTransport([['status' => 200]]))
            ->withBaseUrl('https://api.example.com')
            ->withToken(self::TOKEN)
            ->get('https://evil.test/' . self::PATH . '?q=' . self::QUERY)];

        yield 'a transport that refuses to build the request' => [
            static fn () => new Http(new ScriptedTransport([['throw' => true]]))
                ->withBaseUrl('https://api.example.com')
                ->withToken(self::TOKEN)
                ->post('/' . self::PATH, ['secret' => self::BODY]),
        ];

        yield 'a read that fails on the wire' => [static function () {
            $transport = new ScriptedTransport([[
                'status' => 200,
                'readFailure' => 'read of https://SENTINELTOKEN@api.example.com/SENTINELPATH failed',
            ]]);

            return new Http($transport)
                ->withBaseUrl('https://api.example.com')
                ->withToken(self::TOKEN)
                ->get('/' . self::PATH . '?q=' . self::QUERY)
                ->body();
        }];

        yield 'an error status raised on' => [static fn () => new Http(new ScriptedTransport([['status' => 500]]))
            ->withBaseUrl('https://api.example.com')
            ->withToken(self::TOKEN)
            ->get('/' . self::PATH . '?q=' . self::QUERY)
            ->throw()];

        yield 'a response past the byte ceiling' => [static fn () => new Http(new ScriptedTransport([[
            'status' => 200,
            'chunks' => [self::BODY . self::BODY],
        ]]))
            ->withBaseUrl('https://api.example.com')
            ->withToken(self::TOKEN)
            ->withMaxResponseBytes(4)
            ->get('/' . self::PATH)
            ->body()];

        yield 'a body that is not JSON' => [static fn () => new Http(new ScriptedTransport([[
            'status' => 200,
            'chunks' => [self::BODY],
        ]]))
            ->withBaseUrl('https://api.example.com')
            ->withToken(self::TOKEN)
            ->get('/' . self::PATH)
            ->json()];
    }

    /**
     * @param Closure(): mixed $call
     */
    #[DataProvider('secretBearingFailureProvider')]
    public function test_no_rendering_of_a_failure_carries_a_secret(Closure $call): void
    {
        try {
            $call();
            self::fail('The call was expected to raise.');
        } catch (HttpRequestException $e) {
            foreach (['message' => $e->getMessage(), 'string' => (string) $e, 'trace' => $e->getTraceAsString()] as $rendering) {
                self::assertStringNotContainsString('SENTINEL', $rendering);
            }

            // A vendor cause would carry the URI its own message names.
            self::assertNull($e->getPrevious());
        }
    }

    /**
     * `#[\SensitiveParameter]` is what keeps a value out of a rendered
     * trace, so the marker has to be there rather than the argument.
     */
    public function test_the_trace_holds_redaction_markers_rather_than_nothing_at_all(): void
    {
        try {
            new Http(new ScriptedTransport([['status' => 200]]))->withToken(self::TOKEN)->get('https://api.example.com/x');
            self::fail('A credential with no base URL was expected to be refused.');
        } catch (HttpRequestException $e) {
            self::assertStringContainsString('Object(SensitiveParameterValue)', $e->getTraceAsString());
        }
    }
}
