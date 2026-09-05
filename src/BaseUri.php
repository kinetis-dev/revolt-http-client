<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use SensitiveParameter;

/**
 * A validated base URI, split into the two parts the joining rule needs.
 * Only {@see Preflight::baseUri()} constructs one, so an instance is
 * always an absolute canonical http(s) URI with no userinfo, query, or
 * fragment.
 *
 * @internal
 */
final readonly class BaseUri
{
    /**
     * @param string $origin scheme, host, and port when it is not the
     *     scheme's default — "https://api.example.com", "http://127.0.0.1:8099"
     * @param string $path the base path, always starting and ending with
     *     exactly one "/", so joining is a concatenation
     */
    public function __construct(
        #[SensitiveParameter] public string $origin,
        #[SensitiveParameter] public string $path,
    ) {}
}
