<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use InvalidArgumentException;
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
     * A later call overrides an earlier one for the same header name,
     * matching HTTP's own case-insensitive field-name semantics — a
     * second `withHeaders(['authorization' => ...])` replaces an earlier
     * `withHeaders(['Authorization' => ...])` entirely, not alongside it.
     * See {@see mergeHeaders()} for how that's guaranteed rather than
     * left to depend on Symfony's own internal header normalization.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        /** @var array<array-key, mixed> $existing */
        $existing = $this->options['headers'] ?? [];

        return $this->withOptions(['headers' => self::mergeHeaders($existing, $headers)]);
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
     * keeps the client's Authorization. Headers merge case-insensitively
     * by name (see {@see mergeHeaders()}); query keys are ordinary,
     * case-sensitive PHP array keys, so a plain spread is correct there.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function mergeOptions(array $options): array
    {
        $merged = [...$this->options, ...$options];

        /** @var array<array-key, mixed> $myHeaders */
        $myHeaders = $this->options['headers'] ?? [];
        /** @var array<array-key, mixed> $theirHeaders */
        $theirHeaders = $options['headers'] ?? [];

        if ($myHeaders !== [] || $theirHeaders !== []) {
            $merged['headers'] = self::mergeHeaders($myHeaders, $theirHeaders);
        }

        /** @var array<string, mixed> $myQuery */
        $myQuery = $this->options['query'] ?? [];
        /** @var array<string, mixed> $theirQuery */
        $theirQuery = $options['query'] ?? [];

        if ($myQuery !== [] || $theirQuery !== []) {
            $merged['query'] = [...$myQuery, ...$theirQuery];
        }

        return $merged;
    }

    /**
     * Merges two Symfony HttpClient-shaped header arrays, case-
     * insensitively by header name, with $theirs winning entirely over
     * $mine for any name both define — the same "later/more specific
     * wins" precedence this class already documents for option merging
     * generally.
     *
     * Each side is first resolved independently, via
     * {@see normalizeHeaderArray()}, into exactly one entry per logical
     * header name — collapsing any same-name collision *within* that one
     * side before the two sides are ever compared. This is deliberate,
     * not incidental: reassembling a winning name's *raw* multiple
     * occurrences (several numeric "Name: value" lines, or several
     * differently-cased associative keys) back into the Symfony options
     * array would leave Symfony's own `normalizeHeaders()` to resolve
     * them — and it resolves multiple *separate* top-level array entries
     * for the same name by unconditionally resetting on each one, an
     * undocumented internal collapse, not a rule this class controls or
     * should depend on for its own documented precedence. Normalizing
     * first means the merged result Symfony ever sees contains at most
     * one array entry per header name, so there is nothing left for its
     * own normalization to have to resolve.
     *
     * @param array<array-key, mixed> $mine
     * @param array<array-key, mixed> $theirs
     * @return array<string, string|list<string>>
     */
    private static function mergeHeaders(array $mine, array $theirs): array
    {
        $mineResolved = self::normalizeHeaderArray($mine);
        $theirsResolved = self::normalizeHeaderArray($theirs);

        // Array union (+) keeps the LEFT operand's value for any key
        // present in both — putting $theirsResolved first is what makes
        // it win over $mineResolved for a shared (lowercase) name.
        $winning = $theirsResolved + $mineResolved;

        $result = [];

        foreach ($winning as ['name' => $name, 'values' => $values]) {
            $result[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }

    /**
     * Resolves one header array — which may freely mix Symfony's own two
     * accepted forms, associative (`'Name' => 'value'` or `'Name' =>
     * ['v1', 'v2']`) and numerically-indexed raw `"Name: value"` lines —
     * into exactly one entry per lowercase header name:
     * `['name' => <winning casing>, 'values' => <ordered value list>]`.
     *
     * Multiple *numeric-line* occurrences of the same name are a
     * deliberately repeated header (there is no other reason to write
     * the same name twice in raw-line form) and are combined into one
     * ordered multi-value list, using the *last* occurrence's own
     * casing as the winning spelling — consistent with every other
     * "later wins" precedence in this class, applied here for the one
     * remaining choice that needs to be made (which casing labels the
     * combined list).
     *
     * Multiple *associative* occurrences of the same name (two different
     * PHP keys — differently cased spellings of one HTTP field) are a
     * same-array casing collision, not a repeat: the *last* occurrence
     * wins outright, its own value(s) kept and every earlier occurrence
     * for that name dropped — never combined, since re-interpreting
     * several distinct associative entries as one intentional
     * multi-value list would be assuming something the input never
     * actually said.
     *
     * A name given via *both* forms within the same array — one
     * associative entry and one raw line for what's nominally the same
     * header — has no principled single resolution (which form's value
     * should anchor the combined list?) and is rejected outright with a
     * clear exception instead of guessing.
     *
     * A raw numeric-line entry's name is read the same way Symfony's own
     * normalizeHeaders() reads it — explode() capped at the first colon
     * only — so a value containing its own embedded colon (a URL, for
     * instance) is never mistaken for part of the name. An entry that
     * can't be understood as a header at all (a numeric key whose value
     * isn't a "Name: value" string, or whose name portion is empty or
     * all whitespace, e.g. ": value") is rejected here, clearly, rather
     * than passed through to fail later with a vaguer error from
     * Symfony's own normalization or a silent wrong split.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, array{name: string, values: list<string>}>
     */
    private static function normalizeHeaderArray(array $headers): array
    {
        /** @var array<string, list<array{kind: 'associative'|'numeric', name: string, values: array<string>}>> $occurrences */
        $occurrences = [];

        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !str_contains($value, ':')) {
                    throw new InvalidArgumentException(sprintf(
                        'A numeric header entry must be a "Name: value" string; got %s.',
                        get_debug_type($value),
                    ));
                }

                [$name, $rest] = explode(':', $value, 2);
                $name = trim($name);

                if ($name === '') {
                    throw new InvalidArgumentException(
                        'A numeric header entry must not have an empty header name (e.g. ": value").',
                    );
                }

                $occurrences[strtolower($name)][] = [
                    'kind' => 'numeric',
                    'name' => $name,
                    'values' => [ltrim($rest)],
                ];

                continue;
            }

            /** @var mixed $value */
            $values = is_iterable($value)
                ? array_map(static fn (mixed $v): string => (string) $v, [...$value])
                : [(string) $value];

            $occurrences[strtolower($key)][] = [
                'kind' => 'associative',
                'name' => $key,
                'values' => $values,
            ];
        }

        return self::resolveHeaderOccurrences($occurrences);
    }

    /**
     * @param array<string, list<array{kind: 'associative'|'numeric', name: string, values: array<string>}>> $occurrences
     * @return array<string, array{name: string, values: list<string>}>
     */
    private static function resolveHeaderOccurrences(array $occurrences): array
    {
        $resolved = [];

        foreach ($occurrences as $lowercaseName => $entries) {
            $kinds = array_unique(array_column($entries, 'kind'));

            if (count($kinds) > 1) {
                throw new InvalidArgumentException(sprintf(
                    'Header "%s" is given in both associative and raw "Name: value" line form within '
                        . 'the same array — use only one form for a given header name.',
                    $lowercaseName,
                ));
            }

            if ($kinds === ['numeric']) {
                $values = [];

                foreach ($entries as $entry) {
                    array_push($values, ...$entry['values']);
                }

                $resolved[$lowercaseName] = ['name' => $entries[count($entries) - 1]['name'], 'values' => $values];

                continue;
            }

            // Associative: the last occurrence wins outright, its own
            // value(s) kept as-is; every earlier occurrence for this
            // name is dropped, never combined with it.
            $winner = $entries[count($entries) - 1];
            $resolved[$lowercaseName] = ['name' => $winner['name'], 'values' => array_values($winner['values'])];
        }

        return $resolved;
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
