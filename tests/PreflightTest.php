<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boundary every public path crosses. One case per rule the package
 * owns: what it refuses, and that a refusal reaches no transport.
 */
final class PreflightTest extends TestCase
{
    private ScriptedTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new ScriptedTransport([['status' => 200]]);
    }

    private function http(): Http
    {
        return new Http($this->transport);
    }

    /**
     * @return iterable<string, array{0: Closure(Http): mixed}>
     */
    public static function refusedInputProvider(): iterable
    {
        yield 'base URL with a non-ASCII byte' => [fn (Http $h) => $h->withBaseUrl("https://api.example.com/\u{00e9}")];
        yield 'base URL with a backslash' => [fn (Http $h) => $h->withBaseUrl('https://api.example.com\\@evil.test')];
        yield 'base URL with an unsupported scheme' => [fn (Http $h) => $h->withBaseUrl('ftp://api.example.com')];
        yield 'base URL with userinfo' => [fn (Http $h) => $h->withBaseUrl('https://user:pass@api.example.com')];
        yield 'base URL with no host' => [fn (Http $h) => $h->withBaseUrl('https:///orders')];
        yield 'base URL with a query string' => [fn (Http $h) => $h->withBaseUrl('https://api.example.com?a=1')];
        yield 'base URL with a fragment' => [fn (Http $h) => $h->withBaseUrl('https://api.example.com#top')];
        yield 'base URL with a dot segment' => [fn (Http $h) => $h->withBaseUrl('https://api.example.com/v1/../v2')];

        yield 'request URL with a fragment' => [fn (Http $h) => $h->get('https://api.example.com/orders#top')];
        yield 'request URL with userinfo' => [fn (Http $h) => $h->get('https://user:pass@api.example.com/orders')];
        yield 'request URL with a backslash' => [fn (Http $h) => $h->get('https://api.example.com\\@evil.test/')];
        yield 'relative URL with no base URL' => [fn (Http $h) => $h->get('/orders')];
        yield 'scheme-relative URL under a base URL' => [
            fn (Http $h) => $h->withBaseUrl('https://api.example.com')->get('//evil.test/orders'),
        ];
        yield 'plain dot segment' => [
            fn (Http $h) => $h->withBaseUrl('https://api.example.com')->get('/v1/../admin'),
        ];
        yield 'percent-encoded dot segment' => [
            fn (Http $h) => $h->withBaseUrl('https://api.example.com')->get('/v1/%2e%2e/admin'),
        ];
        yield 'percent-encoded separator inside a segment' => [
            fn (Http $h) => $h->withBaseUrl('https://api.example.com')->get('/v1/%2e%2e%2fadmin'),
        ];

        yield 'header name that is not a token' => [fn (Http $h) => $h->withHeaders(['X Tenant' => 'acme'])];
        yield 'header name under an integer key' => [fn (Http $h) => $h->withHeaders(['X-Tenant: acme'])];
        yield 'header value carrying CRLF' => [fn (Http $h) => $h->withHeaders(['X-Tenant' => "acme\r\nX-Admin: 1"])];
        yield 'header value that is not a string' => [fn (Http $h) => $h->withHeaders(['X-Tenant' => 42])];
        yield 'empty header value list' => [fn (Http $h) => $h->withHeaders(['X-Tenant' => []])];
        yield 'one name given twice in one array' => [
            fn (Http $h) => $h->withHeaders(['X-Tenant' => 'a', 'x-tenant' => 'b']),
        ];

        yield 'timeout of zero' => [fn (Http $h) => $h->withTimeout(0.0)];
        yield 'timeout that is not finite' => [fn (Http $h) => $h->withTimeout(INF)];
        yield 'response ceiling of zero' => [fn (Http $h) => $h->withMaxResponseBytes(0)];
        yield 'negative retry count' => [fn (Http $h) => $h->withRetries(-1)];
        yield 'retry count above the bound' => [fn (Http $h) => $h->withRetries(11)];

        yield 'unsupported per-call option' => [
            fn (Http $h) => $h->send('GET', 'https://api.example.com/orders', ['verify_peer' => false]),
        ];
        yield 'per-call option this client owns' => [
            fn (Http $h) => $h->send('GET', 'https://api.example.com/orders', ['max_redirects' => 3]),
        ];
        yield 'json and body together' => [
            fn (Http $h) => $h->send('POST', 'https://api.example.com/orders', ['json' => [], 'body' => 'x']),
        ];
        yield 'per-call timeout that is not a number' => [
            fn (Http $h) => $h->send('GET', 'https://api.example.com/orders', ['timeout' => 'soon']),
        ];
        yield 'headers option that is not an array' => [
            fn (Http $h) => $h->send('GET', 'https://api.example.com/orders', ['headers' => 'X-Tenant: acme']),
        ];
    }

    /**
     * @param Closure(Http): mixed $call
     */
    #[DataProvider('refusedInputProvider')]
    public function test_refused_input_reaches_no_transport(Closure $call): void
    {
        try {
            $call($this->http());
            self::fail('The input was expected to be refused.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
        }

        self::assertSame(0, $this->transport->requests);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function ownedHeaderProvider(): iterable
    {
        yield 'Accept-Encoding' => ['Accept-Encoding'];
        yield 'Host' => ['Host'];
        yield 'lowercase host' => ['host'];
        yield 'mixed-case host' => ['HoSt'];
        yield 'Proxy-Authorization' => ['Proxy-Authorization'];
    }

    /**
     * The validated URL alone owns the authority a request is routed to,
     * so a caller cannot name a different one — including through a
     * shared proxy that would route on the header rather than the URL.
     */
    #[DataProvider('ownedHeaderProvider')]
    public function test_a_header_this_client_owns_is_refused(string $name): void
    {
        $this->expectException(HttpRequestException::class);

        $this->http()->withHeaders([$name => 'evil.test']);
    }

    public function test_the_client_sends_identity_encoding_and_forbids_redirects(): void
    {
        $this->http()->get('https://api.example.com/orders')->status();

        self::assertSame('identity', $this->transport->options[0]['headers']['Accept-Encoding']);
        self::assertSame(0, $this->transport->options[0]['max_redirects']);
    }

    public function test_a_url_query_string_is_sent_byte_for_byte(): void
    {
        $this->http()->get('https://api.example.com/orders?sig=a%2Bb&t=1')->status();

        self::assertSame('https://api.example.com/orders?sig=a%2Bb&t=1', $this->transport->urls[0]);
    }

    public function test_a_supported_option_set_is_accepted_whole(): void
    {
        $this->http()->send('POST', 'https://api.example.com/orders', [
            'headers' => ['X-Tenant' => 'acme'],
            'query' => ['trace' => 'on'],
            'json' => ['sku' => 'A1'],
            'timeout' => 5,
        ])->status();

        self::assertSame(1, $this->transport->requests);
        self::assertSame(['sku' => 'A1'], $this->transport->options[0]['json']);
        self::assertSame(['trace' => 'on'], $this->transport->options[0]['query']);
        self::assertSame('acme', $this->transport->options[0]['headers']['X-Tenant']);
    }
}
