<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `withRedirects()`, against a scripted transport that records the URL,
 * method and options of every hop it is sent.
 */
final class HttpRedirectTest extends TestCase
{
    private const string BASE = 'https://api.example.com/v1';

    public function test_redirects_are_not_followed_by_default(): void
    {
        $transport = $this->redirectingTo('/v1/next');

        self::assertSame(302, new Http($transport)->withBaseUrl(self::BASE)->get('/start')->status());
        self::assertSame(1, $transport->requests);
    }

    /** @return iterable<string, array{int}> */
    public static function methodChangingProvider(): iterable
    {
        yield '301' => [301];
        yield '302' => [302];
        yield '303' => [303];
    }

    #[DataProvider('methodChangingProvider')]
    public function test_a_post_redirected_with_a_method_changing_status_becomes_a_get_without_its_body(int $status): void
    {
        $transport = $this->redirectingTo('/v1/next', $status);

        $response = new Http($transport)
            ->withBaseUrl(self::BASE)
            ->withHeaders(['Content-Type' => 'application/json', 'X-Trace' => 'kept'])
            ->withRedirects()
            ->post('/start', ['order' => 7]);

        self::assertSame(200, $response->status());
        self::assertSame(['POST', 'GET'], $transport->methods);
        self::assertSame('https://api.example.com/v1/next', $transport->urls[1]);
        self::assertArrayHasKey('json', $transport->options[0]);
        self::assertArrayNotHasKey('json', $transport->options[1]);
        self::assertArrayNotHasKey('body', $transport->options[1]);
        self::assertArrayNotHasKey('Content-Type', $transport->options[1]['headers']);
        self::assertSame('kept', $transport->options[1]['headers']['X-Trace']);
        self::assertSame(1, $transport->cancellations(), 'The redirect answer was released, not left open.');
    }

    public function test_a_307_post_is_returned_unfollowed(): void
    {
        $transport = $this->redirectingTo('/v1/next', 307);

        $response = new Http($transport)->withBaseUrl(self::BASE)->withRedirects()->post('/start', ['order' => 7]);

        self::assertSame(307, $response->status());
        self::assertTrue($response->redirect());
        self::assertSame(1, $transport->requests);
    }

    public function test_a_307_get_is_repeated_at_the_new_location(): void
    {
        $transport = $this->redirectingTo('/v1/next', 307);

        $response = new Http($transport)->withBaseUrl(self::BASE)->withRedirects()->get('/start');

        self::assertSame(200, $response->status());
        self::assertSame(['GET', 'GET'], $transport->methods);
        self::assertSame('https://api.example.com/v1/next', $transport->urls[1]);
    }

    public function test_a_308_put_with_a_stream_body_is_returned_unfollowed(): void
    {
        $transport = $this->redirectingTo('/v1/next', 308);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $response = new Http($transport)
            ->withBaseUrl(self::BASE)
            ->withRedirects()
            ->send('PUT', '/start', ['body' => $stream]);

        self::assertSame(308, $response->status());
        self::assertSame(1, $transport->requests);
    }

    public function test_https_to_http_is_returned_unfollowed(): void
    {
        $transport = $this->redirectingTo('http://api.example.com/next');

        $response = new Http($transport)->withRedirects()->get('https://api.example.com/start');

        self::assertSame(302, $response->status());
        self::assertSame(1, $transport->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function outsideTheBaseProvider(): iterable
    {
        yield 'another origin' => ['https://evil.test/v1/steal'];
        yield 'another scheme' => ['http://api.example.com/v1/steal'];
        yield 'another port' => ['https://api.example.com:8443/v1/steal'];
        yield 'protocol-relative' => ['//evil.test/v1/steal'];
        yield 'the same origin outside the base path' => ['/admin'];
        yield 'a dot segment out of the base path' => ['/v1/../admin'];
    }

    #[DataProvider('outsideTheBaseProvider')]
    public function test_a_base_url_client_never_takes_its_credentials_outside_its_base_url(string $location): void
    {
        $transport = $this->redirectingTo($location);

        $response = new Http($transport)
            ->withBaseUrl(self::BASE)
            ->withToken('secret-key')
            ->withHeaders(['Cookie' => 'session=secret'])
            ->withRedirects()
            ->get('/start');

        self::assertSame(302, $response->status());
        self::assertSame(1, $transport->requests);
    }

    public function test_a_redirect_inside_the_base_url_keeps_its_credentials(): void
    {
        $transport = $this->redirectingTo('next?page=2');

        new Http($transport)->withBaseUrl(self::BASE)->withToken('secret-key')->withRedirects()->get('/orders/start');

        self::assertSame('https://api.example.com/v1/orders/next?page=2', $transport->urls[1]);
        self::assertSame('Bearer secret-key', $transport->options[1]['headers']['Authorization']);
    }

    public function test_a_client_without_a_base_url_follows_to_another_origin(): void
    {
        $transport = $this->redirectingTo('https://cdn.example.net/file');

        self::assertSame(200, new Http($transport)->withRedirects()->get('https://api.example.com/start')->status());
        self::assertSame('https://cdn.example.net/file', $transport->urls[1]);
    }

    public function test_allow_is_asked_for_each_target_and_a_refusal_stops_the_chain(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 302, 'headers' => ['location' => ['https://b.example/two']]],
            ['status' => 302, 'headers' => ['location' => ['https://internal.example/three']]],
            ['status' => 200],
        ]);
        $asked = [];

