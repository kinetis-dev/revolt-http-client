<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use SensitiveParameter;

/**
 * Where one request is going, after {@see Preflight::target()} resolved
 * it against the client's base URI and validated every part of it.
 *
 * @internal
 */
final readonly class RequestTarget
{
    /**
     * @param string $url the absolute URL to send, query string included
     * @param string $origin the same URL's scheme, host, and non-default
     *     port — the only part of a request that ever reaches an
     *     exception message
     * @param bool $hasQueryString whether $url already carries a query
     *     string of its own, which is what makes a separate query array
     *     ambiguous rather than additive
     */
    public function __construct(
        #[SensitiveParameter] public string $url,
        #[SensitiveParameter] public string $origin,
        public bool $hasQueryString,
    ) {}
}
