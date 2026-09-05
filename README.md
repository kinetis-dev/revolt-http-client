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

Built for Kinetis, but usable in
any PHP project — this package depends on nothing beyond
`symfony/http-client` (and its `symfony/http-client-contracts`) and
`amphp/http-client`. No [`kinetis/framework`](https://github.com/kinetis-dev/framework) required.

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

- **Every input is checked before a transport object exists.** The
  transport, base URI, URL, method, headers, query, body, options,
  timeout, retry count, and response-byte ceiling each have a shape this
  client will send and one it refuses. A refused call reaches no network
  at all.
- **A credential is pinned to one origin.** A client carrying an
  `Authorization`, `Cookie`, or `Proxy-Authorization` header requires
  `withBaseUrl()`, and then every URL it accepts is relative to that
  base. Another origin is another client.
- **Redirects are never followed.** A 3xx is a terminal response with a
  `Location` to read. Following one means deciding, per response,
  whether a new origin may see this client's `Authorization` header,
  cookies, and body — a decision belonging to the caller who knows what
  the credential is for.
- **One retry layer, and one total deadline.** `withRetries()` is the
  only way to configure retries, the transport underneath makes one wire
  attempt per request, a retrying transport is refused where it is
  injected, a per-call retry option is refused rather than merged, and a
  body that cannot be replayed is refused rather than resent.
  `withTimeout()` bounds the whole operation on a monotonic clock —
  every attempt, every backoff, and every read of the response — and is
  enforced here rather than trusted to the transport.
- **A bounded response.** `withMaxResponseBytes()` is the ceiling a body
  may reach, enforced while it arrives rather than once it is already in
  memory. Every request asks for identity encoding, so the bytes counted
  are the bytes held rather than the bytes off the wire.
- **Failures carry no secrets.** `HttpRequestException` — the one
  exception type this package throws — carries the request method, the
  origin, a status, and a category from a fixed list. No path, no query
  string, no header, no credential, no body, and no vendor exception
  chained behind it.

An error status is not one of those failures: `successful()`, `failed()`,
`clientError()`, `serverError()`, and `redirect()` are answers to branch
on, and `throw()` opts into raising instead. Read the body with `json()`,
`jsonPath('customer.email')`, or `body()`.

`AmpHttpClientFactory::create()` returns the underlying Symfony
`HttpClientInterface` on its own, for libraries that want to be handed a
client. It is a plain Symfony client and a deliberate escape hatch: none
of the guarantees above apply to it — redirect following and Symfony's
own Amp-level request retries included. `createWithoutRetries()` is the
same client with one wire attempt per request, which is what `Http`
itself is built on.

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
