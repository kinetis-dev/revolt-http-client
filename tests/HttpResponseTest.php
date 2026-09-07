<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\HttpResponse;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the response owns: the transport response it releases, the byte
 * ceiling it enforces, and the fixed failures it reports instead of the
 * transport's own.
 */
final class HttpResponseTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /** @param list<array<string, mixed>> $script */
    private function respondingWith(array $script, int $maxBytes = 8 * 1024 * 1024): HttpResponse
    {
        return new Http(new ScriptedTransport($script))->withMaxResponseBytes($maxBytes)->get(self::URL);
    }

    public function test_discard_releases_once_and_is_repeatable(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $response = new Http($transport)->get(self::URL);

        $response->discard();
        $response->discard();

        self::assertSame(1, $transport->cancellations());
    }

    /**
     * @return iterable<string, array{0: Closure(HttpResponse): mixed}>
     */
    public static function readAfterDiscardProvider(): iterable
    {
        yield 'status' => [static fn (HttpResponse $r): int => $r->status()];
        yield 'body' => [static fn (HttpResponse $r): string => $r->body()];
        yield 'json' => [static fn (HttpResponse $r): array => $r->json()];
        yield 'headers' => [static fn (HttpResponse $r): array => $r->headers()];
    }

    /**
     * @param Closure(HttpResponse): mixed $read
     */
    #[DataProvider('readAfterDiscardProvider')]
    public function test_a_read_after_discard_fails_with_the_discarded_category(Closure $read): void
    {
        $response = $this->respondingWith([['status' => 200]]);
        $response->discard();

        try {
            $read($response);
            self::fail('A read after discard was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Discarded, $e->category);
        }
    }

    public function test_a_response_nobody_keeps_releases_itself_when_it_is_collected(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        new Http($transport)->get(self::URL);

        self::assertSame(1, $transport->cancellations());
    }

    public function test_a_fully_read_response_is_not_cancelled_again(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'chunks' => ['{"ok":true}']]]);
        $response = new Http($transport)->get(self::URL);

        self::assertSame(['ok' => true], $response->json());
        $response->discard();

        self::assertSame(0, $transport->cancellations());
    }

    /**
     * A transport exception names the URI it failed on, userinfo and
     * all, so it is replaced rather than wrapped.
     */
    public function test_a_vendor_failure_while_reading_becomes_a_transport_failure(): void
    {
        $response = $this->respondingWith([[
            'status' => 200,
            'readFailure' => 'read of https://SENTINELUSER:SENTINELPASS@api.example.com failed',
        ]]);

        try {
            $response->body();
            self::fail('A failed read was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_a_body_exactly_at_the_ceiling_is_returned(): void
    {
        self::assertSame('xxxx', $this->respondingWith([['status' => 200, 'chunks' => ['xxxx']]], 4)->body());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function overLargeResponseProvider(): iterable
    {
        yield 'a declared length past the ceiling' => [[
            'status' => 200,
            'headers' => ['content-length' => ['9']],
            'chunks' => ['xxxxxxxxx'],
        ]];

        yield 'a transfer past the ceiling, with no length declared' => [[
            'status' => 200,
            'chunks' => ['xxxx', 'xxxxx'],
        ]];

        yield 'a transfer past a length that understates it' => [[
            'status' => 200,
            'headers' => ['content-length' => ['2']],
            'chunks' => ['xxxx', 'xxxxx'],
        ]];
    }

    /**
     * @param array<string, mixed> $step
     */
    #[DataProvider('overLargeResponseProvider')]
    public function test_a_body_past_the_ceiling_throws_and_releases_the_response(array $step): void
    {
        $transport = new ScriptedTransport([$step]);
        $response = new Http($transport)->withMaxResponseBytes(4)->get(self::URL);

        try {
            $response->body();
            self::fail('An over-large body was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
            self::assertStringContainsString('4-byte ceiling', $e->getMessage());
        }

        self::assertSame(1, $transport->cancellations());
    }

    public function test_the_transfer_stops_at_the_chunk_that_passes_the_ceiling(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'chunks' => ['xxxx', 'xxxx', 'xxxx', 'xxxx']]]);

        try {
            new Http($transport)->withMaxResponseBytes(4)->get(self::URL)->body();
        } catch (HttpRequestException) {
            // The stopping point is what this test is about.
        }

        self::assertSame(2, $transport->responses[0]->chunksDelivered);
    }

    public function test_a_shared_client_carries_no_size_state_between_requests(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'chunks' => ['xxxxxxxx']], ['status' => 200, 'chunks' => ['xx']]]);
        $client = new Http($transport)->withMaxResponseBytes(4);

        try {
            $client->get(self::URL)->body();
        } catch (HttpRequestException) {
            // The first request exhausts its own budget, not the next one's.
        }

        self::assertSame('xx', $client->get(self::URL)->body());
    }

    public function test_the_progress_hook_is_the_clients_own_and_cannot_be_replaced(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        new Http($transport)->get(self::URL)->status();

        self::assertInstanceOf(Closure::class, $transport->options[0]['on_progress']);
    }

    public function test_the_default_ceiling_applies_without_configuration(): void
    {
        self::assertSame(8 * 1024 * 1024, Http::DEFAULT_MAX_RESPONSE_BYTES);
        self::assertSame('xx', $this->respondingWith([['status' => 200, 'chunks' => ['xx']]])->body());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function undecodableBodyProvider(): iterable
    {
        yield 'not JSON at all' => ['definitely not json'];
        yield 'invalid UTF-8' => ["\xB1\x31"];
        yield 'a bare string' => ['"just a string"'];
        yield 'a bare number' => ['42'];
        yield 'a bare boolean' => ['true'];
        yield 'a bare null' => ['null'];
    }

    #[DataProvider('undecodableBodyProvider')]
    public function test_a_body_json_cannot_return_fails_with_the_conversion_category(string $body): void
    {
        $response = $this->respondingWith([['status' => 200, 'chunks' => [$body]]]);

        try {
            $response->json();
            self::fail('A body json() cannot return was expected to raise.');
        } catch (HttpRequestException $e) {
            // The body's own text never reaches the message; see
            // TraceSecrecyTest for the assertion that covers it.
            self::assertSame(HttpFailure::Conversion, $e->category);
            self::assertSame(200, $e->status);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<array-key, mixed>}>
     */
    public static function emptyTopLevelJsonContainerProvider(): iterable
    {
        yield 'an empty object' => ['{}', []];
        yield 'an empty array' => ['[]', []];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('emptyTopLevelJsonContainerProvider')]
    public function test_an_empty_top_level_json_container_decodes_successfully(string $body, array $expected): void
    {
        self::assertSame($expected, $this->respondingWith([['status' => 200, 'chunks' => [$body]]])->json());
    }
}
