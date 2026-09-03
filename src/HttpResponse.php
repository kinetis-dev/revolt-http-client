<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use JsonException;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Revolt\EventLoop;
use Throwable;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What {@see Http}'s verb methods return: the response, with the reading
 * and status handling a caller would otherwise write every time.
 *
 * An error status is not an exception here. `$response->failed()` and
 * `$response->status()` are answers, and {@see throw()} is how a caller
 * opts into the other behavior — a 404 from an API you are probing is
 * information, not a crash, and which one it is belongs to the caller
 * rather than the client.
 *
 * Reading is deferred until something actually asks for the body or
 * status, which is what lets several requests started inside
 * `Kinetis\Async\concurrently()` overlap rather than complete one at a
 * time.
 *
 * A transport failure — DNS, a refused connection, a timeout — has no
 * status to return, so it throws {@see HttpRequestException} (with
 * status 0) from whichever read method first needs the response. One
 * exception type covers everything this client throws.
 */
final class HttpResponse
{
    private ?string $body = null;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly string $method,
        private readonly string $url,
    ) {}

    /**
     * @throws HttpRequestException when no response arrived at all — see
     *     the class note on transport failures.
     */
    public function status(): int
    {
        try {
            return self::await($this->response->getStatusCode(...));
        } catch (TransportExceptionInterface $e) {
            throw HttpRequestException::transportFailure($this->method, $this->effectiveUrl(), $e);
        }
    }

    /** Any 2xx. */
    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    /** Any 4xx — the request was wrong. */
    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /** Any 5xx — the server failed. */
    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * The raw body. Read once and kept, so calling this and {@see json()}
     * together doesn't fetch twice.
     */
    public function body(): string
    {
        try {
            // getContent(false) suppresses Symfony's own
            // throw-on-error-status, leaving that decision here.
            return $this->body ??= self::await(fn (): string => $this->response->getContent(false));
        } catch (TransportExceptionInterface $e) {
            throw HttpRequestException::transportFailure($this->method, $this->effectiveUrl(), $e);
        }
    }

    /**
     * The decoded JSON body as an array — a JSON object or array, per
     * this method's own return type. A body that fails to parse as JSON
     * at all throws via notJson(); a body that parses successfully but
     * whose top-level value is a bare JSON string, number, boolean, or
     * null (all syntactically valid JSON, just not something this
     * method's array-oriented contract can return) throws via
     * unexpectedJsonType() instead — never the native TypeError a bare
     * `return $decoded;` would otherwise raise for a non-array value.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $body = $this->body();

        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw HttpRequestException::notJson($this->method, $this->effectiveUrl(), $this->status(), $e);
        }

        if (!is_array($decoded)) {
            throw HttpRequestException::unexpectedJsonType(
                $this->method,
                $this->effectiveUrl(),
                $this->status(),
                $body,
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
        try {
            return self::await(fn (): array => $this->response->getHeaders(false));
        } catch (TransportExceptionInterface $e) {
            throw HttpRequestException::transportFailure($this->method, $this->effectiveUrl(), $e);
        }
    }

    /**
     * Throws when the status is not 2xx, and returns $this otherwise, so
     * it chains onto a call whose failure should stop the caller:
     *
     *     $order = $http->get('/orders/1')->throw()->json();
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw HttpRequestException::errorStatus($this->method, $this->effectiveUrl(), $this->status(), $this->body());
        }

        return $this;
    }

    /**
     * The resolved absolute URL for exception messages, so a log line
     * names the host even when the call site passed a path relative to
     * `withBaseUrl()`. getInfo() never throws, so this is safe on the
     * transport-failure path too.
     */
    private function effectiveUrl(): string
    {
        $resolved = $this->response->getInfo('url');

        return is_string($resolved) && $resolved !== '' ? $resolved : $this->url;
    }

    /** The underlying Symfony response, for anything not covered here. */
    public function toSymfonyResponse(): ResponseInterface
    {
        return $this->response;
    }
    /**
     * Runs a blocking read inside an event-loop-managed fiber and waits
     * for it on a Revolt suspension.
     *
     * Called from plain top-level code, Symfony's response-stream loop
     * only polls for transport activity once a second — the Amp bridge
     * fails to wake it when a chunk is already in, so every read from
     * outside a fiber pays that full poll tick. Inside a fiber the event
     * loop keeps turning and the same read completes in a couple of
     * milliseconds; this hands every caller that path. Resuming before
     * the suspend is reached is fine — a Revolt suspension stores the
     * result either way.
     *
     * @template T
     * @param Closure(): T $read
     * @return T
     */
    private static function await(Closure $read): mixed
    {
        $suspension = EventLoop::getSuspension();
        $result = null;
        $error = null;

        EventLoop::queue(static function () use ($read, $suspension, &$result, &$error): void {
            try {
                $result = $read();
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
}
