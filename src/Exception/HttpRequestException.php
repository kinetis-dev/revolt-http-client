<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

use RuntimeException;

/**
 * The only exception this package throws, across every path: input
 * validation, request construction, the transport, the total timeout, a
 * response past its byte ceiling, an error status, and decoding.
 *
 * Every instance is built here, from fixed text plus values already
 * validated: the request method, the origin (scheme, host, non-default
 * port), an HTTP status, and a {@see HttpFailure} category. A vendor
 * exception is never chained and its message never copied — a
 * lower-level HTTP or DNS client routinely names the full URI it failed
 * on, and an exception message is what a logging pipeline records by
 * default. Rendered trace arguments are covered by
 * `#[\SensitiveParameter]` on every parameter forwarding caller input.
 */
final class HttpRequestException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly HttpFailure $category,
        public readonly int $status,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * A client, a call, or a body this package will not send. $problem
     * is fixed text written at the call site, never caller input — the
     * rule every constructor here follows.
     */
    public static function invalidRequest(string $problem): self
    {
        return new self($problem, HttpFailure::InvalidRequest, 0);
    }

    /** No response arrived, or the response stopped arriving. */
    public static function transportFailure(string $method, string $origin): self
    {
        return new self("{$method} {$origin} failed before any response arrived.", HttpFailure::Transport, 0);
    }

    /** The whole operation — every attempt and every backoff — ran past its budget. */
    public static function timedOut(string $method, string $origin, float $budget, int $attempts): self
    {
        return new self(
            sprintf('%s %s ran out of its %.3Fs total timeout after %d attempt(s).', $method, $origin, $budget, $attempts),
            HttpFailure::Timeout,
            0,
        );
    }

    /**
     * The status is 0 whichever way the ceiling was reached: a transfer
     * aborted part-way has no complete answer to report, and a declared
     * length refused before the body was fetched has no body behind it.
     */
    public static function responseTooLarge(string $method, string $origin, int $maxBytes): self
    {
        return new self(
            "{$method} {$origin} returned a response past the {$maxBytes}-byte ceiling this client allows.",
            HttpFailure::ResponseTooLarge,
            0,
        );
    }

    /** A response arrived and {@see \Kinetis\RevoltHttpClient\HttpResponse::throw()} was asked to raise on it. */
    public static function errorStatus(string $method, string $origin, int $status): self
    {
        return new self("{$method} {$origin} returned HTTP {$status}.", HttpFailure::ErrorStatus, $status);
    }

    /** $problem describes the shape of the body, never its text. */
    public static function conversionFailed(string $method, string $origin, int $status, string $problem): self
    {
        return new self("{$method} {$origin} returned HTTP {$status} with {$problem}.", HttpFailure::Conversion, $status);
    }

    /** A read was attempted on a response whose body had already been released. */
    public static function discarded(string $method, string $origin): self
    {
        return new self(
            "{$method} {$origin} was discarded; its body was released and cannot be read.",
            HttpFailure::Discarded,
            0,
        );
    }
}
