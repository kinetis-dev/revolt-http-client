<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use SensitiveParameter;

/**
 * One request of an operation: the first, or one a redirect leads to.
 * Its headers are the validated map before the client adds what it owns.
 *
 * @internal
 */
final readonly class Hop
{
    /**
     * @param array<string, array{name: string, values: non-empty-list<string>}> $headers
     * @param array<string, mixed> $query sent as the transport's query option
     * @param array<string, mixed> $body a `json` or `body` transport option, or nothing
     */
    public function __construct(
        public string $method,
        #[SensitiveParameter] public string $target,
        public string $origin,
        #[SensitiveParameter] public array $headers,
        #[SensitiveParameter] public array $query,
        #[SensitiveParameter] public array $body,
    ) {}
}
