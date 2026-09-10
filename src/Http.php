<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Revolt\EventLoop;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * An HTTP client for application code: verbs that take arrays and return
 * a {@see HttpResponse}, over a transport that suspends the calling
 * Fiber instead of blocking the process.
 *
 *     $orders = $http->withBaseUrl('https://api.example.com')
 *         ->withToken($apiKey)
 *         ->get('/orders', ['status' => 'open'])
 *         ->throw()
 *         ->json();
 *
 * With no argument it uses {@see AmpHttpClientFactory}'s transport and
 * autowires with nothing to register; under a persistent worker register
 * a configured instance instead, so one connection pool outlives the
 * request. Every `with*` method returns a new instance.
 *
 * `docs/revolt-http-client.md` states what this client guarantees. An
 * injected transport is the caller's to hold to the same contract: one
 * wire attempt per request, and no credentials or base URI of its own,
 * which the origin pinning here cannot see and so cannot pin.
 */
final class Http
{
    /**
     * Statuses worth sending the same request for again: the server
     * either said so or failed in a commonly transient way. Every other
     * status, 4xx included, is an answer a repeat cannot change.
     */
    private const array RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * RFC 9110's idempotent methods, matched exactly. A repeat of any
     * other request can apply it twice, and neither a dropped connection
     * nor a retryable status proves the first one was not applied.
     */
    private const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'];

    /** Doubling from here, and always inside the total timeout. */
    private const float FIRST_BACKOFF_SECONDS = 0.1;

    /** Headers that carry a credential, and so make a base URL mandatory. */
    private const array CREDENTIAL_HEADERS = ['authorization', 'cookie'];

    /**
     * Asked for on every request and not a caller's to change: the byte
     * ceiling counts what arrives, which bounds memory only while
     * nothing inflates the body in between. `docs/revolt-http-client.md`
     * states what that trades away.
     */
    private const array IDENTITY_ENCODING = ['name' => 'Accept-Encoding', 'values' => ['identity']];

    /** Bounds an operation that never got one from the caller. */
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;

    /** Bounds a response body that never got a ceiling from the caller. */
    public const int DEFAULT_MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /** @var array{0: string, 1: string}|null origin, and the prefix a relative URL extends */
    private ?array $base = null;

    /** @var array<string, array{name: string, values: non-empty-list<string>}> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $query = [];

    private float $timeout = self::DEFAULT_TIMEOUT_SECONDS;

    private int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES;

    private int $retries = 0;

    private bool $sendAsForm = false;

    private readonly HttpClientInterface $transport;

    public function __construct(?HttpClientInterface $transport = null)
    {
        $this->transport = $transport ?? AmpHttpClientFactory::create();
    }

    /**
     * Prefixed to every request. Its path is a prefix joined by
     * concatenation and never replaced, and once it is set a request URL
     * must be relative to it — which is what keeps a configured
     * Authorization header on one origin.
     */
    public function withBaseUrl(#[SensitiveParameter] string $baseUrl): self
    {
        $clone = clone $this;
        $clone->base = Preflight::baseUri($baseUrl);

        return $clone;
    }

    /**
     * Headers as `'Name' => 'value'` or `'Name' => ['v1', 'v2']`, merged
     * over what this client already carries. A later call replaces an
     * earlier one for the same field name, case-insensitively, rather
     * than sending both.
     *
     * @param array<array-key, mixed> $headers
     */
    public function withHeaders(#[SensitiveParameter] array $headers): self
    {
        $clone = clone $this;
        // Array union keeps the left operand's value for a shared key,
        // so the new headers win over the ones already configured.
        $clone->headers = Preflight::headers($headers) + $this->headers;

        return $clone;
    }

    /** An `Authorization` header built from a token and a scheme name. */
    public function withToken(#[SensitiveParameter] string $token, string $scheme = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => "{$scheme} {$token}"]);
    }

    /**
     * An `Authorization` header carrying basic credentials, built here
     * rather than handed to the transport as an option so credentials
     * follow the same one-header-per-name merge as everything else.
     */
    public function withBasicAuth(#[SensitiveParameter] string $userId, #[SensitiveParameter] string $password): self
    {
        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode("{$userId}:{$password}")]);
    }

    /**
     * Query parameters merged into every request, on top of whatever a
     * verb method is given.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(#[SensitiveParameter] array $query): self
    {
        $clone = clone $this;
        $clone->query = [...$this->query, ...$query];

        return $clone;
    }

    /**
     * The total budget for one operation, in seconds: every attempt,
     * every backoff between them, and every read of the response that
     * comes out of it, on a monotonic clock.
     */
    public function withTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = Preflight::timeout($seconds);

        return $clone;
    }

