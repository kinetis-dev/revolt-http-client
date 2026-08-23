<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An HTTP client for application code: verbs that take arrays and return
 * a {@see HttpResponse}, over a transport that suspends the calling Fiber
 * instead of blocking the process.
 *
 *     $orders = $http->withToken($apiKey)
 *         ->get('https://api.example.com/orders', ['status' => 'open'])
 *         ->throw()
 *         ->json();
 *
 * Constructed with no arguments it uses {@see AmpHttpClientFactory}'s
 * Revolt-backed transport, so it autowires straight into a controller or
 * job with nothing to register. Pass any Symfony `HttpClientInterface` to
 * use a different one — a mock in a test, a scoped or instrumented client
 * in production. Under a persistent worker, register a configured
 * instance on AppScope rather than autowiring per request — autowiring
 * builds a fresh transport, and with it a fresh connection pool, each
 * time, losing keep-alive reuse across requests.
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
 */
final class Http
{
    /** @var array<string, mixed> */
    private array $options = [];

    private bool $sendAsForm = false;

    private readonly HttpClientInterface $transport;

    public function __construct(?HttpClientInterface $transport = null)
    {
        // Defaults through this package's own factory rather than
        // Symfony's class directly, so the Revolt-backed transport and
        // its defaults stay defined in one place.
        $this->transport = $transport ?? AmpHttpClientFactory::create();
    }

    /**
     * Prefixed to every relative URL, so call sites carry paths rather
     * than repeating the host.
     */
    public function withBaseUrl(string $baseUrl): self
    {
        return $this->withOptions(['base_uri' => $baseUrl]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        /** @var array<string, string> $existing */
        $existing = $this->options['headers'] ?? [];

        return $this->withOptions(['headers' => [...$existing, ...$headers]]);
    }

    public function withToken(string $token, string $scheme = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => trim("{$scheme} {$token}")]);
    }

    public function withBasicAuth(string $username, #[\SensitiveParameter] string $password): self
    {
        return $this->withOptions(['auth_basic' => [$username, $password]]);
    }

    /**
     * Query parameters merged into every request this client makes, on
     * top of whatever a verb method is given.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        /** @var array<string, mixed> $existing */
        $existing = $this->options['query'] ?? [];

        return $this->withOptions(['query' => [...$existing, ...$query]]);
    }

    /** Seconds to wait for the response, total. */
    public function withTimeout(float $seconds): self
    {
        return $this->withOptions(['timeout' => $seconds]);
    }

    /**
     * Retries failed requests, using Symfony's own retry strategy —
     * 5xx, 429, and connection failures, with exponential backoff, and
     * never a request that already reached a definitive answer.
     *
     * Applies to this client's transport, so a retrying client is
     * normally built once and reused rather than per call.
     */
    public function withRetries(int $times = 3): self
    {
        return new self(new RetryableHttpClient($this->transport, maxRetries: $times))
            ->withOptions($this->options)
            ->sendingAsForm($this->sendAsForm);
    }

    /**
     * Sends array bodies as `application/x-www-form-urlencoded` rather
     * than JSON — what OAuth token endpoints and older APIs expect.
     */
    public function asForm(): self
    {
        return $this->sendingAsForm(true);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $url, array $query = []): HttpResponse
    {
        return $this->send('GET', $url, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function post(string $url, array $body = []): HttpResponse
    {
        return $this->send('POST', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function put(string $url, array $body = []): HttpResponse
    {
        return $this->send('PUT', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function patch(string $url, array $body = []): HttpResponse
    {
        return $this->send('PATCH', $url, $this->bodyOption($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function delete(string $url, array $body = []): HttpResponse
    {
        return $this->send('DELETE', $url, $this->bodyOption($body));
    }

    /**
     * The general form, for anything the verbs don't cover — a raw body,
     * an upload, a header only this call needs. $options are Symfony
     * HttpClient options, merged over this client's own.
     *
     * @param array<string, mixed> $options
     */
    public function send(string $method, string $url, array $options = []): HttpResponse
    {
        return new HttpResponse(
            $this->transport->request($method, $url, $this->mergeOptions($options)),
            $method,
            $url,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<string, mixed>
     */
    private function bodyOption(array $body): array
    {
        if ($body === []) {
            return [];
        }

        return $this->sendAsForm ? ['body' => $body] : ['json' => $body];
    }

    /**
     * Merges per-call options over the client's, with headers and query
     * combined key-wise rather than replaced — a call adding one header
     * keeps the client's Authorization.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function mergeOptions(array $options): array
    {
        $merged = [...$this->options, ...$options];

        foreach (['headers', 'query'] as $key) {
            /** @var array<string, mixed> $mine */
            $mine = $this->options[$key] ?? [];
            /** @var array<string, mixed> $theirs */
            $theirs = $options[$key] ?? [];

            if ($mine !== [] || $theirs !== []) {
                $merged[$key] = [...$mine, ...$theirs];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->options = [...$this->options, ...$options];

        return $clone;
    }

    private function sendingAsForm(bool $asForm): self
    {
        $clone = clone $this;
        $clone->sendAsForm = $asForm;

        return $clone;
    }
}
