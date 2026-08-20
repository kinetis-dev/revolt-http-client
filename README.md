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

Built for Kinetis, but usable in
any PHP project — this package depends on nothing beyond
`symfony/http-client` (and its `symfony/http-client-contracts`) and
`amphp/http-client`. No `kinetis/framework` required.

```php
use Kinetis\RevoltHttpClient\Http;

$http = new Http()->withBaseUrl('https://api.example.com')->withToken($key);

$orders = $http->get('/orders', ['status' => 'open'])->throw()->json();
$http->post('/orders', ['sku' => 'A1', 'quantity' => 2]);
```

A request made through this client suspends the calling Fiber and yields
back to Revolt's event loop while waiting on the network, instead of
blocking the whole process — so several run at once through
`Kinetis\Async\concurrently()` with no pooling API of its own.

An error status is returned rather than thrown: `successful()`,
`failed()`, `clientError()`, and `serverError()` are answers to branch
on, and `throw()` opts into raising instead. Read the body with
`json()`, `jsonPath('customer.email')`, or `body()`.

`AmpHttpClientFactory::create()` returns the underlying Symfony
`HttpClientInterface` on its own, for libraries that want to be handed a
client:

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

MIT — see [LICENSE](../../LICENSE).
