<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Produces a Symfony HttpClientInterface backed by amphp/http-client — the
 * current, genuinely Revolt-based generation, not the pre-Fiber one
 * Symfony's own AmpHttpClient targeted through Symfony 7.x. A request made
 * through the returned client suspends the calling Fiber rather than
 * blocking the process, composing with any other Revolt-native code
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
}
