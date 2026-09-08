<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use SensitiveParameter;

/**
 * The boundary {@see Http} crosses before a transport object exists.
 * Nothing here repairs, casts, or guesses at input, so a rejected
 * request is a request that never happened. What is checked is what
 * {@see Http}'s guarantees rest on: the URL and origin a credential is
 * confined to, the headers this client owns, the bounds an operation
 * runs under, and a closed set of per-call options. Ordinary method,
 * body and query value types are the transport's to validate.
 *
 * Messages are fixed text plus values already proven safe. A rejected
 * value is never quoted back, and every parameter forwarding caller
 * input is `#[\SensitiveParameter]`.
 *
 * @internal
 */
final class Preflight
{
    /** RFC 9110 token: what a header name may contain. */
    private const string TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/';

    /** C0 controls and DEL, minus HTAB, which is legal inside a field value. */
    private const string ILLEGAL_IN_VALUE = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /** A URL is sent as bytes: no spaces, no controls, no unescaped non-ASCII. */
    private const string NON_URL_BYTE = '/[^\x21-\x7E]/';

    /** The subject a URL rejection names, so every message about one URL reads alike. */
    private const string BASE_URL_LABEL = 'A base URL';

    private const string REQUEST_URL_LABEL = 'A request URL';

    /** Retries are bounded by construction, not only by what a caller asks for. */
    private const int MAX_RETRIES = 10;

    private const array SUPPORTED_OPTIONS = ['headers', 'query', 'json', 'body', 'timeout'];

    /** @var array<string, string> header this client owns => why it is not a caller's to set */
    private const array OWNED_HEADERS = [
        'accept-encoding' => 'this client asks for identity encoding, which is what makes the response-byte '
            . 'ceiling a bound on memory',
        'host' => 'the request URL alone names the authority a request is routed to and a credential is confined '
            . 'to; a Host of your own would send this client\'s credentials to one URL while naming a different '
            . 'server to a shared proxy in front of it',
        'proxy-authorization' => 'a proxy credential is addressed to a proxy rather than to the request\'s own '
            . 'origin, so the base URL cannot confine it; configure the proxy on a transport of your own',
    ];

