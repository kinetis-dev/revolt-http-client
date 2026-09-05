<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
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
 * Constructed with no arguments it uses {@see AmpHttpClientFactory}'s
 * Revolt-backed transport, so it autowires straight into a controller or
 * job with nothing to register. Under a persistent worker, register a
 * configured instance on AppScope rather than autowiring per request —
 * autowiring builds a fresh transport, and with it a fresh connection
 * pool, each time, losing keep-alive reuse across requests.
 *
 * Every `with*` method returns a new instance rather than mutating this
 * one, so a configured client is safe to hold as a shared service and
 * specialize per call:
 *
 *     $api = $http->withBaseUrl('https://api.example.com')->withToken($key);
 *     $api->get('/orders');                       // the shared config
 *     $api->withTimeout(30)->get('/reports/big'); // just this call
 *
 * Because requests suspend rather than block, several run concurrently
 * through `Kinetis\Async\concurrently()` with no pooling API of its own:
 *
 *     [$user, $orders] = concurrently([
 *         fn () => $api->get("/users/{$id}")->json(),
 *         fn () => $api->get("/users/{$id}/orders")->json(),
 *     ]);
 *
 * What this client guarantees, and what each guarantee costs:
 *
 * - **Every input is checked before a transport object exists.**
 *   {@see Preflight} validates the transport, the base URI, the URL, the
 *   method, the headers, the query, the body, the options, the timeout,
 *   the retry count, and the response-byte ceiling. A rejected call
 *   makes no transport call at all, and fails with
 *   {@see HttpRequestException}, the only exception type this package
 *   throws.
 * - **A credential is pinned to one origin.** A client carrying an
 *   `Authorization`, `Cookie`, or `Proxy-Authorization` header — set
 *   directly or through {@see withToken()}/{@see withBasicAuth()} —
 *   needs {@see withBaseUrl()}, and then every URL it accepts is
 *   relative to that base. Another origin is another client.
 * - **Redirects are never followed.** A 3xx is a terminal response with
 *   a `Location` header to read. Following one would mean deciding, per
 *   response, whether the new origin may see this client's
 *   Authorization header, cookies, and body — a decision belonging to
 *   the caller who knows what the credential is for.
 * - **One retry layer, owned here.** {@see withRetries()} is the only
 *   way to configure it, a per-call retry option is rejected, a
 *   retrying transport is rejected at construction, and a body that
 *   cannot be replayed is rejected rather than resent.
 * - **One total deadline, on a monotonic clock.**
 *   {@see withTimeout()} bounds the whole operation — every attempt,
 *   every backoff between them, and every read of the response that
 *   comes out of it.
 * - **A bounded response.** {@see withMaxResponseBytes()} is the
 *   ceiling a body may reach, enforced while it arrives rather than
 *   after it is already in memory.
 *
 * **What an injected transport must be.** Pass any Symfony
 * `HttpClientInterface` to use a different one — a mock in a test, an
 * instrumented client in production. The guarantees above are this
 * package's, and two of them need the transport's cooperation: it must
 * not retry (a client that does is rejected outright), and it must not
 * carry credentials or a base URI of its own, which the origin pinning
 * above cannot see and therefore cannot pin. A transport that does
 * carry them answers for where they go. A synchronous transport —
 * Symfony's `CurlHttpClient` or `NativeHttpClient` — is accepted and
 * blocks the process for the length of the request; only the
 * Revolt-backed default suspends the calling Fiber.
 */
final class Http
{
    /**
     * Statuses worth sending the same request for again: the server
     * either said so (429, 503 with a queue behind them) or failed in a
     * way that is commonly transient. Every other status, 4xx included,
     * is an answer — repeating the request cannot change it.
     */
    private const array RETRYABLE_STATUSES = [423, 425, 429, 500, 502, 503, 504, 507, 509];

    /** Doubling from here, and always inside the total timeout. */
    private const float FIRST_BACKOFF_SECONDS = 0.1;

    /**
     * Header names that carry a credential to whatever origin the
     * request reaches, and so make {@see withBaseUrl()} mandatory.
     */
    private const array CREDENTIAL_HEADERS = ['authorization', 'cookie'];

    /**
     * Asked for on every request, and not a caller's to change. With no
     * `Accept-Encoding` of its own a Symfony response inflates a
     * compressed body transparently, which would make the byte ceiling
     * a bound on what arrived rather than on what is held: a kilobyte
     * off the wire becomes a megabyte in memory before anything can
     * measure it. Asking for identity turns that inflation off, so the
     * bytes counted and the bytes held are the same bytes — including
     * when a server answers with a compressed body anyway, which then
     * arrives, and is bounded, as the compressed bytes it is.
     */
    private const array IDENTITY_ENCODING = ['name' => 'Accept-Encoding', 'values' => ['identity']];