        $response = new Http($transport)
            ->withRedirects(allow: static function (string $url) use (&$asked): bool {
                $asked[] = $url;

                return !str_contains($url, 'internal');
            })
            ->get('https://a.example/one');

        self::assertSame(302, $response->status());
        self::assertSame(['https://b.example/two', 'https://internal.example/three'], $asked);
        self::assertSame(2, $transport->requests);
    }

    public function test_the_redirect_past_the_limit_is_returned(): void
    {
        $transport = $this->redirectingTo('/v1/again', 302, forever: true);

        $response = new Http($transport)->withBaseUrl(self::BASE)->withRedirects(2)->get('/start');

        self::assertSame(302, $response->status());
        self::assertSame(3, $transport->requests);
        self::assertSame(3, $response->attempts());
    }

    public function test_a_limit_below_one_is_refused(): void
    {
        $this->expectException(HttpRequestException::class);

        new Http(new ScriptedTransport([['status' => 200]]))->withRedirects(0);
    }

    /** @return iterable<string, array{array<string, list<string>>}> */
    public static function unusableLocationProvider(): iterable
    {
        yield 'no Location' => [[]];
        yield 'an empty Location' => [['location' => ['']]];
        yield 'a non-http scheme' => [['location' => ['ftp://api.example.com/x']]];
        yield 'userinfo' => [['location' => ['https://user:pass@api.example.com/x']]];
        yield 'unencoded bytes' => [['location' => ["/x y"]]];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    #[DataProvider('unusableLocationProvider')]
    public function test_a_redirect_without_a_usable_location_is_returned(array $headers): void
    {
        $transport = new ScriptedTransport([['status' => 302, 'headers' => $headers], ['status' => 200]]);

        self::assertSame(302, new Http($transport)->withRedirects()->get('https://api.example.com/start')->status());
        self::assertSame(1, $transport->requests);
    }

    /**
     * Each hop takes a little more than a third of the one timeout, so a
     * chain that restarted the deadline per hop would finish; one that
     * shares it runs out on the third hop. Each hop is also handed only
     * what is left.
     */
    public function test_the_whole_chain_runs_inside_one_deadline(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 302, 'headers' => ['location' => ['/v1/two']], 'statusDelay' => 0.2],
            ['status' => 302, 'headers' => ['location' => ['/v1/three']], 'statusDelay' => 0.2],
            ['status' => 200, 'statusDelay' => 0.2],
        ]);

        try {
            new Http($transport)->withBaseUrl(self::BASE)->withTimeout(0.5)->withRedirects()->get('/one')->status();
            self::fail('A chain longer than its one deadline was expected to time out.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('after 3 attempt(s)', $e->getMessage());
        }

        self::assertLessThan($transport->options[0]['timeout'], $transport->options[1]['timeout']);
        self::assertLessThan($transport->options[1]['timeout'], $transport->options[2]['timeout']);
    }

    /** The ceiling of the request is the ceiling of its final answer. */
    public function test_the_final_answer_is_held_to_the_requests_byte_ceiling(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 302, 'headers' => ['location' => ['/v1/two']]],
            ['status' => 200, 'chunks' => ['xxxxxxxx']],
        ]);

        try {
            new Http($transport)->withBaseUrl(self::BASE)->withMaxResponseBytes(4)->withRedirects()->get('/one')->body();
            self::fail('An over-large final body was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    public function test_a_retry_after_a_redirect_draws_on_the_same_attempts(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 302, 'headers' => ['location' => ['/v1/two']]],
            ['status' => 503],
        ]);

        $response = new Http($transport)->withBaseUrl(self::BASE)->withRetries(1)->withRedirects()->get('/one');

        self::assertSame(503, $response->status());
        self::assertSame(2, $transport->requests, 'The hop used the one retry the request had.');
    }

    private function redirectingTo(string $location, int $status = 302, bool $forever = false): ScriptedTransport
    {
        $redirect = ['status' => $status, 'headers' => ['location' => [$location]]];

        return new ScriptedTransport($forever ? [$redirect] : [$redirect, ['status' => 200]]);
    }
}
