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
 * A credential reaches the one origin its base URL names, and no other.
 * The base URL is the whole mechanism: with one set every target is
 * relative to it, a 3xx is never followed, and the validated URL alone
 * decides where the request is routed.
 */
final class HttpCredentialOriginTest extends TestCase
{
    private ScriptedTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new ScriptedTransport([['status' => 200]]);
    }

    /**
     * @return iterable<string, array{0: Closure(Http): Http}>
     */
    public static function credentialProvider(): iterable
    {
        yield 'withToken' => [static fn (Http $h): Http => $h->withToken('secret-key')];
        yield 'withBasicAuth' => [static fn (Http $h): Http => $h->withBasicAuth('alice', 'hunter2')];
        yield 'an Authorization header' => [
            static fn (Http $h): Http => $h->withHeaders(['Authorization' => 'Bearer secret-key']),
        ];
        yield 'a lowercase authorization header' => [
            static fn (Http $h): Http => $h->withHeaders(['authorization' => 'Bearer secret-key']),
        ];
        yield 'a Cookie header' => [static fn (Http $h): Http => $h->withHeaders(['Cookie' => 'session=secret'])];
    }

    /**
     * @param Closure(Http): Http $configure
     */
    #[DataProvider('credentialProvider')]
    public function test_a_credential_without_a_base_url_is_refused_before_any_request(Closure $configure): void
    {
        try {
            $configure(new Http($this->transport))->get('https://api.example.com/orders');
            self::fail('A credential with no base URL was expected to be refused.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
            self::assertStringContainsString('withBaseUrl()', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }

        self::assertSame(0, $this->transport->requests);
    }

    public function test_a_per_call_credential_without_a_base_url_is_refused_too(): void
    {
        $this->expectException(HttpRequestException::class);

        new Http($this->transport)->send('GET', 'https://api.example.com/orders', [
            'headers' => ['Authorization' => 'Bearer secret-key'],
        ]);
    }

    public function test_a_client_carrying_no_credential_still_takes_absolute_urls(): void
    {
        self::assertSame(200, new Http($this->transport)->get('https://api.example.com/orders')->status());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function offOriginTargetProvider(): iterable
    {
        yield 'an absolute URL on another origin' => ['https://evil.test/orders'];
        yield 'an absolute URL on the same origin' => ['https://api.example.com/orders'];
        yield 'a scheme-relative URL' => ['//evil.test/orders'];
        yield 'a scheme downgrade' => ['http://api.example.com/orders'];
    }

    /**
     * With a base URL set, every request URL is relative to it — an
     * absolute one is refused whichever origin it names, so no call site
     * can choose where the credential goes.
     */
    #[DataProvider('offOriginTargetProvider')]
    public function test_a_target_off_the_base_origin_sends_nothing(string $target): void
    {
        try {
            new Http($this->transport)
                ->withBaseUrl('https://api.example.com')
                ->withToken('secret-key')
                ->get($target);
            self::fail('An absolute target under a base URL was expected to be refused.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
        }

        self::assertSame(0, $this->transport->requests);
    }

    public function test_a_redirect_to_another_origin_is_never_followed(): void
    {
        $transport = new ScriptedTransport([[
            'status' => 302,
            'headers' => ['location' => ['https://evil.test/steal']],
        ]]);

        $response = new Http($transport)
            ->withBaseUrl('https://api.example.com')
            ->withToken('secret-key')
            ->get('/orders');

        self::assertTrue($response->redirect());
        self::assertSame('https://evil.test/steal', $response->header('Location'));
        self::assertSame(1, $transport->requests);
        self::assertSame(0, $transport->options[0]['max_redirects']);
    }

    public function test_every_retry_attempt_stays_on_the_base_origin(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 503], ['status' => 200]]);

        new Http($transport)
            ->withBaseUrl('https://api.example.com/v1')
            ->withToken('secret-key')
            ->withRetries(2)
            ->get('/orders')
            ->status();

        self::assertSame(3, $transport->requests);
        self::assertSame(
            ['https://api.example.com/v1/orders'],
            array_values(array_unique($transport->urls)),
        );
    }

    public function test_a_failure_message_names_the_origin_and_nothing_more_of_the_url(): void
    {
        $transport = new ScriptedTransport([['status' => 500]]);

        try {
            new Http($transport)
                ->withBaseUrl('https://api.example.com:8443/v1')
                ->withToken('secret-key')
                ->get('/orders?token=secret-key')
                ->throw();
            self::fail('An error status was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame('GET https://api.example.com:8443 returned HTTP 500.', $e->getMessage());
        }
    }
}
