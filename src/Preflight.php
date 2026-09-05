<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use JsonException;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The single boundary every public path of {@see Http} crosses before a
 * transport object is built or a byte moves. Each method either returns
 * a validated value or throws {@see HttpRequestException}; nothing here
 * repairs, casts, or guesses at input, and nothing here calls the
 * transport, so a rejected request is a request that never happened.
 *
 * The messages are fixed text plus values proven safe where they are
 * used: a name that has already passed the token check, an option key
 * that {@see safeLabel()} recognizes as a key rather than a value, a
 * type name, an integer. A rejected *value* is described by its type
 * and never quoted back — the thing being rejected is the thing most
 * likely to be a credential. Every parameter that forwards caller input
 * is `#[\SensitiveParameter]`, so the value stays out of a stack trace
 * as well as out of a message.
 *
 * @internal
 */
final class Preflight
{
    /** RFC 9110 token: what a header name, a method, and an auth scheme may contain. */
    private const string TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/';

    /** C0 controls and DEL, minus HTAB, which is legal inside a field value. */
    private const string ILLEGAL_IN_VALUE = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /** A URL is sent as bytes: no spaces, no controls, no unescaped non-ASCII. */
    private const string NON_URL_BYTE = '/[^\x21-\x7E]/';

    /**
     * DNS labels of letters, digits, hyphen and underscore, joined by
     * single dots. A percent escape, an "@", a colon, an empty label,
     * and a leading or trailing hyphen or dot are all excluded.
     */
    private const string DNS_NAME = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?)*$/';

    /**
     * A final label that a resolver reads as a number rather than a
     * name, decimal or hexadecimal. A host ending in one is an address,
     * so it has to be a well-formed one.
     */
    private const string NUMERIC_LABEL = '/^(?:[0-9]+|0x[0-9a-f]+)$/';

    /**
     * A transport that retries on its own. Named as a string and matched
     * with {@see is_a()}, so this package neither imports nor requires
     * the decorator to reject it.
     */
    private const string RETRYING_DECORATOR = 'Symfony\Component\HttpClient\RetryableHttpClient';

    /** Retries are bounded by construction, not only by what a caller asks for. */
    private const int MAX_RETRIES = 10;

    private const int MAX_NESTING = 16;

    /** @var array<string, string> reserved option => the builder that owns it */
    private const array RESERVED_OPTIONS = [
        'base_uri' => 'withBaseUrl()',
        'max_redirects' => 'nothing: redirects are never followed',
        'max_retries' => 'withRetries()',
        'retry_failed' => 'withRetries()',
        'retry_strategy' => 'withRetries()',
        'max_duration' => 'withTimeout()',
        'auth_basic' => 'withBasicAuth()',
        'auth_bearer' => 'withToken()',
        'on_progress' => 'withMaxResponseBytes(), which owns the transport\'s progress hook',
        'buffer' => 'withMaxResponseBytes(), which owns how much of a response is held',
    ];

    private const array SUPPORTED_OPTIONS = ['headers', 'query', 'json', 'body', 'timeout'];

    /**
     * @var array<string, string> header this client owns => why it is
     *     not a caller's to set
     */
    private const array OWNED_HEADERS = [
        'accept-encoding' => 'this client asks for identity encoding, which is what makes the response-byte '
            . 'ceiling a bound on memory rather than on compressed bytes',
        'proxy-authorization' => 'a proxy credential is addressed to a proxy rather than to the request\'s own '
            . 'origin, so the base URI cannot confine it; configure the proxy on a transport of your own',
    ];

    /**
     * The transport {@see Http} will put its guarantees in front of. Any
     * Symfony client is accepted but one: a client that retries on its
     * own turns this package's single bounded retry loop into two nested
     * ones, whose attempt count is the product of the two and whose
     * inner attempts spend the outer deadline without being counted
     * against it.
     *
     * A decorator this check cannot see through — one wrapping a
     * retrying client, or one implementing retries itself — is the
     * caller's own contract to keep; see {@see Http}'s note on what an
     * injected transport promises.
     */
    public static function transport(?HttpClientInterface $transport): ?HttpClientInterface
    {
        if ($transport !== null && is_a($transport, self::RETRYING_DECORATOR)) {
            throw HttpRequestException::invalidConfiguration(
                'a transport that retries on its own would stack a second retry layer under this client\'s own, '
                    . 'multiplying the attempts and spending the total timeout outside it; inject the undecorated '
                    . 'client and configure retries with withRetries().',
            );
        }

        return $transport;
    }

    /**
     * An absolute canonical http(s) base URI: a scheme, a host, an
     * optional non-default port, and a path. Userinfo, a query string,
     * and a fragment are rejected rather than dropped, because each one
     * changes where credentials would be sent or what a joined URL
     * means.
     *
     * The path is normalized to start and end with exactly one "/", so
     * the joining rule in {@see target()} is a concatenation: a base
     * path is a prefix that a relative target extends, and is never
     * replaced by one.
     */
    public static function baseUri(#[SensitiveParameter] string $baseUrl): BaseUri
    {
        self::assertUrlBytes($baseUrl, 'a base URI', HttpFailure::InvalidConfiguration);

        $parts = parse_url($baseUrl);

        if ($parts === false) {
            throw HttpRequestException::invalidConfiguration('a base URI must be a parseable absolute URI.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw HttpRequestException::invalidConfiguration(
                'a base URI must carry no query string and no fragment; pass query parameters as an array instead.',
            );
        }

        return new BaseUri(
            self::origin($parts, 'a base URI', HttpFailure::InvalidConfiguration),
            self::basePath($parts['path'] ?? '', 'a base URI'),
        );
    }

    /**
     * Resolves one request URL. With a base URI configured, the target
     * must be a relative reference, so every request a credential-
     * carrying client makes lands on the base's own origin and cannot be
     * redirected to another by a call site. With no base URI, the target
     * must itself be an absolute http(s) URL.
     *
     * Either way the URL carries no userinfo and no fragment, and any
     * "." or ".." segment — written plainly or percent-encoded — is
     * rejected rather than resolved: the URL that goes on the wire is
     * the URL that was written.
     */
    public static function target(#[SensitiveParameter] ?BaseUri $base, #[SensitiveParameter] string $url): RequestTarget
    {
        self::assertUrlBytes($url, 'a request URL', HttpFailure::InvalidRequest);

        if (str_contains($url, '#')) {
            throw HttpRequestException::invalidRequest('a request URL must carry no fragment.');
        }

        return $base === null ? self::absoluteTarget($url) : self::relativeTarget($base, $url);
    }

    /** An HTTP method: a token, and uppercase, the way every registered method is written. */
    public static function method(string $method): string
    {
        if (preg_match(self::TOKEN, $method) !== 1 || $method !== strtoupper($method)) {
            throw HttpRequestException::invalidRequest('an HTTP method must be an uppercase token, such as GET or POST.');
        }

        return $method;
    }

    /** An authentication scheme name: a token, such as Bearer or DPoP. */
    public static function authScheme(string $scheme): string
    {
        if (preg_match(self::TOKEN, $scheme) !== 1) {
            throw HttpRequestException::invalidConfiguration('an authentication scheme must be an HTTP token, such as Bearer.');
        }

        return $scheme;
    }

    /**
     * A credential that is about to become part of an Authorization
     * header: present, not blank, and free of anything that would end
     * the header line.
     */
    public static function credential(#[SensitiveParameter] string $value, string $label): string
    {
        if (trim($value) === '') {
            throw HttpRequestException::invalidConfiguration("{$label} must not be blank.");
        }

        if (preg_match(self::ILLEGAL_IN_VALUE, $value) === 1) {
            throw HttpRequestException::invalidConfiguration("{$label} must contain no control characters.");
        }

        return $value;
    }

    /** A basic-auth user id, which RFC 7617 forbids a colon in. */
    public static function basicUserId(#[SensitiveParameter] string $userId): string
    {
        if (str_contains($userId, ':')) {
            throw HttpRequestException::invalidConfiguration('a basic-auth user id must contain no colon.');
        }

        return self::credential($userId, 'a basic-auth user id');
    }

    /** The total budget for an operation: finite and greater than zero. */
    public static function timeout(float $seconds): float
    {
        if (!is_finite($seconds) || $seconds <= 0.0) {
            throw HttpRequestException::invalidConfiguration('a timeout must be a finite number of seconds greater than zero.');
        }

        return $seconds;
    }

    /** The ceiling one response body may reach: a positive count of bytes. */
    public static function responseByteCeiling(int $bytes): int
    {
        if ($bytes < 1) {
            throw HttpRequestException::invalidConfiguration(
                'a response byte ceiling must be a positive number of bytes.',
            );
        }

        return $bytes;
    }

    /** Retries beyond the first attempt: zero or more, and bounded. */
    public static function retries(int $times): int
    {
        if ($times < 0 || $times > self::MAX_RETRIES) {
            throw HttpRequestException::invalidConfiguration(
                'a retry count must be between 0 and ' . self::MAX_RETRIES . '.',
            );
        }

        return $times;
    }

    /**
     * Header input in exactly the two documented forms: associative
     * `'Name' => 'value'` or `'Name' => ['v1', 'v2']`, and raw
     * `"Name: value"` lines under integer keys. A value is a string or a
     * non-empty list of strings — a Stringable, a resource, an iterator,
     * a number, a boolean, and null are all rejected rather than cast,
     * because what a cast produces is not what the caller wrote.
     *
     * One array uses one form throughout, and a name appears in it once.
     * A mixed array reads as two different notations for one thing, and
     * two spellings of one field name carry no order that HTTP itself
     * would honour, so either is rejected instead of a form or a
     * spelling being picked. Merging *between* arrays is where
     * precedence lives: see {@see Http::withHeaders()}.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, array{name: string, values: non-empty-list<string>}>
     */
    public static function headers(#[SensitiveParameter] array $headers): array
    {
        $resolved = [];
        $rawForm = null;

        foreach ($headers as $key => $value) {
            $isRawLine = is_int($key);

            if ($rawForm !== null && $rawForm !== $isRawLine) {
                throw HttpRequestException::invalidRequest(
                    'one array of headers uses one form throughout: either "Name" => value entries or raw '
                        . '"Name: value" lines, not both. Write the array in whichever form you prefer.',
                );
            }

            $rawForm = $isRawLine;

            [$name, $values] = $isRawLine ? self::rawHeaderLine($value) : self::headerPair($key, $value);

            $lowercaseName = strtolower($name);

            if (isset(self::OWNED_HEADERS[$lowercaseName])) {
                throw HttpRequestException::invalidRequest(sprintf(
                    'the header "%s" is not this client\'s to be given: %s.',
                    $lowercaseName,
                    self::OWNED_HEADERS[$lowercaseName],
                ));
            }

            if (isset($resolved[$lowercaseName])) {
                throw HttpRequestException::invalidRequest(sprintf(
                    'the header "%s" is given more than once in one array; HTTP field names are case-insensitive, '
                        . 'so give a name once, with a list of strings when it carries several values.',
                    $lowercaseName,
                ));
            }

            $resolved[$lowercaseName] = ['name' => $name, 'values' => $values];
        }

        return $resolved;
    }

    /**
     * Query parameters: string keys, and values that are strings,
     * integers, floats, booleans, or nested arrays of those. Null is
     * rejected because URL encoding drops it, so the parameter a caller
     * wrote would silently not be sent. Objects are rejected rather than
     * cast.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    public static function query(#[SensitiveParameter] array $query): array
    {
        foreach ($query as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw HttpRequestException::invalidRequest('a query parameter name must be a non-empty string.');
            }

            self::assertEncodableValue($value, 'a query parameter value', allowNull: false, depth: 0);
        }

        /** @var array<string, mixed> $query */
        return $query;
    }

    /**
     * A body given as an array: scalars and nested arrays. Null is
     * accepted for JSON, which has a null of its own, and rejected for
     * form encoding, which drops it. A non-finite float is rejected in
     * both, having no representation in either.
     *
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     */
    public static function bodyArray(#[SensitiveParameter] array $body, bool $asForm): array
    {
        foreach ($body as $value) {
            self::assertEncodableValue($value, 'a body value', allowNull: !$asForm, depth: 0);
        }

        return $body;
    }

    /**
     * Per-call options, as an exact map: string keys only, drawn from
     * the supported set, each with its own checked shape. An option this
     * package owns is named as reserved and points at the builder that
     * sets it, so a per-call retry, redirect, size, or base-URI setting
     * can never sit alongside — and disagree with — the client's own
     * policy. An option outside the set is rejected rather than
     * forwarded, since an option this boundary cannot check is an option
     * it cannot make any promise about.
     *
     * @param array<array-key, mixed> $options
     * @return array{headers?: array<array-key, mixed>, query?: array<array-key, mixed>, json?: array<array-key, mixed>, body?: mixed, timeout?: float}
     */
    public static function callOptions(#[SensitiveParameter] array $options): array
    {
        foreach ($options as $key => $_) {
            if (!is_string($key)) {
                throw HttpRequestException::invalidRequest('a per-call option name must be a string.');
            }

            if (isset(self::RESERVED_OPTIONS[$key])) {
                throw HttpRequestException::invalidRequest(sprintf(
                    'the option "%s" belongs to this client, not to one call; set it with %s.',
                    $key,
                    self::RESERVED_OPTIONS[$key],
                ));
            }

            if (!in_array($key, self::SUPPORTED_OPTIONS, true)) {
                throw HttpRequestException::invalidRequest(sprintf(
                    'the option "%s" is not one this client can check; the supported options are %s.',
                    self::safeLabel($key),
                    implode(', ', self::SUPPORTED_OPTIONS),
                ));
            }
        }

        if (array_key_exists('json', $options) && array_key_exists('body', $options)) {
            throw HttpRequestException::invalidRequest('a request carries either a "json" option or a "body" option, not both.');
        }

        if (array_key_exists('headers', $options) && !is_array($options['headers'])) {
            throw HttpRequestException::invalidRequest('the "headers" option must be an array.');
        }

        if (array_key_exists('json', $options) && !is_array($options['json'])) {
            throw HttpRequestException::invalidRequest('the "json" option must be an array.');
        }

        if (array_key_exists('query', $options) && !is_array($options['query'])) {
            throw HttpRequestException::invalidRequest('the "query" option must be an array.');
        }

        if (array_key_exists('timeout', $options)) {
            if (!is_float($options['timeout']) && !is_int($options['timeout'])) {
                throw HttpRequestException::invalidRequest('the "timeout" option must be a number of seconds.');
            }

            $options['timeout'] = self::timeout((float) $options['timeout']);
        }

        /** @var array{headers?: array<array-key, mixed>, query?: array<array-key, mixed>, json?: array<array-key, mixed>, body?: mixed, timeout?: float} $options */
        return $options;
    }

    /**
     * A body passed straight through as the "body" option. A string or
     * an array is replayable, so a retry sends the same bytes again. A
     * stream resource or a Closure is read as it is consumed:
     * legitimate for a one-shot upload, and impossible to replay, so
     * {@see Http::withRetries()} rejects it rather than resending a
     * body that is already gone.
     *
     * A resource that is not a stream — a database handle, an image, a
     * closed one — has no bytes to send at all and is rejected as
     * plainly as a value of the wrong type, rather than reaching the
     * transport to fail there.
     */
    public static function rawBody(#[SensitiveParameter] mixed $body, bool $retriesEnabled): mixed
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_array($body)) {
            return self::bodyArray($body, asForm: true);
        }

        if (is_resource($body)) {
            $type = get_resource_type($body);

            if ($type !== 'stream') {
                throw HttpRequestException::invalidRequest(sprintf(
                    'the "body" option takes a stream resource; a %s resource has no bytes to send.',
                    $type,
                ));
            }
        } elseif (!$body instanceof Closure) {
            throw HttpRequestException::invalidRequest(sprintf(
                'the "body" option must be a string, an array, a stream resource, or a Closure; got %s.',
                get_debug_type($body),
            ));
        }

        if ($retriesEnabled) {
            throw HttpRequestException::invalidRequest(
                'a stream or Closure body is read once and cannot be replayed, so it cannot be sent by a client '
                    . 'configured with retries; send it from a client without them.',
            );
        }

        return $body;
    }

    /**
     * Encodes a validated array body, so a failure to encode is this
     * package's own typed failure rather than a vendor exception
     * carrying the values that failed.
     *
     * @param array<array-key, mixed> $body
     */
    public static function encodeJson(#[SensitiveParameter] array $body): string
    {
        try {
            return json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw HttpRequestException::bodyEncodingFailed('JSON');
        }
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function encodeForm(#[SensitiveParameter] array $body): string
    {
        return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The bytes a URL may be written in, before anything tries to read
     * structure out of it. A backslash is refused outright: it is not a
     * URL character, and the readers that accept it read it as "/",
     * which is how `https://api.example.com\@evil.test` reaches one
     * origin while naming another.
     */
    private static function assertUrlBytes(
        #[SensitiveParameter] string $url,
        string $label,
        HttpFailure $category,
    ): void {
        if ($url === '' || preg_match(self::NON_URL_BYTE, $url) === 1) {
            throw self::reject(
                $category,
                "{$label} must be a non-empty string of printable ASCII, with any other byte percent-encoded.",
            );
        }

        if (str_contains($url, '\\')) {
            throw self::reject($category, "{$label} must contain no backslash; the URL path separator is \"/\".");
        }
    }

    private static function absoluteTarget(#[SensitiveParameter] string $url): RequestTarget
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            throw HttpRequestException::invalidRequest(
                'with no base URI configured, a request URL must be an absolute http or https URL.',
            );
        }

        $origin = self::origin($parts, 'a request URL', HttpFailure::InvalidRequest);
        $path = $parts['path'] ?? '';
        $path = $path === '' ? '/' : self::assertNoDotSegments($path, 'a request URL', HttpFailure::InvalidRequest);
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return new RequestTarget($origin . $path . $query, $origin, $query !== '');
    }

    private static function relativeTarget(#[SensitiveParameter] BaseUri $base, #[SensitiveParameter] string $url): RequestTarget
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1 || str_starts_with($url, '//')) {
            throw HttpRequestException::invalidRequest(
                'with a base URI configured, a request URL must be a path relative to it, so that every request '
                    . 'this client makes reaches the base URI\'s own origin.',
            );
        }

        $questionMark = strpos($url, '?');
        $path = $questionMark === false ? $url : substr($url, 0, $questionMark);
        $query = $questionMark === false ? '' : substr($url, $questionMark);

        $path = self::assertNoDotSegments($path, 'a request URL', HttpFailure::InvalidRequest);

        return new RequestTarget($base->origin . $base->path . ltrim($path, '/') . $query, $base->origin, $query !== '');
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function origin(#[SensitiveParameter] array $parts, string $label, HttpFailure $category): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw self::reject($category, "{$label} must use the http or https scheme.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::reject(
                $category,
                "{$label} must carry no userinfo; credentials belong in withToken() or withBasicAuth().",
            );
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw self::reject($category, "{$label} must name a host.");
        }

        if (!self::isCanonicalHost($host)) {
            throw self::reject(
                $category,
                "{$label} must name the host as a canonical DNS name, a dotted-quad IPv4 address, or a "
                    . 'bracketed IPv6 literal, with nothing percent-encoded and no abbreviated numeric form.',
            );
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw self::reject($category, "{$label} must name a port between 1 and 65535.");
        }

        $isDefaultPort = $port === null || ($scheme === 'http' ? $port === 80 : $port === 443);

        return "{$scheme}://{$host}" . ($isDefaultPort ? '' : ":{$port}");
    }

    /**
     * A host in the one spelling that means what it looks like. Three
     * forms are canonical and nothing else is:
     *
     * - a bracketed IPv6 literal, which has to parse as one — `[:::]`
     *   and `[.]` are brackets around something that is not an address;
     * - an address written as a dotted quad, because a resolver reads
     *   `2130706433` and `127.1` as 127.0.0.1 while a reader does not;
     * - a DNS name, whose last label is never numeric, which is what
     *   separates the two cases above from `example.com`.
     */
    private static function isCanonicalHost(#[SensitiveParameter] string $host): bool
    {
        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            return str_starts_with($host, '[')
                && str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        $labels = explode('.', $host);

        if (preg_match(self::NUMERIC_LABEL, $labels[count($labels) - 1]) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return preg_match(self::DNS_NAME, $host) === 1;
    }

    private static function basePath(#[SensitiveParameter] string $path, string $label): string
    {
        if ($path === '') {
            return '/';
        }

        $path = self::assertNoDotSegments($path, $label, HttpFailure::InvalidConfiguration);

        return '/' . trim($path, '/') . '/';
    }

    /**
     * A path whose segments are what they look like. Each segment is
     * percent-decoded first, which settles two things at once:
     *
     * - a decoded segment holding "/" or "\\" carries its own separator,
     *   so the path has more segments than it appears to and the ones
     *   after the escape were never checked — `%2e%2e%2fadmin` is one
     *   segment here and two everywhere it is resolved;
     * - a decoded segment of "." or ".." climbs, whether it was written
     *   plainly or as `%2e%2e`.
     *
     * Both are rejected rather than resolved, so the path that goes on
     * the wire is the path that was written.
     */
    private static function assertNoDotSegments(
        #[SensitiveParameter] string $path,
        string $label,
        HttpFailure $category,
    ): string {
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);

            if (str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                throw self::reject(
                    $category,
                    "{$label} must contain no percent-encoded \"/\" or \"\\\" in a path segment; a separator that "
                        . 'appears only after decoding hides the segments behind it.',
                );
            }

            if ($decoded === '.' || $decoded === '..') {
                throw self::reject(
                    $category,
                    "{$label} must contain no \".\" or \"..\" segment, encoded or not; write the path it resolves to.",
                );
            }
        }

        return $path;
    }

    /**
     * The same rule reads as a configuration problem when it is the
     * client's own base URI and a request problem when it is one call's
     * URL; the check is written once either way.
     */
    private static function reject(HttpFailure $category, string $problem): HttpRequestException
    {
        return $category === HttpFailure::InvalidConfiguration
            ? HttpRequestException::invalidConfiguration($problem)
            : HttpRequestException::invalidRequest($problem);
    }

    /**
     * @return array{0: string, 1: non-empty-list<string>}
     */
    private static function rawHeaderLine(#[SensitiveParameter] mixed $line): array
    {
        if (!is_string($line)) {
            throw HttpRequestException::invalidRequest(sprintf(
                'a header entry under an integer key must be a "Name: value" string; got %s.',
                get_debug_type($line),
            ));
        }

        $colon = strpos($line, ':');

        if ($colon === false) {
            throw HttpRequestException::invalidRequest('a header entry under an integer key must be a "Name: value" string.');
        }

        return [
            self::headerName(trim(substr($line, 0, $colon))),
            [self::headerValue(substr($line, $colon + 1))],
        ];
    }

    /**
     * @return array{0: string, 1: non-empty-list<string>}
     */
    private static function headerPair(#[SensitiveParameter] string $name, #[SensitiveParameter] mixed $value): array
    {
        $name = self::headerName($name);

        if (is_string($value)) {
            return [$name, [self::headerValue($value)]];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw HttpRequestException::invalidRequest(sprintf(
                'a header value must be a string or a list of strings; got %s.',
                get_debug_type($value),
            ));
        }

        if ($value === []) {
            throw HttpRequestException::invalidRequest(
                'a header value list must hold at least one value; drop the name instead of sending it empty.',
            );
        }

        return [$name, array_map(static fn (mixed $one): string => self::headerValue(self::assertHeaderString($one)), $value)];
    }

    private static function assertHeaderString(#[SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) {
            throw HttpRequestException::invalidRequest(sprintf(
                'every value in a header value list must be a string; got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function headerName(#[SensitiveParameter] string $name): string
    {
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw HttpRequestException::invalidRequest(
                'a header name must be an RFC 9110 token: letters, digits, and !#$%&\'*+-.^_`|~, and not empty.',
            );
        }

        return $name;
    }

    private static function headerValue(#[SensitiveParameter] string $value): string
    {
        if (preg_match(self::ILLEGAL_IN_VALUE, $value) === 1) {
            throw HttpRequestException::invalidRequest(
                'a header value must contain no CR, LF, NUL, or other control character.',
            );
        }

        return trim($value, " \t");
    }

    private static function assertEncodableValue(
        #[SensitiveParameter] mixed $value,
        string $label,
        bool $allowNull,
        int $depth,
    ): void {
        if ($depth > self::MAX_NESTING) {
            throw HttpRequestException::invalidRequest("{$label} must not nest more than " . self::MAX_NESTING . ' levels deep.');
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                self::assertEncodableValue($nested, $label, $allowNull, $depth + 1);
            }

            return;
        }

        if ($value === null) {
            if (!$allowNull) {
                throw HttpRequestException::invalidRequest("{$label} must not be null; URL encoding would drop it.");
            }

            return;
        }

        if (is_float($value) && !is_finite($value)) {
            throw HttpRequestException::invalidRequest("{$label} must be a finite number; NAN and INF have no encoding.");
        }

        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw HttpRequestException::invalidRequest(sprintf(
                '%s must be a string, number, boolean, or array; got %s.',
                $label,
                get_debug_type($value),
            ));
        }
    }

    /** Echoes a key back only when it is plainly an option name and not a value. */
    private static function safeLabel(#[SensitiveParameter] string $key): string
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $key) === 1 ? $key : '(omitted)';
    }
}
