<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use SensitiveParameter;

/**
 * The cookies of one HTTP session, given to a client with
 * {@see Http::withCookieJar()}. It keeps what every response sets,
 * redirect hops included, and sends what matches on every request.
 *
 * A cookie belongs to the exact host that set it: the `Domain`
 * attribute is read and never widens that, so a cookie `a.example.com`
 * sets is never sent to `b.example.com`, whatever it names. Without a
 * public-suffix list a client cannot tell a parent domain shared by one
 * owner from one shared by strangers, and the exact host is the scope
 * that is right either way. `Path` scopes it as RFC 6265 §5.1.4 does, a
 * `Secure` cookie goes only over https and is refused when set over
 * http, and `Max-Age` wins over `Expires`. `HttpOnly` and `SameSite`
 * mean nothing outside a browser and are ignored.
 *
 * Build one per session, never per worker: a jar is the cookies of
 * whoever it was built for, and one shared by the requests of a
 * persistent worker sends one user's session as the next one's. Nothing
 * else in this package keeps one, and no client has one until it is
 * given one. The limits below bound what a hostile server can make it
 * hold. Cookie values never reach an exception message.
 */
final class CookieJar
{
    /** Cookies kept for one host; setting another evicts that host's oldest. */
    private const int MAX_COOKIES_PER_HOST = 50;

    /** Cookies kept in all; setting another evicts the oldest. */
    private const int MAX_COOKIES = 300;

    /** A cookie whose name, value and stored path together are longer is ignored. */
    private const int MAX_COOKIE_BYTES = 4096;

    /** RFC 9110 token: what a cookie name may contain. */
    private const string TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/';

    /** Controls, and the separator a value cannot carry in a Cookie header. */
    private const string ILLEGAL_IN_VALUE = '/[\x00-\x1F\x7F;]/';

    /**
     * Oldest first, keyed by host, path and name.
     *
     * @var array<string, array{host: string, path: string, name: string, value: string, secure: bool, expires: ?int}>
     */
    private array $cookies = [];

    /**
     * Keeps what $setCookie sets, for a response from $url, an absolute
     * URL this package has already validated.
     *
     * @internal {@see Http} calls this for every response it reads.
     * @param list<string> $setCookie
     */
    public function store(#[SensitiveParameter] string $url, #[SensitiveParameter] array $setCookie): void
    {
        $target = self::target($url);

        if ($target === null) {
            return;
        }

        foreach ($setCookie as $line) {
            $this->storeOne($target, $line);
        }
    }

    /**
     * The `Cookie` header value for a request to $url, or null when no
     * cookie matches.
     *
     * @internal {@see Http} calls this for every request it sends.
     */
    public function header(#[SensitiveParameter] string $url): ?string
    {
        $target = self::target($url);

        if ($target === null) {
            return null;
        }

        $now = time();
        $matching = [];

        foreach ($this->cookies as $key => $cookie) {
            if ($cookie['expires'] !== null && $cookie['expires'] <= $now) {
                unset($this->cookies[$key]);

                continue;
            }

            if (
                $cookie['host'] === $target['host']
                && ($target['secure'] || !$cookie['secure'])
                && self::pathMatches($target['path'], $cookie['path'])
            ) {
                $matching[] = $cookie;
            }
        }

        if ($matching === []) {
            return null;
        }

        // RFC 6265 §5.4: longer paths first; the sort is stable, so
        // equal paths keep the order they were set in.
        usort($matching, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));

        return implode('; ', array_map(static fn (array $cookie): string => "{$cookie['name']}={$cookie['value']}", $matching));
    }

    /**
     * @param array{host: string, path: string, secure: bool} $target
     */
    private function storeOne(array $target, #[SensitiveParameter] string $line): void
    {
        $attributes = explode(';', $line);
        $pair = (string) array_shift($attributes);
        $equals = strpos($pair, '=');

        if ($equals === false) {
            return;
        }

        $name = trim(substr($pair, 0, $equals));
        $value = trim(substr($pair, $equals + 1));

        if (
            preg_match(self::TOKEN, $name) !== 1
            || preg_match(self::ILLEGAL_IN_VALUE, $value) === 1
        ) {
            return;
        }

        $path = null;
        $secure = false;
        $maxAge = null;
        $expires = null;

        foreach ($attributes as $attribute) {
            $equals = strpos($attribute, '=');
            $attributeName = strtolower(trim($equals === false ? $attribute : substr($attribute, 0, $equals)));
            $attributeValue = $equals === false ? '' : trim(substr($attribute, $equals + 1));

            match ($attributeName) {
                'path' => $path = str_starts_with($attributeValue, '/') ? $attributeValue : null,
                'secure' => $secure = true,
                'max-age' => $maxAge = preg_match('/^-?\d+$/', $attributeValue) === 1 ? (int) $attributeValue : $maxAge,
                'expires' => $expires = self::date($attributeValue) ?? $expires,
                default => null,
            };
        }

        // A cookie that claims transport security over a channel without it.
        if ($secure && !$target['secure']) {
            return;
        }

        $path ??= self::defaultPath($target['path']);

        if (strlen($name) + strlen($value) + strlen($path) > self::MAX_COOKIE_BYTES) {
            return;
        }
        $key = "{$target['host']}\0{$path}\0{$name}";

        // Over http a cookie cannot replace one only https could set.
        if (!$target['secure'] && ($this->cookies[$key]['secure'] ?? false)) {
            return;
        }

        $expiresAt = $maxAge !== null ? time() + $maxAge : $expires;
        unset($this->cookies[$key]);

        if ($expiresAt !== null && $expiresAt <= time()) {
            return;
        }

        $this->evictFor($target['host']);
        $this->cookies[$key] = [
            'host' => $target['host'],
            'path' => $path,
            'name' => $name,
            'value' => $value,
            'secure' => $secure,
            'expires' => $expiresAt,
        ];
    }

    /** Makes room for one more cookie on $host, oldest first. */
    private function evictFor(string $host): void
    {
        $forHost = array_keys(array_filter($this->cookies, static fn (array $cookie): bool => $cookie['host'] === $host));

        if (count($forHost) >= self::MAX_COOKIES_PER_HOST) {
            unset($this->cookies[$forHost[0]]);
        }

        if (count($this->cookies) >= self::MAX_COOKIES) {
            unset($this->cookies[array_key_first($this->cookies)]);
        }
    }

    /**
     * The parts of $url a cookie is scoped by: the lowercased host, the
     * path, and whether the channel is https.
     *
     * @return array{host: string, path: string, secure: bool}|null
     */
    private static function target(#[SensitiveParameter] string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        return [
            'host' => strtolower($parts['host']),
            'path' => $parts['path'] ?? '/',
            'secure' => strtolower($parts['scheme']) === 'https',
        ];
    }

    /** RFC 6265 §5.1.4's default-path: the request path up to its last "/". */
    private static function defaultPath(string $path): string
    {
        $slash = strrpos($path, '/');

        return !str_starts_with($path, '/') || $slash === 0 || $slash === false ? '/' : substr($path, 0, $slash);
    }

    /** RFC 6265 §5.1.4's path-match. */
    private static function pathMatches(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }

        return str_starts_with($requestPath, $cookiePath)
            && (str_ends_with($cookiePath, '/') || ($requestPath[strlen($cookiePath)] ?? '') === '/');
    }

    /** An `Expires` value as a Unix time, or null when it is not a date. */
    private static function date(string $value): ?int
    {
        $time = $value === '' ? false : strtotime($value);

        return $time === false ? null : $time;
    }
}
