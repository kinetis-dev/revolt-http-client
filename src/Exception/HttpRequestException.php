<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

use RuntimeException;

/**
 * The only exception this package throws, across every path: input
 * validation, body encoding, transport construction, the transport
 * itself, the total timeout, a response past its byte ceiling, an error
 * status a caller asked to raise on, and response decoding.
 *
 * Every instance is built here, from fixed text plus values this package
 * has already validated — the request method, the origin (scheme, host,
 * and non-default port) of the request, an HTTP status, and a
 * {@see HttpFailure} category. Nothing else reaches it. A vendor
 * exception is never chained and its message is never copied, because a
 * lower-level HTTP or DNS client routinely names the full URI it failed
 * on — userinfo, path, and query string included — and an exception
 * message is the one thing a logging pipeline records by default. The
 * category is what a caller branches on; the message is prose.
 *
 * Nothing this class stores can name a path, a query value, a header, a
 * credential, or a body, so an instance is safe to log whole:
 * `getMessage()`, `(string) $e`, `getTraceAsString()`, and
 * `serialize($e)` all stay within that guarantee. Trace arguments are
 * covered separately, by `#[\SensitiveParameter]` on every parameter
 * that forwards caller input.
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
     * A client configured with something this package will not send.
     * $problem is fixed text written at the call site, never caller
     * input — the same rule every constructor here follows.
     */
    public static function invalidConfiguration(string $problem): self
    {
        return new self("This HTTP client cannot be configured that way: {$problem}", HttpFailure::InvalidConfiguration, 0);
    }

    /** A per-call URL, header, query, body, option, or shape this package will not send. */
    public static function invalidRequest(string $problem): self
    {
        return new self("This HTTP request cannot be sent: {$problem}", HttpFailure::InvalidRequest, 0);
    }

    /** Encoding a request body failed — an unencodable value reached the encoder. */
    public static function bodyEncodingFailed(string $encoding): self
    {
        return new self("The request body could not be encoded as {$encoding}.", HttpFailure::Conversion, 0);
    }

    /**
     * No response arrived, or the transport refused the request as
     * constructed. `$status` is 0: there is no HTTP answer to report.
     */
    public static function transportFailure(string $method, string $origin): self
    {
        return new self("{$method} {$origin} failed before any response arrived.", HttpFailure::Transport, 0);
    }

    /** The whole operation — every attempt and every backoff between them — ran past its budget. */
    public static function timedOut(string $method, string $origin, float $budget, int $attempts): self
    {
        return new self(
            sprintf('%s %s ran out of its %.3Fs total timeout after %d attempt(s).', $method, $origin, $budget, $attempts),
            HttpFailure::Timeout,
            0,
        );
    }

    /**
     * The response body passed the ceiling
     * {@see \Kinetis\RevoltHttpClient\Http::withMaxResponseBytes()} sets.
     * The status is 0 whichever way the ceiling was reached: a transfer
     * aborted part-way has no complete answer to report, and a declared
     * length refused before the body was fetched has no body behind it,
     * so one fixed status keeps the two indistinguishable from outside.
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

    /** The body does not parse as JSON at all. Its text is never quoted here. */
    public static function malformedJson(string $method, string $origin, int $status): self
    {
        return new self(
            "{$method} {$origin} returned HTTP {$status} with a body that is not valid JSON.",
            HttpFailure::Conversion,
            $status,
        );
    }

    /**
     * The body is valid JSON whose top-level value is a bare string,
     * number, boolean, or null — syntactically fine, and outside the
     * array-shaped contract of `json()`/`jsonPath()`. $type is
     * `get_debug_type()`'s output for the decoded value, never the value.
     */
    public static function unexpectedJsonType(string $method, string $origin, int $status, string $type): self
    {
        return new self(
            "{$method} {$origin} returned HTTP {$status} with a JSON body that decoded to a {$type}, not an object or array.",
            HttpFailure::Conversion,
            $status,
        );
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

    /**
     * Serialization carries the safe scalars and nothing else. The
     * default would carry the stack trace, whose arguments hold whatever
     * the caller passed in; PHP's own `#[\SensitiveParameter]` marker
     * keeps those out of rendered traces but is still an object holding
     * the value, so the trace is dropped here rather than serialized.
     *
     * @return array{message: string, code: int, file: string, line: int, category: HttpFailure, status: int}
     */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'category' => $this->category,
            'status' => $this->status,
        ];
    }

    /**
     * @param array{message?: string, code?: int, file?: string, line?: int, category?: HttpFailure, status?: int} $data
     */
    public function __unserialize(array $data): void
    {
        $this->message = $data['message'] ?? '';
        $this->code = $data['code'] ?? 0;
        $this->file = $data['file'] ?? __FILE__;
        $this->line = $data['line'] ?? 0;
        $this->category = $data['category'] ?? HttpFailure::Transport;
        $this->status = $data['status'] ?? 0;
    }
}
