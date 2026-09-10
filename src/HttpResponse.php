<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use JsonException;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Revolt\EventLoop;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * What {@see Http}'s verb methods return. An error status is an answer
 * here, not an exception; {@see throw()} opts into the other behavior.
 * Reading is deferred until something asks for the body, status, or
 * headers, which is what lets requests started inside
 * `Kinetis\Async\concurrently()` overlap.
 *
 * This object owns the underlying transport response for as long as it
 * lives, and releases it on {@see discard()}, on a full read, and on
 * collection. The operation's deadline reaches every read: one refused
 * before it and one that answers after it both report the timeout.
 */
final class HttpResponse
{
    private ?string $body = null;

    private bool $discarded = false;

    private bool $released = false;

    public function __construct(
        #[SensitiveParameter] private readonly ResponseInterface $response,
        #[SensitiveParameter] private readonly ResponseBudget $budget,
    ) {}

    /**
     * The fallback for a response nobody read and nobody discarded.
     * Cancelling is local, so this neither blocks nor throws: a
     * destructor that raised would raise from wherever PHP chose to
     * collect, which is nowhere a caller can catch it.
     */
    public function __destruct()
    {
        $this->releaseOnce();
    }

    /**
     * @throws HttpRequestException when no complete response arrived; the
     *     server may still have received the request.
     */
    public function status(): int
    {
        return $this->read($this->response->getStatusCode(...));
    }

    /** Any 2xx. */
    public function successful(): bool
    {
        $status = $this->status();

        return $status >= 200 && $status < 300;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    /** Any 3xx — a redirect this client did not follow. */
    public function redirect(): bool
    {
        $status = $this->status();

        return $status >= 300 && $status < 400;
    }

    /** Any 4xx — the request was wrong. */
    public function clientError(): bool
    {
        $status = $this->status();

        return $status >= 400 && $status < 500;
    }

    /** Any 5xx — the server failed. */
    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * The raw body, read once and kept, bounded by the ceiling
     * {@see Http::withMaxResponseBytes()} sets at all three points a body
     * can pass it — a `Content-Length` past it, the transfer itself, and
     * the bytes that arrived — so no path ends with the whole of an
     * untrusted reply in memory.
     */
    public function body(): string
    {
        $this->guardNotDiscarded();

        if ($this->body !== null) {
            return $this->body;
        }

        $declared = $this->header('Content-Length');

        if ($declared !== null && ctype_digit($declared) && (int) $declared > $this->budget->maxBytes) {
            throw $this->tooLarge();
        }

        // getContent(false) suppresses the transport's own
        // throw-on-error-status, leaving that decision here.
        $body = $this->read(fn (): string => $this->response->getContent(false));

        if (strlen($body) > $this->budget->maxBytes) {
            throw $this->tooLarge();
        }

        // A body read to its end leaves the transport nothing to
        // release, so neither discard() nor the destructor cancels a
        // response that is already complete.
        $this->released = true;

        return $this->body = $body;
    }

    /**
     * The decoded JSON body as an array. A body that fails to parse, and
     * one that parses into a bare string, number, boolean or null, both
     * throw with the `Conversion` category. An integer too large for
     * PHP's own int type is decoded as a string rather than a float.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $body = $this->body();

        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw $this->conversionFailed('a body that is not valid JSON');
        }

        if (!is_array($decoded)) {
            throw $this->conversionFailed(
                'a JSON body that decoded to a ' . get_debug_type($decoded) . ', not an object or array',
            );
        }

        return $decoded;
    }

    /**
     * One value from the decoded body at a dot path — `data.items.0.id` —
     * or $default when nothing is there.
     */
    public function jsonPath(string $path, mixed $default = null): mixed
    {
        $value = $this->json();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            /** @var mixed $value */
            $value = $value[$segment];
        }

        return $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)][0] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->read(fn (): array => $this->response->getHeaders(false));
    }

    /**
     * Throws when the status is not 2xx and returns $this otherwise, so
     * it chains. The exception names the method, the origin, and the
     * status; the upstream's error payload stays on this object, where
     * reading it is a decision.
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw HttpRequestException::errorStatus($this->budget->method, $this->budget->origin, $this->status());
        }

        return $this;
    }

    /**
     * Releases the response body without reading it — the explicit end
     * of this object's ownership. It never throws and never blocks, and
     * calling it again does nothing; every read after it fails with the
     * `Discarded` category, an earlier full read included.
     */
    public function discard(): void
    {
        $this->discarded = true;

        $this->releaseOnce();
    }

    /**
     * Every read goes through here, so one place covers a read of a
     * response that was given back, one past the deadline, and one the
     * byte ceiling stopped. What the transport raises is replaced rather
     * than wrapped: its message routinely names the URI it failed on.
     * Which failure it becomes is read from the budget's state, never
     * from the exception's type, so a progress-guard failure the
     * transport wrapped is still the ceiling or the deadline it was.
     *
     * @template T
     * @param Closure(): T $read
     * @return T
     */
    private function read(#[SensitiveParameter] Closure $read): mixed
    {
        $this->guardNotDiscarded();

        if ($this->budget->expired()) {
            $this->releaseOnce();

            throw $this->budget->timedOut();
        }

        try {
            $value = self::await($read);
        } catch (Throwable) {
            $this->releaseOnce();

            throw match (true) {
                $this->budget->exceeded => $this->budget->tooLarge(),
                $this->budget->expired() => $this->budget->timedOut(),
                default => $this->budget->transportFailure(),
            };
        }

        // A read that answered after the budget ran out spent it just as
        // surely as one that never answered. A transport ignoring the
        // duration it was handed can only be caught here, on the way
        // back.
        if ($this->budget->expired()) {
            $this->releaseOnce();

            throw $this->budget->timedOut();
        }

        return $value;
    }

    /**
     * Runs one read on the event loop and waits for it, so the calling
     * Fiber suspends rather than the process blocking. From plain
     * top-level code Symfony's response stream polls once a second;
     * inside a fiber the loop keeps turning and the same read completes
     * in milliseconds.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private static function await(#[SensitiveParameter] Closure $work): mixed
    {
        $suspension = EventLoop::getSuspension();
        $result = null;
        $error = null;

        EventLoop::queue(static function () use ($work, $suspension, &$result, &$error): void {
            try {
                $result = $work();
            } catch (Throwable $e) {
                $error = $e;
            }

            $suspension->resume();
        });

        $suspension->suspend();

        if ($error !== null) {
            throw $error;
        }

        // The queued fiber ran to completion before the suspension
        // resumed, so exactly one of $error/$result is set by now.
        /** @var T $result */
        return $result;
    }

    /** $problem is fixed text; the body it describes is never quoted. */
    private function conversionFailed(string $problem): HttpRequestException
    {
        return HttpRequestException::conversionFailed(
            $this->budget->method,
            $this->budget->origin,
            $this->status(),
            $problem,
        );
    }

    /** A fresh failure for the ceiling, and no more reads of a body that passed it. */
    private function tooLarge(): HttpRequestException
    {
        $this->releaseOnce();

        return $this->budget->tooLarge();
    }

    private function releaseOnce(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        try {
            $this->response->cancel();
        } catch (Throwable) {
            // Nothing to report and nobody to report it to: the caller
            // has already given the response up.
        }
    }

    private function guardNotDiscarded(): void
    {
        if ($this->discarded) {
            throw HttpRequestException::discarded($this->budget->method, $this->budget->origin);
        }
    }
}
