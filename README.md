<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/revolt-http-client</strong>
  <br>
  <strong>A Revolt-native, Fiber-suspending implementation of Symfony's <code>HttpClientInterface</code></strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/revolt-http-client"><img src="https://img.shields.io/packagist/v/kinetis/revolt-http-client?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/revolt-http-client"><img src="https://img.shields.io/packagist/dt/kinetis/revolt-http-client" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/revolt-http-client"><img src="https://img.shields.io/packagist/php-v/kinetis/revolt-http-client" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/revolt-http-client"><img src="https://img.shields.io/packagist/l/kinetis/revolt-http-client" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Built for Kinetis, but usable in any PHP project — this package depends
on nothing beyond `symfony/http-client` (and its
`symfony/http-client-contracts`), `amphp/http-client`, and
`revolt/event-loop`. No [`kinetis/framework`](https://github.com/kinetis-dev/framework) required.

```php
use Kinetis\RevoltHttpClient\Http;

$api = new Http()->withBaseUrl('https://api.example.com')->withToken($key);

$orders = $api->get('/orders', ['status' => 'open'])->throw()->json();
$api->post('/orders', ['sku' => 'A1', 'quantity' => 2]);
```

A request made through this client suspends the calling Fiber and yields
back to Revolt's event loop while waiting on the network, instead of
blocking the whole process — so several run at once through
`Kinetis\Async\concurrently()` with no pooling API of its own.

## What it guarantees

- **A credential is pinned to one origin.** A client carrying an
  `Authorization` or `Cookie` header requires `withBaseUrl()`, and then
  every URL it accepts, and every redirect it follows, is under that
  base. Another origin is another client. The validated URL alone names
  where a request goes: `Host`, `Proxy-Authorization`, and
  `Accept-Encoding` are refused wherever a caller writes them.
- **Redirects are followed only when asked.** By default a 3xx is the
  response, with a `Location` to read. `withRedirects()` follows a
  bounded number inside the request's one deadline and byte ceiling:
  301, 302 and 303 as a `GET` without the body, 307 and 308 only for an
  idempotent method whose body can be sent again, never from https to
  http, never outside a client's base URL, and only where an optional
  callback allows each target. A redirect it does not follow is returned
  as the response.
- **Cookies live in a jar you hand it.** `withCookieJar()` keeps the
  cookies of one session in a `CookieJar` and sends them back, each to
  the exact host that set it, whatever its `Domain` says. No client has a
  jar until it is given one; build one per session, never per worker.
- **One retry layer, for idempotent methods only, and one total
  deadline.** `withRetries()` is the only way to configure retries, the
  transport underneath makes one wire attempt per request, and a
  per-call retry option is refused rather than merged. Retries apply
  only to exactly `GET`, `HEAD`, `OPTIONS`, `TRACE`, `PUT`, and
  `DELETE`; every other method, `POST` and `PATCH` included, is sent
  once, because neither a dropped connection nor a retryable status
  proves the server did not apply it. A stream or `Closure` body is
  refused on a request that could be retried, rather than resent. A
  server's `Retry-After` is never cut short: a wait that does not fit
  the deadline ends the retries. `withTimeout()` bounds the whole
  operation on a monotonic clock — every attempt, every backoff, and
  every read of the response — and is enforced here rather than trusted
  to the transport. `withPacer()` spaces attempts to a host, across
  worker processes, inside that deadline; the
  [HTTP client guide](https://kinetis.dev/docs/revolt-http-client.html#http-client-pacing)
  states its rules.
- **A bounded response.** `withMaxResponseBytes()` is the ceiling a body
  may reach, enforced while it arrives rather than once it is already in
  memory. Every request asks for gzip and deflate, and this client
  decodes them itself, counting the decoded bytes against the ceiling as
  they are produced, so a small compressed reply cannot inflate past it.
  Another content encoding, and compressed data that is corrupt or
  truncated, fail rather than pass through.
- **Failures carry no secrets.** `HttpRequestException` — the one
  exception type this package throws — carries the request method, the
  origin, a status, and a category from a fixed list. No path, no query
  string, no header, no credential, no body, and no vendor exception
  chained behind it.

An error status is not one of those failures: `successful()`, `failed()`,
`clientError()`, `serverError()`, and `redirect()` are answers to branch
on, and `throw()` opts into raising instead. Read the body with `json()`,
`jsonPath('customer.email')`, or `body()`, or stream it as it arrives
with `chunks()`, under the same deadline and byte ceiling. `attempts()`
reports how many wire attempts the call sent, retries and followed
redirects included.

`AmpHttpClientFactory::create()` returns the underlying Symfony
`HttpClientInterface` on its own, for libraries that want to be handed a
client. It is a plain Symfony client and the escape hatch: none of the
guarantees above apply to it, redirect following included. One
thing carries across, and it is what `Http` itself is built on: one
request is one wire attempt, so a library given this client retries on
its own terms or not at all.

```php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create();
$response = $client->request('GET', 'https://example.com/');
```

## Using it with AsyncAws

Every AsyncAws client — S3, SQS, SES, DynamoDB, or any of its other
services — extends `AsyncAws\Core\AbstractApi`, whose constructor takes
an optional `?HttpClientInterface $httpClient`:

```php
use AsyncAws\S3\S3Client;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$s3 = new S3Client(['region' => 'us-east-1'], null, AmpHttpClientFactory::create());
```

Nothing about this is Kinetis-specific — the same pattern works for any
other library that accepts an injectable `HttpClientInterface`.

## Installation

```sh
composer require kinetis/revolt-http-client
```

Requires PHP 8.4+ (the floor `symfony/http-client:^8.0` itself requires).
Full documentation:
[kinetis.dev/docs/revolt-http-client.html](https://kinetis.dev/docs/revolt-http-client.html).

## License

MIT — see [LICENSE](LICENSE).
