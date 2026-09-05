<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Reading a response, giving one back, and what an exception from either
 * is allowed to carry.
 */
final class HttpResponseTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    private function respondingWith(string $body, int $status = 200): Http
    {
        return new Http(new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => $status])));
    }

    public function test_discard_is_quiet_and_repeatable(): void
    {
        $response = $this->respondingWith('{"id":1}')->get(self::URL);

        $response->discard();
        $response->discard();

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{0: \Closure(\Kinetis\RevoltHttpClient\HttpResponse): mixed}>
     */
    public static function readAfterDiscardProvider(): iterable
    {
        yield 'status' => [static fn ($response) => $response->status()];
        yield 'body' => [static fn ($response) => $response->body()];
        yield 'json' => [static fn ($response) => $response->json()];
        yield 'headers' => [static fn ($response) => $response->headers()];
    }

    /**
     * Reading a response that was given back is a defined failure rather
     * than an undefined result — the caller said they were done with it.
     *
     * @param \Closure(\Kinetis\RevoltHttpClient\HttpResponse): mixed $read
     */
    #[DataProvider('readAfterDiscardProvider')]
    public function test_a_read_after_discard_fails_with_the_discarded_category(\Closure $read): void
    {
        $response = $this->respondingWith('{"id":1}')->get(self::URL);
        $response->body();
        $response->discard();

        try {
            $read($response);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Discarded, $e->category);
            self::assertSame('GET https://api.example.com was discarded; its body was released and cannot be read.', $e->getMessage());
        }
    }

    /**
     * Cancelling is the caller saying they are done; a transport that
     * raises while being cancelled has nobody left to tell, and must not
     * turn a cleanup into a second failure.
     */
    public function test_discard_stays_quiet_when_the_transport_raises_on_cancellation(): void
    {
        $response = new Http(self::transportWhoseResponseThrowsOnCancel($cancelled))->get(self::URL);

        $response->discard();

        self::assertSame(1, $cancelled);
    }

    /**
     * Every response the retry loop abandons is released. An abandoned
     * response that kept its connection would leak one per retry.
     */
    public function test_the_retry_loop_releases_every_response_it_abandons(): void
    {
        $cancelled = 0;

        $response = new Http(self::transportWhoseResponseThrowsOnCancel($cancelled, status: 503))
            ->withRetries(2)
            ->get(self::URL);

        // Three attempts, of which the first two were abandoned by the
        // loop; the third belongs to the caller until they give it back.
        self::assertSame(2, $cancelled);

        $response->discard();

        self::assertSame(3, $cancelled);
    }

    /**
     * A response nobody reads and nobody discards still gives its
     * connection back, at the point PHP collects it. That fallback is
     * what keeps an ignored response from holding one for as long as the
     * object happens to live; it is not a substitute for discard(),
     * which releases at a moment the caller chose.
     */
    public function test_a_response_nobody_keeps_releases_itself_when_it_is_collected(): void
    {
        $transport = self::transportWhoseResponseThrowsOnCancel($cancelled);

        new Http($transport)->get(self::URL);

        self::assertSame(1, $cancelled);
    }

    /**
     * A body read to its end leaves the transport nothing to give back,
     * so neither the destructor nor a later discard() cancels a response
     * that is already complete.
     */
    public function test_a_fully_read_response_is_not_cancelled_again(): void
    {
        $transport = self::transportWhoseResponseThrowsOnCancel($cancelled);

        new Http($transport)->get(self::URL)->body();

        self::assertSame(0, $cancelled);
    }

    /**
     * Whatever the transport raises while a body is being read is
     * replaced, not wrapped: its message routinely names the URI it
     * failed on, userinfo and all.
     */
    public function test_a_vendor_failure_while_reading_becomes_a_transport_failure(): void
    {
        $transport = new class implements HttpClientInterface {
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return new class implements ResponseInterface {
                    public function getStatusCode(): int
                    {
                        return 200;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        throw new RuntimeException('reading https://user:SENTINEL@api.example.com/orders failed');
                    }

                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }

                    public function cancel(): void {}

                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }
                };
            }

            public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new RuntimeException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };

        try {
            new Http($transport)->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
            self::assertNull($e->getPrevious());
            self::assertSame('GET https://api.example.com failed before any response arrived.', $e->getMessage());
        }
    }

    /**
     * A body that is not valid UTF-8 is not valid JSON either, and it
     * fails the same fixed way: the same category, the same message,
     * and not one byte of the body quoted into either.
     */
    public function test_a_body_that_is_not_valid_utf8_fails_the_same_fixed_way(): void
    {
        try {
            $this->respondingWith("{\"name\":\"\xB1\xC3SENTINELBODY\"}")->get(self::URL)->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Conversion, $e->category);
            self::assertSame(
                'GET https://api.example.com returned HTTP 200 with a body that is not valid JSON.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
        }
    }

    public function test_a_body_that_is_not_json_fails_with_the_conversion_category(): void
    {
        try {
            $this->respondingWith('definitely not json')->get(self::URL)->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Conversion, $e->category);
            self::assertSame(
                'GET https://api.example.com returned HTTP 200 with a body that is not valid JSON.',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function scalarTopLevelJsonProvider(): iterable
    {
        yield 'string' => ['"a-secret-string-value"', 'string'];
        yield 'integer' => ['123456789', 'int'];
        yield 'float' => ['3.14159', 'float'];
        yield 'true' => ['true', 'bool'];
        yield 'false' => ['false', 'bool'];
        yield 'null' => ['null', 'null'];
    }

    /**
     * A JSON string, number, boolean, or null is valid JSON that json()'s
     * array-shaped contract cannot return. The failure reports the
     * decoded value's type, never the value.
     */
    #[DataProvider('scalarTopLevelJsonProvider')]
    public function test_a_scalar_top_level_json_body_is_a_typed_failure_rather_than_a_type_error(
        string $body,
        string $expectedType,
    ): void {
        try {
            $this->respondingWith($body)->get(self::URL)->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Conversion, $e->category);
            self::assertSame(200, $e->status);
            self::assertStringContainsString("decoded to a {$expectedType}, not an object or array", $e->getMessage());
            self::assertStringNotContainsString('a-secret-string-value', $e->getMessage());
        }
    }

    public function test_json_path_fails_the_same_way_for_a_scalar_top_level_body(): void
    {
        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('not an object or array');

        $this->respondingWith('"just a string"')->get(self::URL)->jsonPath('anything');
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function emptyTopLevelJsonContainerProvider(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
    }

    /**
     * Decoding associatively makes an empty JSON object and an empty
     * JSON array the same empty PHP array, and json()'s contract does not
     * need to tell them apart: both are valid containers.
     */
    #[DataProvider('emptyTopLevelJsonContainerProvider')]
    public function test_an_empty_top_level_json_container_decodes_successfully(string $body): void
    {
        self::assertSame([], $this->respondingWith($body)->get(self::URL)->json());
    }

    /**
     * An id wider than PHP's own int type would round into a float if it
     * were decoded as a number; it is decoded as a string instead, with
     * every digit intact.
     */
    public function test_an_integer_too_wide_for_php_is_decoded_as_a_string(): void
    {
        $body = $this->respondingWith('{"id":123456789012345678901,"count":42}')->get(self::URL)->json();

        self::assertSame('123456789012345678901', $body['id']);
        self::assertSame(42, $body['count']);
    }

    /**
     * A transport whose response raises while being cancelled, and
     * counts how often that happened — the seam for both the quiet
     * discard and the retry loop's own release.
     */
    private static function transportWhoseResponseThrowsOnCancel(?int &$cancelled, int $status = 200): HttpClientInterface
    {
        $cancelled = 0;

        return new class ($cancelled, $status) implements HttpClientInterface {
            public function __construct(private int &$cancelled, private readonly int $status) {}

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return new class ($this->cancelled, $this->status) implements ResponseInterface {
                    public function __construct(private int &$cancelled, private readonly int $status) {}

                    public function getStatusCode(): int
                    {
                        return $this->status;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        return '';
                    }

                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }

                    public function cancel(): void
                    {
                        ++$this->cancelled;

                        throw new RuntimeException('cancelling https://user:SENTINEL@api.example.com failed');
                    }

                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }
                };
            }

            public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new RuntimeException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
    }
}
