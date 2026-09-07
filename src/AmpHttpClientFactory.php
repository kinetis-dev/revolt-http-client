<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\PooledHttpClient;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Produces a Symfony HttpClientInterface backed by amphp/http-client, the
 * current Revolt-based generation. A request made through the returned
 * client suspends the calling Fiber rather than blocking the process, so
 * it composes with any other Revolt-native code on the same loop.
 *
 * Nothing here is Kinetis-specific: the returned client is a plain
 * Symfony\Contracts\HttpClient\HttpClientInterface usable by any library
 * that accepts one.
 */
final class AmpHttpClientFactory
{
    /**
     * One request is one wire attempt: the Amp delegate is the connection
     * pool itself, with no interceptor above it. A repeat belongs to
     * whoever can count it against a deadline and a budget —
     * {@see Http::withRetries()}, or an SDK's own retry policy — rather
     * than happening two levels below the API where nothing sees it. A
     * transport injected into {@see Http} instead of this one is the
     * caller's to hold to that same one attempt per request.
     *
     * $clientConfigurator replaces that pool outright, and a caller
     * supplying one owns what it installs.
     *
     * @param array<string, mixed> $defaultOptions applied to every request
     * @param ?callable(PooledHttpClient): DelegateHttpClient $clientConfigurator
     */
    public static function create(
        array $defaultOptions = [],
        ?callable $clientConfigurator = null,
        int $maxHostConnections = 6,
        int $maxPendingPushes = 50,
    ): HttpClientInterface {
        return new AmpHttpClient(
            $defaultOptions,
            $clientConfigurator ?? static fn (PooledHttpClient $pooled): PooledHttpClient => $pooled,
            $maxHostConnections,
            $maxPendingPushes,
        );
    }
}
