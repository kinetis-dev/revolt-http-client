<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\CookieJar;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A jar driven through the client, so every assertion reads the Cookie
 * header the transport was actually handed.
 */
final class CookieJarTest extends TestCase
{
    public function test_a_cookie_belongs_to_the_exact_host_that_set_it_whatever_its_domain_says(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://a.example.com/', 'sid=one; Domain=example.com; Path=/');

        self::assertSame('sid=one', $this->sent($jar, 'https://a.example.com/x'));
        self::assertNull($this->sent($jar, 'https://b.example.com/x'));
        self::assertNull($this->sent($jar, 'https://example.com/x'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function pathProvider(): iterable
    {
        yield 'the path itself' => ['p=1; Path=/docs', '/docs', true];
        yield 'below it' => ['p=1; Path=/docs', '/docs/page', true];
        yield 'a sibling sharing its prefix' => ['p=1; Path=/docs', '/docsx', false];
        yield 'above it' => ['p=1; Path=/docs', '/', false];
        yield 'a path ending in a slash' => ['p=1; Path=/docs/', '/docs/page', true];
        yield 'the default path, below' => ['p=1', '/a/other', true];
        yield 'the default path, above' => ['p=1', '/', false];
        yield 'a relative Path falls back to the default' => ['p=1; Path=docs', '/a/c', true];
    }

    #[DataProvider('pathProvider')]
    public function test_a_cookie_is_sent_where_its_path_matches(string $setCookie, string $path, bool $sent): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/a/b', $setCookie);

        self::assertSame($sent ? 'p=1' : null, $this->sent($jar, 'https://api.example.com' . $path));
    }

    public function test_a_secure_cookie_travels_only_over_https_and_cannot_be_set_over_http(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', 's=1; Secure');
        $this->set($jar, 'http://api.example.com/', 'insecure=1; Secure');
        $this->set($jar, 'http://api.example.com/', 's=overwritten');

        self::assertSame('s=1', $this->sent($jar, 'https://api.example.com/'));
        self::assertNull($this->sent($jar, 'http://api.example.com/'));
    }

    public function test_max_age_and_expires_end_a_cookie(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', 'a=1', 'b=1', 'c=1; Expires=Wed, 01 Jan 2099 00:00:00 GMT', 'd=1; Max-Age=60; Expires=Thu, 01 Jan 1970 00:00:00 GMT');

        self::assertSame('a=1; b=1; c=1; d=1', $this->sent($jar, 'https://api.example.com/'));

        $this->set($jar, 'https://api.example.com/', 'a=1; Max-Age=0', 'b=1; Expires=Thu, 01 Jan 1970 00:00:00 GMT', 'c=1; Max-Age=-1');

        self::assertSame('d=1', $this->sent($jar, 'https://api.example.com/'));
    }

    public function test_a_cookie_with_the_same_name_host_and_path_is_replaced(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', 'sid=old; Path=/', 'sid=other-path; Path=/x');
        $this->set($jar, 'https://api.example.com/', 'sid=new; Path=/');

        self::assertSame('sid=other-path; sid=new', $this->sent($jar, 'https://api.example.com/x'));
    }

    public function test_a_cookie_set_on_a_redirect_hop_is_sent_on_the_next(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 302, 'headers' => ['location' => ['/login/done'], 'set-cookie' => ['sid=hop; Path=/']]],
            ['status' => 200],
        ]);

        new Http($transport)->withCookieJar(new CookieJar())->withRedirects()->get('https://api.example.com/login');

        self::assertArrayNotHasKey('Cookie', $transport->options[0]['headers']);
        self::assertSame('sid=hop', $transport->options[1]['headers']['Cookie']);
    }

    public function test_two_jars_share_nothing(): void
    {
        $first = new CookieJar();
        $second = new CookieJar();
        $this->set($first, 'https://api.example.com/', 'sid=first');

        self::assertSame('sid=first', $this->sent($first, 'https://api.example.com/'));
        self::assertNull($this->sent($second, 'https://api.example.com/'));
    }

    public function test_a_client_without_a_jar_keeps_nothing(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'headers' => ['set-cookie' => ['sid=1']]]]);
        $http = new Http($transport);

        $http->get('https://api.example.com/')->status();
        $http->get('https://api.example.com/')->status();

        self::assertArrayNotHasKey('Cookie', $transport->options[1]['headers']);
    }

    public function test_the_callers_cookie_header_comes_first_and_the_jars_cookies_follow(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', 'jar=1');
        $transport = new ScriptedTransport([['status' => 200]]);

        new Http($transport)
            ->withBaseUrl('https://api.example.com')
            ->withHeaders(['Cookie' => 'caller=1'])
            ->withCookieJar($jar)
            ->get('/')
            ->status();

        self::assertSame('caller=1; jar=1', $transport->options[0]['headers']['Cookie']);
    }

    public function test_a_hostile_server_cannot_grow_the_jar_without_bound(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', ...array_map(static fn (int $i): string => "c{$i}=1", range(1, 80)));
        $this->set($jar, 'https://api.example.com/', 'huge=' . str_repeat('x', 5000));

        $sent = (string) $this->sent($jar, 'https://api.example.com/');

        self::assertSame(50, substr_count($sent, '=1'));
        self::assertStringStartsWith('c31=1; ', $sent, 'The oldest cookies made room.');
        self::assertStringNotContainsString('huge', $sent);
    }

    /** The size bound counts what the jar keeps, the path a cookie is stored under included. */
    public function test_a_cookie_whose_path_carries_it_past_the_size_limit_is_ignored(): void
    {
        $jar = new CookieJar();
        $long = '/' . str_repeat('a', 60000);
        $this->set($jar, 'https://api.example.com/', 's=1; Path=' . $long, 'kept=1; Path=/' . str_repeat('b', 100));

        self::assertNull($this->sent($jar, 'https://api.example.com' . $long));
        self::assertSame('kept=1', $this->sent($jar, 'https://api.example.com/' . str_repeat('b', 100)));
    }

    /** A malformed line is ignored rather than stored or sent. */
    public function test_a_malformed_set_cookie_is_ignored(): void
    {
        $jar = new CookieJar();
        $this->set($jar, 'https://api.example.com/', 'no-equals-sign', '=no-name', "bad name=1", "ctl=a\x01b");

        self::assertNull($this->sent($jar, 'https://api.example.com/'));
    }

    /** Stores the cookies one response from $url sets, through the client. */
    private function set(CookieJar $jar, string $url, string ...$setCookie): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'headers' => ['set-cookie' => $setCookie]]]);

        new Http($transport)->withCookieJar($jar)->get($url)->status();
    }

    /** The Cookie header a request to $url carries, or null when it carries none. */
    private function sent(CookieJar $jar, string $url): ?string
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        new Http($transport)->withCookieJar($jar)->get($url)->status();

        $cookie = $transport->options[0]['headers']['Cookie'] ?? null;

        return is_string($cookie) ? $cookie : null;
    }
}
