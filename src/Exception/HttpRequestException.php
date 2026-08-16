<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

use RuntimeException;
use Throwable;

final class HttpRequestException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /**
     * The body is included, truncated: an API's own error payload is
     * usually the only thing that explains the status, and a caller
     * reading a log should not have to reproduce the request to see it.
     */
    public static function errorStatus(string $method, string $url, int $status, string $body): self
    {
        // A byte cap, not a character cap — mb_substr() would need
        // ext-mbstring, which nothing this package requires guarantees.
        $excerpt = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;

        return new self(
            "{$method} {$url} returned HTTP {$status}." . ($excerpt === '' ? '' : " Response: {$excerpt}"),
            $status,
        );
    }

    /**
     * No response at all — DNS failure, a refused connection, a timeout.
     * `$status` is 0, since there is no HTTP answer to report.
     */
    public static function transportFailure(string $method, string $url, Throwable $previous): self
    {
        return new self(
            "{$method} {$url} failed before any response arrived: {$previous->getMessage()}",
            0,
            $previous,
        );
    }

    public static function notJson(string $method, string $url, int $status, Throwable $previous): self
    {
        return new self(
            "{$method} {$url} returned HTTP {$status} with a body that is not valid JSON: {$previous->getMessage()}",
            $status,
            $previous,
        );
    }
}
