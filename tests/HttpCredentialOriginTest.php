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
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * A credential goes to one origin and no other. Every case here plants a
 * recognizable credential and then proves, from the transport's own
 * record of what it was asked to send, that no request left for anywhere
 * but the origin the client was configured for — usually because no
 * request left at all.
 */
final class HttpCredentialOriginTest extends TestCase
{
    private const string BASE = 'https://api.example.com';
    private const string TOKEN = 'SENTINELTOKEN';

    /**
     * @return iterable<string, array{0: Closure(Http): Http}>
     */
    public static function credentialProvider(): iterable
    {
        yield 'a bearer token' => [static fn (Http $http): Http => $http->withToken(self::TOKEN)];
        yield 'basic credentials' => [static fn (Http $http): Http => $http->withBasicAuth('user', self::TOKEN)];
        yield 'an Authorization header' => [
            static fn (Http $http): Http => $http->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN]),
        ];
        yield 'a Cookie header' => [
            static fn (Http $http): Http => $http->withHeaders(['Cookie' => 'session=' . self::TOKEN]),
        ];
    }

    /**
     * With no base URI, a call site chooses the whole URL — which would
     * mean a call site chooses who receives the credential. The client
     * refuses before a transport call rather than trusting the URL it
     * happens to be given.
     *
     * @param Closure(Http): Http $configure
     */
    #[DataProvider('credentialProvider')]
    public function test_a_credential_without_a_base_uri_is_refused_before_any_request(Closure $configure): void
    {
        $transport = new ScriptedTransport([[]]);

        try {
            $configure(new Http($transport))->get('https://elsewhere.test/orders');

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidConfiguration, $e->category);
            self::assertStringContainsString('needs withBaseUrl()', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertSame(0, $transport->requests);
    }

    /**
     * A proxy credential is addressed to the proxy in front of the
     * request, not to the origin behind it, so pinning it to a base URI
     * would confine it to something it is not sent to. It is refused at
     * this boundary instead — a proxy that needs credentials is a
     * transport of the caller's own.
     */
    public function test_a_proxy_credential_is_refused_rather_than_pinned(): void
    {
        $transport = new ScriptedTransport([[]]);

        foreach ([static fn (Http $http): Http => $http->withBaseUrl(self::BASE), static fn (Http $http): Http => $http] as $configure) {
            try {
                $configure(new Http($transport))->withHeaders(['Proxy-Authorization' => 'Basic ' . self::TOKEN]);

                self::fail('Expected HttpRequestException.');
            } catch (HttpRequestException $e) {
                self::assertSame(HttpFailure::InvalidRequest, $e->category);
                self::assertStringContainsString('addressed to a proxy', $e->getMessage());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }

        self::assertSame(0, $transport->requests);
    }

    /** The same rule holds for a credential given to one call rather than to the client. */
    public function test_a_per_call_credential_without_a_base_uri_is_refused_too(): void
    {
        $transport = new ScriptedTransport([[]]);

        try {
            new Http($transport)->send('GET', 'https://elsewhere.test/orders', [
                'headers' => ['Authorization' => 'Bearer ' . self::TOKEN],
            ]);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidConfiguration, $e->category);
        }

        self::assertSame(0, $transport->requests);
    }

    /**
     * A client carrying no credential has nothing to pin, so absolute
     * URLs stay the ordinary way to use one.
     */
    public function test_a_client_carrying_no_credential_still_takes_absolute_urls(): void
    {
        $transport = new ScriptedTransport([[]]);

        new Http($transport)->withHeaders(['X-Tenant' => 'acme'])->get('https://elsewhere.test/orders');

        self::assertSame(['https://elsewhere.test/orders'], $transport->urls);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function offOriginTargetProvider(): iterable
    {
        yield 'an absolute URL on another origin' => ['https://elsewhere.test/orders'];
        yield 'an absolute URL on the same host, another scheme' => ['http://api.example.com/orders'];
        yield 'a network-path reference' => ['//elsewhere.test/orders'];
        yield 'a backslash network-path reference' => ['\\\\elsewhere.test\\orders'];
        yield 'an encoded dot-segment climbing out of the base path' => ['/v1/%2e%2e/%2e%2e/orders'];
    }

    /**
     * Cross-origin, scheme-downgrading, network-path and dot-segment
     * targets are all the same mistake — a request leaving the origin
     * the credential was issued for — and all are refused with the
     * credential still in the client and nothing on the wire.
     */
    #[DataProvider('offOriginTargetProvider')]
    public function test_a_target_off_the_base_origin_sends_nothing(string $target): void
    {
        $transport = new ScriptedTransport([[]]);

        try {
            new Http($transport)->withBaseUrl(self::BASE . '/v1')->withToken(self::TOKEN)->get($target);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        self::assertSame(0, $transport->requests);
    }

    /**
     * A 3xx naming another origin is answered, not acted on: one request
     * to the base origin, `max_redirects` at zero, and the credential
     * still only ever sent to the host it was configured for.
     */
    public function test_a_redirect_to_another_origin_is_never_followed(): void
    {
        $transport = new ScriptedTransport([[
            'status' => 302,
            'headers' => ['location' => ['https://elsewhere.test/harvest']],
        ]]);

        $response = new Http($transport)->withBaseUrl(self::BASE)->withToken(self::TOKEN)->get('/orders');

        self::assertSame(302, $response->status());
        self::assertSame('https://elsewhere.test/harvest', $response->header('Location'));
        self::assertSame(['https://api.example.com/orders'], $transport->urls);
        self::assertSame(0, $transport->options[0]['max_redirects']);
        self::assertSame('Bearer ' . self::TOKEN, $transport->options[0]['headers']['Authorization']);
    }

    /**
     * Every attempt of a retrying client is the same pinned request:
     * same origin, same redirect budget, no chance for a later attempt
     * to be aimed somewhere else.
     */
    public function test_every_retry_attempt_stays_on_the_base_origin(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 503], ['status' => 200]]);

        new Http($transport)->withBaseUrl(self::BASE)->withToken(self::TOKEN)->withRetries(2)->get('/orders');

        self::assertSame(
            ['https://api.example.com/orders', 'https://api.example.com/orders', 'https://api.example.com/orders'],
            $transport->urls,
        );
        self::assertSame([0, 0, 0], array_column($transport->options, 'max_redirects'));
    }

    /**
     * A transport that retries on its own would send the pinned request
     * again on terms this client neither set nor counts, so it is
     * refused where it is injected rather than trusted at send time.
     */
    public function test_a_retrying_transport_is_refused_at_construction(): void
    {
        try {
            new Http(new RetryableHttpClient(new ScriptedTransport([[]])));

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidConfiguration, $e->category);
            self::assertStringContainsString('withRetries()', $e->getMessage());
        }
    }
}
