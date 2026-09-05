<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Amp\Http\Client\PooledHttpClient;
use Closure;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Produces a Symfony HttpClientInterface backed by amphp/http-client — the
 * current, Revolt-based generation. A request made through the returned
 * client suspends the calling Fiber rather than blocking the process, composing with any other Revolt-native code
 * (Kinetis\Persistence's MySQL/Postgres/Redis clients, Kinetis\Storage's
 * AmpFileAdapter, or anything else on the same loop) rather than
 * defeating the point of running alongside it.
 *
 * Nothing about this class is Kinetis-specific: it depends on nothing
 * beyond symfony/http-client and amphp/http-client, and returns a plain
 * Symfony\Contracts\HttpClient\HttpClientInterface — usable by any library
 * that accepts one (AsyncAws's AbstractApi::__construct($httpClient), or
 * anything else), with or without Kinetis in the picture at all.
 */
final class AmpHttpClientFactory
{
    /**
     * @param array<string, mixed> $defaultOptions applied to every request
     *     made through the returned client
     */
    public static function create(
        array $defaultOptions = [],
        ?callable $clientConfigurator = null,
        int $maxHostConnections = 6,
        int $maxPendingPushes = 50,
    ): HttpClientInterface {
        return new AmpHttpClient($defaultOptions, $clientConfigurator, $maxHostConnections, $maxPendingPushes);
    }

    /**
     * The same transport with one wire attempt per request, which is
     * what {@see Http} is built on.
     *
     * Left to itself, `AmpHttpClient` wraps its connection pool in an
     * Amp interceptor that repeats a failed request twice more. That is
     * a retry layer below the one {@see Http::withRetries()} owns: it
     * multiplies the attempts, it repeats a request a client configured
     * for none never asked to repeat, and it spends the total deadline
     * where nothing counts it. Handing the pooled client back unchanged
     * is what leaves the retry decision in one place.
     *
     * @param array<string, mixed> $defaultOptions applied to every request
     *     made through the returned client
     */
    public static function createWithoutRetries(
        array $defaultOptions = [],
        int $maxHostConnections = 6,
        int $maxPendingPushes = 50,
    ): HttpClientInterface {
        return new AmpHttpClient(
            $defaultOptions,
            self::noRetryConfigurator(),
            $maxHostConnections,
            $maxPendingPushes,
        );
    }

    /**
     * The configurator that adds nothing to the pool. Named here rather
     * than written inline so a test can assert on the one thing that
     * matters about it: what it hands back.
     *
     * @return Closure(PooledHttpClient): PooledHttpClient
     */
    public static function noRetryConfigurator(): Closure
    {
        return static fn (PooledHttpClient $pooled): PooledHttpClient => $pooled;
    }
}