    /**
     * An absolute http(s) base URL, as the origin exceptions may name and
     * the prefix a relative target extends. Userinfo, a query string and
     * a fragment are rejected rather than dropped: each changes where
     * credentials would be sent or what a joined URL means.
     *
     * @return array{0: string, 1: string} the origin, and the prefix
     */
    public static function baseUri(#[SensitiveParameter] string $baseUrl): array
    {
        self::assertUrlBytes($baseUrl, self::BASE_URL_LABEL);

        $parts = parse_url($baseUrl);

        if ($parts === false) {
            throw HttpRequestException::invalidRequest('A base URL must be a parseable absolute URL.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw HttpRequestException::invalidRequest('A base URL must carry no query string and no fragment.');
        }

        $origin = self::origin($parts, self::BASE_URL_LABEL);
        $path = $parts['path'] ?? '';
        $prefix = $path === '' ? '/' : '/' . trim(self::assertNoDotSegments($path, self::BASE_URL_LABEL), '/') . '/';

        return [$origin, $origin . $prefix];
    }

    /**
     * Resolves one request URL: relative under a base URL, so a
     * credential-carrying client lands on the base's own origin, and
     * absolute without one. Either way it carries no userinfo and no
     * fragment, and a "." or ".." segment is rejected rather than
     * resolved.
     *
     * @param array{0: string, 1: string}|null $base origin and prefix from {@see baseUri()}
     * @return array{0: string, 1: string} the URL to send, and its origin
     */
    public static function target(#[SensitiveParameter] ?array $base, #[SensitiveParameter] string $url): array
    {
        self::assertUrlBytes($url, self::REQUEST_URL_LABEL);

        if (str_contains($url, '#')) {
            throw HttpRequestException::invalidRequest('A request URL must carry no fragment.');
        }

        if ($base === null) {
            $parts = parse_url($url);

            if ($parts === false || !isset($parts['scheme'])) {
                throw HttpRequestException::invalidRequest('With no base URL, a request URL must be absolute.');
            }

            $origin = self::origin($parts, self::REQUEST_URL_LABEL);
            $path = $parts['path'] ?? '';
            $path = $path === '' ? '/' : self::assertNoDotSegments($path, self::REQUEST_URL_LABEL);

            return [$origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : ''), $origin];
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1 || str_starts_with($url, '//')) {
            throw HttpRequestException::invalidRequest(
                'With a base URL, a request URL must be a path relative to it, so that every request reaches the '
                    . 'base URL\'s own origin.',
            );
        }

        $questionMark = strpos($url, '?');
        $path = self::assertNoDotSegments($questionMark === false ? $url : substr($url, 0, $questionMark), self::REQUEST_URL_LABEL);

        return [
            $base[1] . ltrim($path, '/') . ($questionMark === false ? '' : substr($url, $questionMark)),
            $base[0],
        ];
    }

    /** The total budget for an operation: finite and greater than zero. */
    public static function timeout(float $seconds): float
    {
        if (!is_finite($seconds) || $seconds <= 0.0) {
            throw HttpRequestException::invalidRequest('A timeout must be a finite number of seconds above zero.');
        }

        return $seconds;
    }

    /** The ceiling one response body may reach: a positive count of bytes. */
    public static function responseByteCeiling(int $bytes): int
    {
        if ($bytes < 1) {
            throw HttpRequestException::invalidRequest('A response byte ceiling must be a positive number of bytes.');
        }

        return $bytes;
    }

    /** Retries beyond the first attempt: zero or more, and bounded. */
    public static function retries(int $times): int
    {
        if ($times < 0 || $times > self::MAX_RETRIES) {
            throw HttpRequestException::invalidRequest('A retry count must be between 0 and ' . self::MAX_RETRIES . '.');
        }

        return $times;
    }

    /**
     * Headers as `'Name' => 'value'` or `'Name' => ['v1', 'v2']`. A name
     * is an RFC 9110 token appearing once per array, case-insensitively;
     * a value is a string or a non-empty list of strings, rejected rather
     * than cast. Precedence between arrays lives in
     * {@see Http::withHeaders()}.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, array{name: string, values: non-empty-list<string>}>
     */
    public static function headers(#[SensitiveParameter] array $headers): array
    {
        $resolved = [];

        foreach ($headers as $key => $value) {
            if (!is_string($key)) {
                throw HttpRequestException::invalidRequest('A header name must be a string.');
            }

            $name = self::headerName($key);
            $lowercaseName = strtolower($name);

            if (isset(self::OWNED_HEADERS[$lowercaseName])) {
                throw HttpRequestException::invalidRequest(
                    "The header \"{$lowercaseName}\" is not this client's to be given: "
                        . self::OWNED_HEADERS[$lowercaseName] . '.',
                );
            }

            if (isset($resolved[$lowercaseName])) {
                throw HttpRequestException::invalidRequest(
                    "The header \"{$lowercaseName}\" is given more than once in one array; HTTP field names are "
                        . 'case-insensitive, so give a name once, with a list of strings for several values.',
                );
            }

            $resolved[$lowercaseName] = ['name' => $name, 'values' => self::headerValues($value)];
        }

        return $resolved;
    }

    /**
     * Per-call options, as an exact map drawn from a closed set. The
     * transport's retry, redirect, duration, credential and buffering
     * options each belong to a builder on the client, and an option this
     * boundary cannot check is one none of {@see Http}'s guarantees would
     * hold for.
     *
     * @param array<array-key, mixed> $options
     * @return array{headers?: array<array-key, mixed>, query?: array<array-key, mixed>, json?: mixed, body?: mixed, timeout?: float}
     */
    public static function callOptions(#[SensitiveParameter] array $options): array
    {
        foreach ($options as $key => $_) {
            if (!is_string($key) || !in_array($key, self::SUPPORTED_OPTIONS, true)) {
                throw HttpRequestException::invalidRequest(
                    'The supported per-call options are ' . implode(', ', self::SUPPORTED_OPTIONS)
                        . '; every other option belongs to a with* method on the client.',
                );
            }
        }

        if (array_key_exists('json', $options) && array_key_exists('body', $options)) {
            throw HttpRequestException::invalidRequest('A request carries a "json" option or a "body" option, not both.');
        }

        foreach (['headers', 'query'] as $key) {
            if (array_key_exists($key, $options) && !is_array($options[$key])) {
                throw HttpRequestException::invalidRequest("The \"{$key}\" option must be an array.");
            }
        }

        if (array_key_exists('timeout', $options)) {
            if (!is_float($options['timeout']) && !is_int($options['timeout'])) {
                throw HttpRequestException::invalidRequest('The "timeout" option must be a number of seconds.');
            }

            $options['timeout'] = self::timeout((float) $options['timeout']);
        }

        /** @var array{headers?: array<array-key, mixed>, query?: array<array-key, mixed>, json?: mixed, body?: mixed, timeout?: float} $options */
        return $options;
    }

    /**
     * A backslash is refused outright: it is not a URL character, and the
     * readers that accept it read it as "/", which is how
     * `https://api.example.com\@evil.test` reaches one origin while
     * naming another.
     */
    private static function assertUrlBytes(#[SensitiveParameter] string $url, string $label): void
    {
        if ($url === '' || preg_match(self::NON_URL_BYTE, $url) === 1) {
            throw HttpRequestException::invalidRequest("{$label} must be printable ASCII, with any other byte percent-encoded.");
        }

        if (str_contains($url, '\\')) {
            throw HttpRequestException::invalidRequest("{$label} must contain no backslash; the path separator is \"/\".");
        }
    }

    /**
     * Scheme, host, and port when it is not the scheme's default — the
     * only part of a URL that ever reaches an exception message.
     *
     * @param array<string, int|string> $parts
     */
    private static function origin(#[SensitiveParameter] array $parts, string $label): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw HttpRequestException::invalidRequest("{$label} must use the http or https scheme.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw HttpRequestException::invalidRequest(
                "{$label} must carry no userinfo; credentials belong in withToken() or withBasicAuth().",
            );
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw HttpRequestException::invalidRequest("{$label} must name a host.");
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $isDefaultPort = $port === null || ($scheme === 'http' ? $port === 80 : $port === 443);

        return "{$scheme}://{$host}" . ($isDefaultPort ? '' : ":{$port}");
    }

    /**
     * A path whose segments are what they look like. Each is
     * percent-decoded first: a decoded "/" or "\\" carries its own
     * separator, hiding the segments behind it, and a decoded "." or
     * ".." climbs however it was written.
     */
    private static function assertNoDotSegments(#[SensitiveParameter] string $path, string $label): string
    {
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);

            if (str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                throw HttpRequestException::invalidRequest(
                    "{$label} must contain no percent-encoded \"/\" or \"\\\" in a path segment; a separator that "
                        . 'appears only after decoding hides the segments behind it.',
                );
            }

            if ($decoded === '.' || $decoded === '..') {
                throw HttpRequestException::invalidRequest(
                    "{$label} must contain no \".\" or \"..\" segment, encoded or not; write the path it resolves to.",
                );
            }
        }

        return $path;
    }

    private static function headerName(#[SensitiveParameter] string $name): string
    {
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw HttpRequestException::invalidRequest(
                'A header name must be an RFC 9110 token: letters, digits, and !#$%&\'*+-.^_`|~, and not empty.',
            );
        }

        return $name;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function headerValues(#[SensitiveParameter] mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        if (!array_is_list($values) || $values === []) {
            throw HttpRequestException::invalidRequest('A header value must be a string or a non-empty list of strings.');
        }

        return array_map(static function (mixed $one): string {
            if (!is_string($one)) {
                throw HttpRequestException::invalidRequest(sprintf(
                    'A header value must be a string or a non-empty list of strings; got %s.',
                    get_debug_type($one),
                ));
            }

            if (preg_match(self::ILLEGAL_IN_VALUE, $one) === 1) {
                throw HttpRequestException::invalidRequest('A header value must contain no CR, LF, NUL, or other control byte.');
            }

            return trim($one, " \t");
        }, $values);
    }
}