    /** The ceiling one response body may reach; see {@see HttpResponse::body()}. */
    public function withMaxResponseBytes(int $bytes): self
    {
        $clone = clone $this;
        $clone->maxResponseBytes = Preflight::responseByteCeiling($bytes);

        return $clone;
    }

    /**
     * Sends a failed GET, HEAD, OPTIONS, TRACE, PUT or DELETE request
     * again, up to $times more times (at most 10), with backoff doubling
     * from 100 ms inside the one operation deadline. Out of retries, or of
     * budget for the next backoff, the last response received is
     * returned; a transport failure with no response behind it throws
     * instead. Every other method is sent once, as a client without
     * retries sends it.
     */
    public function withRetries(int $times = 3): self
    {
        $clone = clone $this;
        $clone->retries = Preflight::retries($times);

        return $clone;
    }

    /** Sends array bodies as `application/x-www-form-urlencoded` rather than JSON. */
    public function asForm(): self
    {
        $clone = clone $this;
        $clone->sendAsForm = true;

        return $clone;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(#[SensitiveParameter] string $url, #[SensitiveParameter] array $query = []): HttpResponse
    {
        return $this->send('GET', $url, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function post(#[SensitiveParameter] string $url, #[SensitiveParameter] array $body = []): HttpResponse
    {
        return $this->send('POST', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function put(#[SensitiveParameter] string $url, #[SensitiveParameter] array $body = []): HttpResponse
    {
        return $this->send('PUT', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function patch(#[SensitiveParameter] string $url, #[SensitiveParameter] array $body = []): HttpResponse
    {
        return $this->send('PATCH', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function delete(#[SensitiveParameter] string $url, #[SensitiveParameter] array $body = []): HttpResponse
    {
        return $this->send('DELETE', $url, $this->bodyOption($body));
    }

    /**
     * The general form, for anything the verbs do not cover. $options is
     * an exact map of `headers`, `query`, `json`, `body`, and `timeout`;
     * every other option belongs to a `with*` method here.
     *
     * @param array<array-key, mixed> $options
     */
    public function send(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $options = []): HttpResponse
    {
        $options = Preflight::callOptions($options);

        $headers = isset($options['headers'])
            ? Preflight::headers($options['headers']) + $this->headers
            : $this->headers;

        $this->assertCredentialsAreBoundToAnOrigin($headers);

        [$target, $origin] = Preflight::target($this->base, $url);

        $headers['accept-encoding'] = self::IDENTITY_ENCODING;

        $transportOptions = ['headers' => self::flattenHeaders($headers), 'max_redirects' => 0];

        $query = isset($options['query']) ? [...$this->query, ...$options['query']] : $this->query;

        if ($query !== []) {
            $transportOptions['query'] = $query;
        }

        $retries = in_array($method, self::IDEMPOTENT_METHODS, true) ? $this->retries : 0;

        if (array_key_exists('json', $options)) {
            $transportOptions['json'] = $options['json'];
        } elseif (array_key_exists('body', $options)) {
            $transportOptions['body'] = self::replayableBody($options['body'], $retries);
        }

        return $this->dispatch(
            $method,
            $target,
            $origin,
            $transportOptions,
            $options['timeout'] ?? $this->timeout,
            $retries,
        );
    }

    /**
     * Without a base URL a call site chooses the whole URL, and so
     * chooses who receives the Authorization header.
     *
     * @param array<string, array{name: string, values: non-empty-list<string>}> $headers
     */
    private function assertCredentialsAreBoundToAnOrigin(#[SensitiveParameter] array $headers): void
    {
        if ($this->base !== null || array_intersect_key($headers, array_flip(self::CREDENTIAL_HEADERS)) === []) {
            return;
        }

        throw HttpRequestException::invalidRequest(
            'A client carrying an Authorization or Cookie header needs withBaseUrl(); reach a second origin with '
                . 'a second client.',
        );
    }

    /**
     * One attempt, then as many more as $retries allows — `withRetries()`
     * for an idempotent method, none for any other — all inside a single
     * deadline: each attempt gets only what is left of it, and the
     * {@see ResponseBudget} handed to the surviving response carries the
     * same deadline into every read of it.
     *
     * @param array<string, mixed> $options
     */
    private function dispatch(
        string $method,
        #[SensitiveParameter] string $target,
        string $origin,
        #[SensitiveParameter] array $options,
        float $timeout,
        int $retries,
    ): HttpResponse {
        $budget = new ResponseBudget($method, $origin, $timeout, $this->maxResponseBytes);

        while (true) {
            if ($budget->expired()) {
                throw $budget->timedOut();
            }

            ++$budget->attempts;
            $response = new HttpResponse($this->issue($method, $target, $budget->applyTo($options)), $budget);

            if ($retries === 0) {
                // Nothing left to decide, so the response stays deferred
                // and every read of it happens where the caller asked.
                return $response;
            }

            // Waiting for the status is a read like any other:
            // HttpResponse classifies a vendor failure from the budget's
            // state and releases the response before raising. Only
            // Transport is worth another attempt.
            try {
                $status = $response->status();
            } catch (HttpRequestException $failure) {
                if ($failure->category !== HttpFailure::Transport) {
                    throw $failure;
                }

                $status = null;
            }

            if ($status !== null && !in_array($status, self::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $backoff = self::FIRST_BACKOFF_SECONDS * 2 ** ($budget->attempts - 1);

            if ($budget->attempts > $retries || $backoff >= $budget->remaining()) {
                return $status === null ? throw $budget->transportFailure() : $response;
            }

            $response->discard();
            self::pause($backoff);
        }
    }

    /**
     * Issues one attempt. A transport refusing the request as it is built
     * raises from a library holding the full URL and the value it
     * refused, so that exception ends here and fixed text replaces it.
     *
     * @param array<string, mixed> $options
     */
    private function issue(
        string $method,
        #[SensitiveParameter] string $target,
        #[SensitiveParameter] array $options,
    ): ResponseInterface {
        try {
            return $this->transport->request($method, $target, $options);
        } catch (Throwable) {
            throw HttpRequestException::invalidRequest(
                'The transport refused to construct this request; check the method and the body and query values.',
            );
        }
    }

    /**
     * A stream resource or a Closure is consumed as it is read, so a
     * request that may be retried refuses one rather than resending a
     * body that is already gone. A request sent once takes it as it is.
     */
    private static function replayableBody(#[SensitiveParameter] mixed $body, int $retries): mixed
    {
        if ($retries > 0 && (is_resource($body) || $body instanceof Closure)) {
            throw HttpRequestException::invalidRequest(
                'A stream or Closure body cannot be replayed, so it cannot be sent with a method this client retries.',
            );
        }

        return $body;
    }

    /** Suspends the caller for $seconds, leaving the loop free to run everything else. */
    private static function pause(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();

        EventLoop::delay($seconds, static fn () => $suspension->resume());

        $suspension->suspend();
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<string, mixed>
     */
    private function bodyOption(#[SensitiveParameter] array $body): array
    {
        if ($body === []) {
            return [];
        }

        return $this->sendAsForm ? ['body' => $body] : ['json' => $body];
    }

    /**
     * The validated header map in the shape the transport takes: a
     * string for a single value and a list for several.
     *
     * @param array<string, array{name: string, values: non-empty-list<string>}> $headers
     * @return array<string, string|list<string>>
     */
    private static function flattenHeaders(#[SensitiveParameter] array $headers): array
    {
        $flattened = [];

        foreach ($headers as ['name' => $name, 'values' => $values]) {
            $flattened[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $flattened;
    }
}
