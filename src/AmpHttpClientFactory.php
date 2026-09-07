<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\PooledHttpClient;
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
     * One request is one wire attempt: the Amp delegate is the connection
     * pool itself, with no interceptor above it. A repeat belongs to
     * whoever can count it against a deadline and a budget —
     * {@see Http::withRetries()} for this package's own client, or an
     * SDK's own retry policy for a transport handed to one — rather than
     * happening two levels below the API where nothing sees it.
     *
     * $clientConfigurator is the delegate, replacing that pool outright:
     * a caller wanting interceptors of its own — retries, redirect
     * following, anything else AMPHP composes — supplies one and owns
     * what it installs.
     *
     * @param array<string, mixed> $defaultOptions applied to every request
     *     made through the returned client
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
