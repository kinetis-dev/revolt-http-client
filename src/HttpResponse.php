<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use JsonException;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * What {@see Http}'s verb methods return: the response, with the reading
 * and status handling a caller would otherwise write every time.
 *
 * An error status is not an exception here. `$response->failed()` and
 * `$response->status()` are answers, and {@see throw()} is how a caller
 * opts into the other behavior — a 404 from an API you are probing is
 * information, not a crash, and which one it is belongs to the caller
 * rather than the client. A 3xx is an answer too: redirects are never
 * followed, so `status()` reports the redirect and `header('Location')`
 * is there to read.
 *
 * Reading is deferred until something asks for the body,
 * status, or headers, which is what lets several requests started inside
 * `Kinetis\Async\concurrently()` overlap rather than complete one at a
 * time.
 *
 * **The deadline reaches here.** The budget {@see Http::withTimeout()}
 * sets covers this object too: a read is refused once the operation's
 * monotonic deadline has passed, with the `Timeout` category, and a read
 * that fails after it has passed reports the timeout rather than the
 * transport. A transport that ignores the per-request duration it was
 * given and blocks inside a single read cannot be interrupted from here;
 * the deadline is checked at every boundary this package controls.
 *
 * **Ownership.** This object owns the underlying transport response for
 * as long as it lives. {@see discard()} is how a caller gives it back
 * early and deterministically: it releases the body, never throws, never
 * blocks on the network, and can be called any number of times,
 * including after a full read. A read after it fails with the
 * `Discarded` category rather than returning something undefined. A
 * response nobody discards releases the same way when PHP collects the
 * object — the fallback that keeps an abandoned response from holding a
 * connection, not the API to reach for, since when a collection happens
 * is PHP's decision and not the caller's.
 *
 * A transport failure — DNS, a refused connection, a timeout — has no
 * status to return, so it throws {@see HttpRequestException} (with
 * status 0) from whichever read method first needs the response. One
 * exception type covers everything this client throws.
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
     * Releasing here rather than nowhere is what keeps an ignored
     * response from holding its connection for as long as the object
     * happens to live; {@see discard()} remains the way to release one
     * at a chosen moment. Cancelling is local, so this neither blocks
     * nor throws — a destructor that raised would raise from wherever
     * PHP chose to collect, which is nowhere a caller can catch it.
     */
    public function __destruct()
    {
        if (!$this->released) {
            self::release($this->response);
        }
    }

    /**
     * @throws HttpRequestException when no response arrived at all — see
     *     the class note on transport failures.
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
     * The raw body, bounded by the ceiling
     * {@see Http::withMaxResponseBytes()} sets. Read once and kept, so
     * calling this and {@see json()} together doesn't fetch twice.
     *
     * The ceiling is checked at all three points a body can pass it, so
     * that no path ends with the whole of an untrusted reply in memory:
     *
     * - a `Content-Length` larger than the ceiling fails before any body
     *   is fetched;
     * - a transfer that passes the ceiling as it arrives is aborted
     *   there, which is what covers a response that declares no length
     *   or declares one it exceeds;
     * - what did arrive is measured before it is handed back, so a
     *   transport that ignored the progress hook is caught by the one
     *   check that needs nothing from it.
     *
     * Exactly the ceiling is a body like any other; one byte past it
     * throws with the `ResponseTooLarge` category, and the response is
     * released rather than left holding a connection nothing will read.
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
     * The decoded JSON body as an array — a JSON object or array, per
     * this method's own return type. A body that fails to parse throws
     * with the `Conversion` category; so does one that parses into a
     * bare JSON string, number, boolean, or null, all valid JSON and
     * none of them something this method's array-shaped contract can
     * return.
     *
     * An integer too large for PHP's own int type is decoded as a string
     * rather than silently becoming a float: an API that keys resources
     * by 64-bit ids, or by ids beyond JavaScript's safe integer range,
     * gets its digits back exactly as they were sent.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $body = $this->body();

        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw HttpRequestException::malformedJson($this->budget->method, $this->budget->origin, $this->status());
        }

        if (!is_array($decoded)) {
            throw HttpRequestException::unexpectedJsonType(
                $this->budget->method,
                $this->budget->origin,
                $this->status(),
                get_debug_type($decoded),
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
     * Throws when the status is not 2xx, and returns $this otherwise, so
     * it chains onto a call whose failure should stop the caller:
     *
     *     $order = $http->get('/orders/1')->throw()->json();
     *
     * The exception names the method, the origin, and the status. The
     * response body is not in it and never will be — an upstream error
     * payload carries whatever the upstream chose to put there. Read it
     * from this object, where taking it is a decision:
     *
     *     if ($response->failed()) {
     *         $log->warning('upstream said', ['body' => $response->body()]);
     *     }
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
     * of this object's ownership, for a response whose status was all
     * the caller wanted.
     *
     * It never throws and never blocks: cancelling is a local operation,
     * and a transport that raises while being cancelled has nothing left
     * to tell a caller who already said they were done. Calling it again
     * does nothing; every read after it fails with the `Discarded`
     * category, an earlier full read included.
     */
    public function discard(): void
    {
        $this->discarded = true;

        $this->releaseOnce();
    }

    /**
     * Cancels a response nothing will read again. Shared with
     * {@see Http}'s retry loop, which abandons a response every time it
     * decides to send the request again; an abandoned response that kept
     * its connection would leak one per retry.
     *
     * @internal
     */
    public static function release(ResponseInterface $response): void
    {
        try {
            $response->cancel();
        } catch (Throwable) {
            // Nothing to report and nobody to report it to: the caller
            // has already given the response up.
        }
    }

    /**
     * Every read goes through here, so a vendor exception becomes this
     * package's own typed failure in one place rather than in each
     * method — and so does a read of a response that was given back, one
     * past the operation's deadline, and one the byte ceiling stopped.
     * Anything the transport raises is replaced rather than wrapped: a
     * transport exception routinely names the URI it failed on,
     * userinfo and all.
     *
     * @template T
     * @param Closure(): T $read
     * @return T
     */
    private function read(#[SensitiveParameter] Closure $read): mixed
    {
        $this->guardNotDiscarded();

        if ($this->budget->deadline->expired()) {
            $this->releaseOnce();

            throw $this->budget->timedOut();
        }

        try {
            $value = Loop::await($read);
        } catch (Throwable) {
            $this->releaseOnce();

            throw match (true) {
                // The ceiling is asked first: passing it is what made the
                // transport raise, and the transport's own account of that
                // is the one thing this package will not repeat.
                $this->budget->exceeded => $this->tooLarge(),
                $this->budget->deadline->expired() => $this->budget->timedOut(),
                default => $this->budget->transportFailure(),
            };
        }

        // A read that answered after the budget ran out spent it just as
        // surely as one that never answered. A transport that ignores
        // the duration it is handed can only be caught here, on the way
        // back, so the answer is refused rather than returned from an
        // operation that is already over.
        if ($this->budget->deadline->expired()) {
            $this->releaseOnce();

            throw $this->budget->timedOut();
        }

        return $value;
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

        self::release($this->response);
    }

    private function guardNotDiscarded(): void
    {
        if ($this->discarded) {
            throw HttpRequestException::discarded($this->budget->method, $this->budget->origin);
        }
    }
}
