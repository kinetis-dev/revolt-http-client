<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/revolt-http-client</strong>
  <br>
  <strong>A Revolt-native, Fiber-suspending implementation of Symfony's <code>HttpClientInterface</code></strong>
</p>

---

Built for Kinetis, but usable in
any PHP project — this package depends on nothing beyond
`symfony/http-client` (and its `symfony/http-client-contracts`) and
`amphp/http-client`. No `kinetis/framework` required.

```php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create();
$response = $client->request('GET', 'https://example.com/');
$response->getContent();
```

A request made through this client suspends the calling Fiber and yields
back to Revolt's event loop while waiting on the network, instead of
blocking the whole process.

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
[docs.kinetis.dev/revolt-http-client.html](https://docs.kinetis.dev/revolt-http-client.html).

## License

MIT — see [LICENSE](../../LICENSE).