    /** Bounds an operation that never got one from the caller. */
    public const float DEFAULT_TIMEOUT_SECONDS = 30.0;

    /**
     * Bounds a response body that never got a ceiling from the caller:
     * generous for an API payload, and far below what it takes to
     * exhaust a worker's memory with one reply.
     */
    public const int DEFAULT_MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    private ?BaseUri $baseUri = null;

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
        // Defaults through this package's own factory rather than
        // Symfony's class directly, so the Revolt-backed transport and
        // its defaults stay defined in one place — and through the
        // variant that adds no retry layer of its own beneath this
        // client's, which is the whole of what "one retry layer" means.
        $this->transport = Preflight::transport($transport) ?? AmpHttpClientFactory::createWithoutRetries();
    }

    /**
     * Prefixed to every request this client makes, so call sites carry
     * paths rather than repeating the host. The base URI is absolute,
     * http or https, and carries no userinfo, query string, or fragment.
     *
     * Its path is a prefix, joined by concatenation and never replaced:
     * a base of `https://api.example.com/v1` sends `/orders` to
     * `https://api.example.com/v1/orders`, with or without the leading
     * slash on either side. Once a base URI is set, a request URL must
     * be relative to it, which is what keeps a configured Authorization
     * header on the origin it was meant for.
     */
    public function withBaseUrl(#[SensitiveParameter] string $baseUrl): self
    {
        $clone = clone $this;
        $clone->baseUri = Preflight::baseUri($baseUrl);

        return $clone;
    }

    /**
     * Headers in either documented form — associative `'Name' =>
     * 'value'` or `'Name' => ['v1', 'v2']`, and raw `"Name: value"`
     * lines under integer keys, one form per array — validated by
     * {@see Preflight::headers()} and merged over what this client
     * already carries.
     *
     * A later call replaces an earlier one for the same field name,
     * case-insensitively, matching HTTP's own case-insensitive field
     * names: a second `withHeaders(['authorization' => ...])` replaces
     * an earlier `withHeaders(['Authorization' => ...])` entirely,
     * rather than sending both.
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
        $scheme = Preflight::authScheme($scheme);
        $token = Preflight::credential($token, 'an authentication token');

        return $this->withHeaders(['Authorization' => "{$scheme} {$token}"]);
    }

    /**
     * An `Authorization` header carrying basic credentials. Built here
     * rather than handed to the transport as an option, so credentials
     * follow the same one-header-per-name merge as everything else.
     */
    public function withBasicAuth(#[SensitiveParameter] string $userId, #[SensitiveParameter] string $password): self
    {
        $userId = Preflight::basicUserId($userId);
        $password = Preflight::credential($password, 'a basic-auth password');

        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode("{$userId}:{$password}")]);
    }

    /**
     * Query parameters merged into every request this client makes, on
     * top of whatever a verb method is given.
     *
     * @param array<array-key, mixed> $query
     */
    public function withQuery(#[SensitiveParameter] array $query): self
    {
        $clone = clone $this;
        $clone->query = [...$this->query, ...Preflight::query($query)];

        return $clone;
    }

    /**
     * The total budget for one operation, in seconds: every attempt,
     * every backoff between them, and every read of the response that
     * comes out of it, measured on a monotonic clock. Running out throws
     * {@see HttpRequestException} with the `Timeout` category.
     */
    public function withTimeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = Preflight::timeout($seconds);

        return $clone;
    }

    /**
     * The ceiling one response body may reach, in bytes, replacing
     * {@see DEFAULT_MAX_RESPONSE_BYTES}. See {@see HttpResponse::body()}
     * for when the ceiling is checked and what passing it costs.
     */
    public function withMaxResponseBytes(int $bytes): self
    {
        $clone = clone $this;
        $clone->maxResponseBytes = Preflight::responseByteCeiling($bytes);

        return $clone;
    }

    /**
     * Sends a failed request again, up to $times more times, with
     * exponential backoff from 100 ms, for either kind of failure this
     * client can survive a repeat of:
     *
     * - a transport failure, whether the transport refused the request
     *   as it was built or the response never arrived;
     * - a status the server itself marks as worth repeating (423, 425,
     *   429, 500, 502, 503, 504, 507, 509).
     *
     * Every other status is returned as it is, and so is the last
     * status of an attempt that ran out of retries. A transport failure
     * that outlives them has no response to hand back, so it throws.
     *
     * This is the only retry layer: a transport that retries on its own
     * is rejected at construction, a per-call retry option is rejected
     * rather than merged, and a request whose body is a stream or a
     * Closure is rejected outright, because such a body is consumed as
     * it is read and a second attempt would send a body that is already
     * gone. A response past the byte ceiling is not retried either —
     * the same request would fetch the same oversized body again.
     *
     * A retrying client waits for the response status inside `send()`,
     * since that status is what the decision is made on; without
     * retries, `send()` returns as soon as the request is issued and
     * every read stays deferred.
     *
     * Retrying a request that is not idempotent is the caller's call to
     * make: neither a 5xx nor a dropped connection proves the server did
     * not already act on it.
     */
    public function withRetries(int $times = 3): self
    {
        $clone = clone $this;
        $clone->retries = Preflight::retries($times);

        return $clone;
    }

    /**
     * Sends array bodies as `application/x-www-form-urlencoded` rather
     * than JSON — what OAuth token endpoints and older APIs expect.
     */
    public function asForm(): self
    {
        $clone = clone $this;
        $clone->sendAsForm = true;

        return $clone;
    }

    /**
     * @param array<array-key, mixed> $query
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
     * The general form, for anything the verbs do not cover — a raw
     * body, an upload, a header only this call needs.
     *
     * $options is an exact map of the options this client can check:
     * `headers`, `query`, `json`, `body`, and `timeout`. An option this
     * client owns (`base_uri`, `max_redirects`, `max_retries`,
     * `retry_failed`, `retry_strategy`, `max_duration`, `auth_basic`,
     * `auth_bearer`, `on_progress`, `buffer`) is rejected and points at
     * the builder that sets it; anything else is rejected as
     * unsupported, because an option this boundary cannot check is an
     * option none of the guarantees above would still hold for.
     *
     * `body` is the one place a caller can hand over something this
     * package cannot inspect: a stream resource or a Closure. Those are
     * sent as they are, and are rejected outright by a client with
     * retries configured, since neither can be replayed.
     *
     * @param array<array-key, mixed> $options
     */
    public function send(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $options = []): HttpResponse
    {
        $method = Preflight::method($method);
        $options = Preflight::callOptions($options);

        $headers = isset($options['headers'])
            ? Preflight::headers($options['headers']) + $this->headers
            : $this->headers;

        $this->assertCredentialsAreBoundToAnOrigin($headers);

        $target = Preflight::target($this->baseUri, $url);

        $query = isset($options['query'])
            ? [...$this->query, ...Preflight::query($options['query'])]
            : $this->query;

        if ($query !== [] && $target->hasQueryString) {
            throw HttpRequestException::invalidRequest(
                'a request URL that already carries a query string cannot also take query parameters as an array; '
                    . 'the two have no defined order. Put every parameter in one of them.',
            );
        }

        [$body, $contentType] = $this->resolveBody($options);

        if ($contentType !== null && !isset($headers['content-type'])) {
            $headers['content-type'] = ['name' => 'Content-Type', 'values' => [$contentType]];
        }

        $headers['accept-encoding'] = self::IDENTITY_ENCODING;

        $transportOptions = ['headers' => self::flattenHeaders($headers), 'max_redirects' => 0];

        if ($query !== []) {
            $transportOptions['query'] = $query;
        }

        if ($body !== null) {
            $transportOptions['body'] = $body;
        }

        return $this->dispatch($method, $target, $transportOptions, $options['timeout'] ?? $this->timeout);
    }

    /**
     * A credential is only safe to attach to a request when the request
     * cannot reach an origin the credential was not issued for. The base
     * URI is what makes that true: with one set, every URL this client
     * accepts is relative to it, an absolute or scheme-relative target
     * is rejected, and a 3xx is never followed. Without one, a call site
     * chooses the whole URL, which would mean a call site chooses who
     * receives the Authorization header.
     *
     * Reaching a second origin is a second client, configured with the
     * credential that origin should see. A `Proxy-Authorization` header
     * is addressed to a proxy rather than to the origin, so a base URI
     * cannot confine it at all; {@see Preflight::headers()} rejects it
     * outright instead of pinning it to something it does not go to.
     *
     * @param array<string, array{name: string, values: non-empty-list<string>}> $headers
     */
    private function assertCredentialsAreBoundToAnOrigin(#[SensitiveParameter] array $headers): void
    {
        if ($this->baseUri !== null || array_intersect_key($headers, array_flip(self::CREDENTIAL_HEADERS)) === []) {
            return;
        }

        throw HttpRequestException::invalidConfiguration(
            'a client carrying an Authorization, Cookie, or Proxy-Authorization header needs withBaseUrl(), '
                . 'so the credential can only reach the one origin it was issued for; reach a second origin '
                . 'with a second client.',
        );
    }

    /**
     * One attempt, then as many more as `withRetries()` allows, all
     * inside a single deadline. The deadline is what makes the timeout
     * total: each attempt is given only what is left of it, an attempt
     * is never started nor a backoff waited out once nothing is left,
     * and the {@see ResponseBudget} handed to the surviving response
     * carries the same deadline into every read of it.
     *
     * @param array<string, mixed> $options
     */
    private function dispatch(
        string $method,
        #[SensitiveParameter] RequestTarget $target,
        #[SensitiveParameter] array $options,
        float $timeout,
    ): HttpResponse {
        $deadline = Deadline::startingNow($timeout);
        $attempts = 0;

        while (true) {
            if ($deadline->expired()) {
                throw HttpRequestException::timedOut($method, $target->origin, $timeout, $attempts);
            }

            $budget = new ResponseBudget($method, $target->origin, $deadline, $this->maxResponseBytes, ++$attempts);
            $response = $this->issue($method, $target, $budget->applyTo($options));

            // A transport is handed what is left of the deadline and is
            // trusted with none of it: one that ignores the duration and
            // returns — or raises — past it has still spent the budget,
            // and that is a timeout whichever way it came back.
            if ($deadline->expired()) {
                self::abandon($response);

                throw $budget->timedOut();
            }

            if ($this->retries === 0) {
                // Nothing left to decide, so the response stays deferred
                // and every read of it happens where the caller asked.
                return $response === null
                    ? throw $budget->transportFailure()
                    : new HttpResponse($response, $budget);
            }

            $failed = $response === null;
            $retryable = true;

            if ($response !== null) {
                try {
                    $retryable = in_array(Loop::await($response->getStatusCode(...)), self::RETRYABLE_STATUSES, true);
                } catch (Throwable) {
                    // The transport's own exception stops here: its
                    // message can name the full URI, credentials and all.
                    $failed = true;
                }
            }

            if ($budget->exceeded) {
                self::abandon($response);

                throw $budget->tooLarge();
            }

            // Waiting for the status spent part of the budget, so the
            // deadline is asked again before anything is retried or a
            // response is handed over that every later read would be
            // measured against.
            if ($deadline->expired()) {
                self::abandon($response);

                throw $budget->timedOut();
            }

            if ($retryable && $attempts <= $this->retries) {
                self::abandon($response);

                $backoff = self::FIRST_BACKOFF_SECONDS * 2 ** ($attempts - 1);

                if ($backoff >= $deadline->remaining()) {
                    throw $budget->timedOut();
                }

                Loop::pause($backoff);

                continue;
            }

            if ($response === null || $failed) {
                self::abandon($response);

                throw $budget->transportFailure();
            }

            return new HttpResponse($response, $budget);
        }
    }

    /**
     * Issues one attempt. A transport that refuses the request as it is
     * built raises where the request is made rather than where it is
     * read, and that exception ends here: it comes from a library
     * holding the full URL, and this package promises its own exceptions
     * carry only an origin. The absent response is what the retry loop
     * sees, so a construction failure is retried on exactly the same
     * terms as one that surfaced from the wire.
     *
     * @param array<string, mixed> $options
     */
    private function issue(
        string $method,
        #[SensitiveParameter] RequestTarget $target,
        #[SensitiveParameter] array $options,
    ): ?ResponseInterface {
        try {
            return $this->transport->request($method, $target->url, $options);
        } catch (Throwable) {
            return null;
        }
    }

    /** Gives back a response no read will ever reach, if there is one. */
    private static function abandon(?ResponseInterface $response): void
    {
        if ($response !== null) {
            HttpResponse::release($response);
        }
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
     * Encoding happens here, not in the transport, so a value that
     * cannot be encoded fails as this package's own typed exception
     * instead of a vendor one carrying the value that failed. The
     * returned content type is a default: a caller who set one keeps it.
     *
     * @param array{headers?: array<array-key, mixed>, query?: array<array-key, mixed>, json?: array<array-key, mixed>, body?: mixed, timeout?: float} $options
     * @return array{0: mixed, 1: string|null}
     */
    private function resolveBody(#[SensitiveParameter] array $options): array
    {
        if (array_key_exists('json', $options)) {
            return [Preflight::encodeJson(Preflight::bodyArray($options['json'], asForm: false)), 'application/json'];
        }

        if (!array_key_exists('body', $options)) {
            return [null, null];
        }

        $body = Preflight::rawBody($options['body'], $this->retries > 0);

        return is_array($body)
            ? [Preflight::encodeForm($body), 'application/x-www-form-urlencoded']
            : [$body, null];
    }

    /**
     * The validated header map, in the shape the transport takes: one
     * entry per field name, a single string where there is one value and
     * a list where the caller asked for several.
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
