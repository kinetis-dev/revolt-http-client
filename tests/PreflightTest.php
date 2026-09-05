<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use ArrayIterator;
use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Traversable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The validation boundary, asserted through the public API that crosses
 * it. Every case here proves two things at once: the input is refused
 * with the right category, and the transport was never called — a
 * rejected request is a request that never happened, not one that was
 * sent and then complained about.
 */
final class PreflightTest extends TestCase
{
    private const string BASE = 'https://api.example.com';

    /**
     * @return iterable<string, array{call: Closure(Http): mixed, category: HttpFailure, fragment: string}>
     */
    public static function rejectedInputProvider(): iterable
    {
        yield 'a relative base URI' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'a base URI must use the http or https scheme',
        ];

        yield 'a non-HTTP base URI scheme' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('ftp://files.example.com'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must use the http or https scheme',
        ];

        yield 'a base URI carrying userinfo' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://user:pass@api.example.com'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must carry no userinfo',
        ];

        yield 'a base URI carrying a query string' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com/?tenant=acme'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'no query string and no fragment',
        ];

        yield 'a base URI carrying a fragment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com/#section'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'no query string and no fragment',
        ];

        yield 'an empty base URI' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(''),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'non-empty string of printable ASCII',
        ];

        yield 'a base URI with a space in it' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com/a b'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'non-empty string of printable ASCII',
        ];

        yield 'a base URI with a dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com/v1/../v2'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'no "." or ".." segment',
        ];

        yield 'a base URI with a backslash in the authority' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com\\@evil.test'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must contain no backslash',
        ];

        yield 'a base URI whose host is percent-encoded' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api%2eexample%2ecom/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose host has an empty label' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api..example.com'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose encoded authority hides the real host' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com%2F@evil.test/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must carry no userinfo',
        ];

        yield 'a base URI naming a port outside the valid range' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com:0'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'port between 1 and 65535',
        ];

        yield 'a base URI with a percent-encoded dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://api.example.com/v1/%2e%2e/v2'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'no "." or ".." segment, encoded or not',
        ];

        yield 'a request URL with a backslash in it' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get('/orders\\@evil.test'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must contain no backslash',
        ];

        yield 'a base URI whose host is an IPv6 literal that is not an address' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://[:::]/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose host is a bracketed dot' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://[.]/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose host is an IPv4 address written as one integer' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://2130706433/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose host is an abbreviated IPv4 address' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://127.1/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a base URI whose host is a hexadecimal IPv4 address' => [
            'call' => static fn (Http $http) => $http->withBaseUrl('https://0x7f.0x1/v1'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'canonical DNS name',
        ];

        yield 'a request URL with a percent-encoded separator hiding a dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE . '/v1')->get('/%2e%2e%2fadmin'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no percent-encoded "/" or "\\" in a path segment',
        ];

        yield 'a request URL with a percent-encoded backslash hiding a dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE . '/v1')->get('/%2e%2e%5cadmin'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no percent-encoded "/" or "\\" in a path segment',
        ];

        yield 'a base URI with a percent-encoded separator in its path' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE . '/v1%2fadmin'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'no percent-encoded "/" or "\\" in a path segment',
        ];

        yield 'a request URL with a percent-encoded dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE . '/v1')->get('/%2E%2E/admin'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no "." or ".." segment, encoded or not',
        ];

        yield 'an absolute request URL whose host is percent-encoded' => [
            'call' => static fn (Http $http) => $http->get('https://api%2eexample%2ecom/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'canonical DNS name',
        ];

        yield 'an absolute URL against a configured base URI' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get('https://other.example.com/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a path relative to it',
        ];

        yield 'a protocol-relative URL against a configured base URI' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get('//other.example.com/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a path relative to it',
        ];

        yield 'a relative URL with no base URI configured' => [
            'call' => static fn (Http $http) => $http->get('/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an absolute http or https URL',
        ];

        yield 'a request URL carrying userinfo' => [
            'call' => static fn (Http $http) => $http->get('https://user:pass@api.example.com/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must carry no userinfo',
        ];

        yield 'a request URL carrying a fragment' => [
            'call' => static fn (Http $http) => $http->get('https://api.example.com/orders#top'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must carry no fragment',
        ];

        yield 'a request URL with a dot segment' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get('/v1/../admin'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no "." or ".." segment',
        ];

        yield 'a request URL with a raw newline in it' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get("/orders\nX-Injected: 1"),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'non-empty string of printable ASCII',
        ];

        yield 'a request URL with a NUL byte in it' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get("/orders\0"),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'non-empty string of printable ASCII',
        ];

        yield 'a query string in the URL alongside a query array' => [
            'call' => static fn (Http $http) => $http->withBaseUrl(self::BASE)->get('/orders?page=1', ['status' => 'open']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'have no defined order',
        ];

        yield 'a lowercase HTTP method' => [
            'call' => static fn (Http $http) => $http->send('get', self::BASE . '/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an uppercase token',
        ];

        yield 'a method that is not a token' => [
            'call' => static fn (Http $http) => $http->send('GET ORDERS', self::BASE . '/orders'),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an uppercase token',
        ];

        yield 'an authentication scheme that is not a token' => [
            'call' => static fn (Http $http) => $http->withToken('t', 'Bearer token'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must be an HTTP token',
        ];

        yield 'a blank token' => [
            'call' => static fn (Http $http) => $http->withToken('   '),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'an authentication token must not be blank',
        ];

        yield 'a token carrying a newline' => [
            'call' => static fn (Http $http) => $http->withToken("abc\r\nX-Injected: 1"),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must contain no control characters',
        ];

        yield 'a blank basic-auth password' => [
            'call' => static fn (Http $http) => $http->withBasicAuth('user', ''),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'a basic-auth password must not be blank',
        ];

        yield 'a basic-auth user id containing a colon' => [
            'call' => static fn (Http $http) => $http->withBasicAuth('user:name', 'pass'),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must contain no colon',
        ];

        yield 'a zero timeout' => [
            'call' => static fn (Http $http) => $http->withTimeout(0.0),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'finite number of seconds greater than zero',
        ];

        yield 'a negative timeout' => [
            'call' => static fn (Http $http) => $http->withTimeout(-1.0),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'finite number of seconds greater than zero',
        ];

        yield 'a NAN timeout' => [
            'call' => static fn (Http $http) => $http->withTimeout(NAN),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'finite number of seconds greater than zero',
        ];

        yield 'an INF timeout' => [
            'call' => static fn (Http $http) => $http->withTimeout(INF),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'finite number of seconds greater than zero',
        ];

        yield 'a negative retry count' => [
            'call' => static fn (Http $http) => $http->withRetries(-1),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must be between 0 and 10',
        ];

        yield 'an unbounded retry count' => [
            'call' => static fn (Http $http) => $http->withRetries(11),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'must be between 0 and 10',
        ];

        yield 'a header name that is not a token' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X Tenant' => 'acme']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an RFC 9110 token',
        ];

        yield 'an empty header name' => [
            'call' => static fn (Http $http) => $http->withHeaders(['' => 'acme']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an RFC 9110 token',
        ];

        yield 'a header value carrying CR LF' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => "acme\r\nX-Injected: 1"]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no CR, LF, NUL, or other control character',
        ];

        yield 'a header value carrying a NUL byte' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => "ac\0me"]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'no CR, LF, NUL, or other control character',
        ];

        yield 'an empty header value list' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => []]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must hold at least one value',
        ];

        yield 'an associative array as a header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => ['first' => 'acme']]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'an integer header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => 42]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'a null header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => null]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'a Stringable header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => new class implements Stringable {
                public function __toString(): string
                {
                    return 'acme';
                }
            }]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'a Stringable header value whose __toString would throw' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => new class implements Stringable {
                public function __toString(): string
                {
                    throw new RuntimeException('a cast this client never performs');
                }
            }]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'an iterable query value whose iteration would throw' => [
            'call' => static fn (Http $http) => $http->withQuery(['tenant' => new class implements IteratorAggregate {
                public function getIterator(): Traversable
                {
                    throw new RuntimeException('an iteration this client never performs');
                }
            }]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string, number, boolean, or array',
        ];

        yield 'a resource header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => fopen('php://memory', 'r')]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'an iterator header value' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => new ArrayIterator(['acme'])]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string or a list of strings',
        ];

        yield 'a non-string inside a header value list' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => ['acme', 42]]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'every value in a header value list must be a string',
        ];

        yield 'two casings of one header name in one array' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => 'first', 'x-tenant' => 'second']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'is given more than once in one array',
        ];

        yield 'two raw lines for one header name in one array' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant: first', 'X-Tenant: second']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'is given more than once in one array',
        ];

        yield 'an array mixing the associative and raw header forms' => [
            'call' => static fn (Http $http) => $http->withHeaders(['X-Tenant' => 'first', 0 => 'X-Request-Id: 0f9b']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'uses one form throughout',
        ];

        yield 'a raw header line with no colon' => [
            'call' => static fn (Http $http) => $http->withHeaders(['NoColonHere']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a "Name: value" string',
        ];

        yield 'a raw header line with an empty name' => [
            'call' => static fn (Http $http) => $http->withHeaders([': value']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be an RFC 9110 token',
        ];

        yield 'a non-string raw header entry' => [
            'call' => static fn (Http $http) => $http->withHeaders([42]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a "Name: value" string; got int',
        ];

        yield 'an integer query parameter name' => [
            'call' => static fn (Http $http) => $http->withQuery(['acme']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'a query parameter name must be a non-empty string',
        ];

        yield 'a null query parameter value' => [
            'call' => static fn (Http $http) => $http->withQuery(['tenant' => null]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must not be null',
        ];

        yield 'an object query parameter value' => [
            'call' => static fn (Http $http) => $http->withQuery(['tenant' => new ArrayIterator([])]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string, number, boolean, or array',
        ];

        yield 'a NAN query parameter value' => [
            'call' => static fn (Http $http) => $http->withQuery(['ratio' => NAN]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a finite number',
        ];

        yield 'an INF value nested in a body' => [
            'call' => static fn (Http $http) => $http->post(self::BASE . '/orders', ['totals' => ['sum' => INF]]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a finite number',
        ];

        yield 'an object in a body' => [
            'call' => static fn (Http $http) => $http->post(self::BASE . '/orders', ['at' => new ArrayIterator([])]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string, number, boolean, or array',
        ];

        yield 'a resource in a body' => [
            'call' => static fn (Http $http) => $http->post(self::BASE . '/orders', ['file' => fopen('php://memory', 'r')]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string, number, boolean, or array',
        ];

        yield 'a null in a form body, which URL encoding would drop' => [
            'call' => static fn (Http $http) => $http->asForm()->post(self::BASE . '/token', ['scope' => null]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must not be null',
        ];

        yield 'an integer-keyed per-call option' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['headers']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'a per-call option name must be a string',
        ];

        yield 'a per-call base URI' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['base_uri' => 'https://x.test']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withBaseUrl()',
        ];

        yield 'a per-call redirect budget' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['max_redirects' => 5]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'nothing: redirects are never followed',
        ];

        yield 'a per-call retry count' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['max_retries' => 5]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withRetries()',
        ];

        yield 'a per-call retry strategy' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['retry_strategy' => 'x']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withRetries()',
        ];

        yield 'a per-call max duration' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['max_duration' => 5]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withTimeout()',
        ];

        yield 'per-call basic credentials' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['auth_basic' => ['u', 'p']]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withBasicAuth()',
        ];

        yield 'a per-call bearer token' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['auth_bearer' => 't']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withToken()',
        ];

        yield 'an option this client cannot check' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['proxy' => 'http://proxy.test']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'the option "proxy" is not one this client can check',
        ];

        yield 'a json body and a raw body together' => [
            'call' => static fn (Http $http) => $http->send('POST', self::BASE . '/orders', ['json' => ['a' => 1], 'body' => 'raw']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'not both',
        ];

        yield 'a non-array headers option' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['headers' => 'X-Tenant: acme']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'the "headers" option must be an array',
        ];

        yield 'a non-array query option' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['query' => 'page=1']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'the "query" option must be an array',
        ];

        yield 'a non-array json option' => [
            'call' => static fn (Http $http) => $http->send('POST', self::BASE . '/orders', ['json' => 'raw']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'the "json" option must be an array',
        ];

        yield 'a non-numeric per-call timeout' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['timeout' => 'soon']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a number of seconds',
        ];

        yield 'a non-finite per-call timeout' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['timeout' => NAN]),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'finite number of seconds greater than zero',
        ];

        yield 'a Proxy-Authorization header, which no base URI can confine' => [
            'call' => static fn (Http $http) => $http->withHeaders(['Proxy-Authorization' => 'Basic abc']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'a proxy credential is addressed to a proxy',
        ];

        yield 'an Accept-Encoding header, which the response ceiling owns' => [
            'call' => static fn (Http $http) => $http->withHeaders(['Accept-Encoding' => 'gzip']),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'this client asks for identity encoding',
        ];

        yield 'a per-call Accept-Encoding header' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', [
                'headers' => ['accept-encoding' => 'gzip, deflate'],
            ]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'this client asks for identity encoding',
        ];

        yield 'a per-call progress hook' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', [
                'on_progress' => static fn (): null => null,
            ]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withMaxResponseBytes()',
        ];

        yield 'a per-call buffering setting' => [
            'call' => static fn (Http $http) => $http->send('GET', self::BASE . '/orders', ['buffer' => false]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'set it with withMaxResponseBytes()',
        ];

        yield 'a zero response byte ceiling' => [
            'call' => static fn (Http $http) => $http->withMaxResponseBytes(0),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'positive number of bytes',
        ];

        yield 'a negative response byte ceiling' => [
            'call' => static fn (Http $http) => $http->withMaxResponseBytes(-1),
            'category' => HttpFailure::InvalidConfiguration,
            'fragment' => 'positive number of bytes',
        ];

        yield 'a resource body that is not a stream' => [
            'call' => static fn (Http $http) => $http->send('POST', self::BASE . '/orders', [
                'body' => stream_context_create(),
            ]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'a stream-context resource has no bytes to send',
        ];

        yield 'a body of a type this client cannot send' => [
            'call' => static fn (Http $http) => $http->send('POST', self::BASE . '/orders', ['body' => 42]),
            'category' => HttpFailure::InvalidRequest,
            'fragment' => 'must be a string, an array, a stream resource, or a Closure',
        ];
    }

    /**
     * @param Closure(Http): mixed $call
     */
    #[DataProvider('rejectedInputProvider')]
    public function test_invalid_input_is_rejected_and_reaches_no_transport(
        Closure $call,
        HttpFailure $category,
        string $fragment,
    ): void {
        $transport = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));

        try {
            $call(new Http($transport));

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame($category, $e->category);
            self::assertStringContainsString($fragment, $e->getMessage());
            self::assertSame(0, $e->status);
        }

        self::assertSame(0, $transport->getRequestsCount());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function canonicalHostProvider(): iterable
    {
        yield 'a DNS name' => ['https://api.example.com', 'https://api.example.com/orders'];
        yield 'a single-label DNS name' => ['http://localhost:8099', 'http://localhost:8099/orders'];
        yield 'a DNS name with a digit-led label' => ['https://0api.example.com', 'https://0api.example.com/orders'];
        yield 'a dotted-quad IPv4 address' => ['http://127.0.0.1:8099', 'http://127.0.0.1:8099/orders'];
        yield 'a bracketed IPv6 literal' => ['http://[::1]:8099', 'http://[::1]:8099/orders'];
    }

    /**
     * The three host forms that mean exactly what they read as are the
     * three that go through — the counterpart to every abbreviated,
     * numeric and malformed spelling the provider above refuses.
     */
    #[DataProvider('canonicalHostProvider')]
    public function test_a_canonical_host_reaches_the_wire_unchanged(string $base, string $expected): void
    {
        self::assertSame($expected, $this->urlSentBy(static fn (Http $http) => $http->withBaseUrl($base)->get('/orders')));
    }

    /**
     * @return iterable<string, array{base: string, expected: string}>
     */
    public static function canonicalBaseUriProvider(): iterable
    {
        yield 'uppercase scheme and host' => ['base' => 'HTTPS://API.Example.COM', 'expected' => 'https://api.example.com/orders'];
        yield 'default https port' => ['base' => 'https://api.example.com:443', 'expected' => 'https://api.example.com/orders'];
        yield 'default http port' => ['base' => 'http://api.example.com:80', 'expected' => 'http://api.example.com/orders'];
        yield 'non-default port' => ['base' => 'http://api.example.com:8080', 'expected' => 'http://api.example.com:8080/orders'];
    }

    #[DataProvider('canonicalBaseUriProvider')]
    public function test_a_base_uri_is_canonicalized_before_anything_is_sent(string $base, string $expected): void
    {
        self::assertSame($expected, $this->urlSentBy(static fn (Http $http) => $http->withBaseUrl($base)->get('/orders')));
    }

    /**
     * An absolute URL is the form a client with no base URI takes, and
     * its own query string is passed through untouched — a signed URL's
     * parameters keep the exact order and encoding they were signed in.
     */
    public function test_an_absolute_url_keeps_its_own_query_string_verbatim(): void
    {
        $signed = 'https://api.example.com/events?b=2&a=1&sig=Zm9v%2Fdw';

        self::assertSame($signed, $this->urlSentBy(static fn (Http $http) => $http->send('POST', $signed, ['body' => 'x'])));
    }

    /**
     * @param Closure(Http): mixed $call
     */
    private function urlSentBy(Closure $call): string
    {
        $sent = '';
        $transport = new MockHttpClient(static function (string $method, string $url) use (&$sent): MockResponse {
            $sent = $url;

            return new MockResponse('{}');
        });

        $call(new Http($transport));

        return $sent;
    }
}
