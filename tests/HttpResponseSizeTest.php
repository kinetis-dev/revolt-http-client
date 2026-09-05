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
 * The ceiling a response body may reach, at each of the three points it
 * is checked: a declared length, the transfer itself, and what
 * arrived.
 */
final class HttpResponseSizeTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /**
     * @param list<array<string, mixed>> $script
     */
    private function client(array $script, int $ceiling, int $retries = 0): Http
    {
        $client = new Http(new ScriptedTransport($script))->withMaxResponseBytes($ceiling);

        return $retries === 0 ? $client : $client->withRetries($retries);
    }

    /** A body exactly at the ceiling is a body like any other. */
    public function test_a_body_exactly_at_the_ceiling_is_returned(): void
    {
        $response = $this->client([['chunks' => ['0123456789']]], ceiling: 10)->get(self::URL);

        self::assertSame('0123456789', $response->body());
    }

    /** One byte past it is not. */
    public function test_a_body_one_byte_past_the_ceiling_throws(): void
    {
        try {
            $this->client([['chunks' => ['0123456789']]], ceiling: 9)->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
            self::assertSame(0, $e->status);
            self::assertSame(
                'GET https://api.example.com returned a response past the 9-byte ceiling this client allows.',
                $e->getMessage(),
            );
        }
    }

    /**
     * A truthful `Content-Length` past the ceiling is refused before the
     * body is fetched at all, so nothing of it is ever held.
     */
    public function test_a_declared_length_past_the_ceiling_is_refused_before_the_body_is_fetched(): void
    {
        $transport = new ScriptedTransport([[
            'headers' => ['content-length' => ['5000']],
            'chunks' => ['this body is never asked for'],
        ]]);

        $response = new Http($transport)->withMaxResponseBytes(1024)->get(self::URL);

        try {
            $response->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(1, $transport->cancellations());
    }

    /**
     * @return iterable<string, array{0: array<string, list<string>>}>
     */
    public static function untrustworthyLengthProvider(): iterable
    {
        yield 'no Content-Length at all' => [[]];
        yield 'a Content-Length that understates the body' => [['content-length' => ['4']]];
        yield 'a Content-Length that is not a number' => [['content-length' => ['chunked']]];
    }

    /**
     * The transfer is what the ceiling is enforced against, so a
     * response that declares no length, or declares one it goes past,
     * is stopped as it arrives rather than measured once it is all in
     * memory.
     *
     * @param array<string, list<string>> $headers
     */
    #[DataProvider('untrustworthyLengthProvider')]
    public function test_a_missing_or_untruthful_length_is_caught_by_the_transfer_itself(array $headers): void
    {
        $transport = new ScriptedTransport([[
            'headers' => $headers,
            'chunks' => ['aaaa', 'bbbb', 'cccc', 'dddd'],
        ]]);

        try {
            new Http($transport)->withMaxResponseBytes(10)->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    /**
     * The guard runs on the bytes that arrived, so the transfer stops
     * at the chunk that passes the ceiling rather than at the end of
     * the response: two of four chunks reach the buffer, and the two
     * behind them never do.
     */
    public function test_the_transfer_stops_at_the_chunk_that_passes_the_ceiling(): void
    {
        $transport = new ScriptedTransport([['chunks' => ['aaaa', 'bbbb', 'cccc', 'dddd']]]);

        try {
            new Http($transport)->withMaxResponseBytes(6)->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(2, $transport->responses[0]->chunksDelivered);
    }

    /**
     * An error payload is untrusted data like any other, so the ceiling
     * holds for the body a caller reads to find out what went wrong.
     */
    public function test_an_error_status_body_is_bounded_by_the_same_ceiling(): void
    {
        $response = $this->client([['status' => 500, 'chunks' => [str_repeat('x', 64)]]], ceiling: 16)->get(self::URL);

        self::assertSame(500, $response->status());
        self::assertTrue($response->serverError());

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('past the 16-byte ceiling');
        $response->body();
    }

    /** json() reads through body(), so it is bounded by the same ceiling. */
    public function test_json_is_bounded_by_the_same_ceiling(): void
    {
        try {
            $this->client([['chunks' => ['{"id":1234567890}']]], ceiling: 8)->get(self::URL)->json();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    /**
     * Each attempt gets its own budget, so a retry counts its own bytes
     * from zero. Two attempts of three bytes against a ceiling of four
     * succeed; one accumulating counter would make the second fail.
     */
    public function test_a_retry_counts_its_own_bytes_rather_than_the_previous_attempts(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 503, 'chunks' => ['abc']],
            ['status' => 200, 'chunks' => ['xyz']],
        ]);

        $response = new Http($transport)->withMaxResponseBytes(4)->withRetries(1)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame('xyz', $response->body());
        self::assertSame(2, $transport->requests);
    }

    /**
     * A retrying client waits for the status inside send(), and a
     * transport that buffers reports body bytes while it answers that
     * wait. The ceiling is reached there, before any read is asked for,
     * so send() is where the refusal comes from — and the response is
     * released rather than left holding a connection.
     *
     * The transport reports the bytes rather than a clock deciding
     * whether it got round to it, which is what makes the point provable
     * instead of raced.
     */
    public function test_a_retrying_client_reaches_the_ceiling_while_it_waits_for_the_status(): void
    {
        $transport = new ScriptedTransport([[
            'status' => 200,
            'progressBeforeStatus' => 4096,
            'chunks' => [str_repeat('x', 4096)],
        ]]);

        try {
            new Http($transport)->withRetries(1)->withMaxResponseBytes(512)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(1, $transport->requests);
        self::assertSame(1, $transport->cancellations());
    }

    /**
     * A client without retries returns before any of that: nothing has
     * asked for a status, so the same oversized response is refused at
     * the read instead. Both routes are the same ceiling and the same
     * category; which one reports it is a matter of what has been asked
     * for.
     */
    public function test_a_client_without_retries_reaches_the_same_ceiling_at_the_read(): void
    {
        $transport = new ScriptedTransport([[
            'status' => 200,
            'progressBeforeStatus' => 4096,
            'chunks' => [str_repeat('x', 4096)],
        ]]);

        $response = new Http($transport)->withMaxResponseBytes(512)->get(self::URL);

        try {
            $response->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    /**
     * A response past the ceiling is the same response every time, so a
     * retrying client reports it rather than spending attempts on it.
     */
    public function test_an_oversized_response_is_not_retried(): void
    {
        $transport = new ScriptedTransport([['chunks' => [str_repeat('x', 32)]]]);

        try {
            new Http($transport)->withMaxResponseBytes(8)->withRetries(3)->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(1, $transport->requests);
    }

    /**
     * One shared client serving request after request — the persistent
     * worker's own shape. Nothing the ceiling counts survives a request,
     * so a body that passed it leaves the next request unaffected and
     * the one after that too.
     */
    public function test_a_shared_client_carries_no_size_state_between_requests(): void
    {
        $transport = new ScriptedTransport([
            ['chunks' => ['abc']],
            ['chunks' => [str_repeat('x', 32)]],
            ['chunks' => ['abc']],
        ]);
        $client = new Http($transport)->withMaxResponseBytes(4);

        self::assertSame('abc', $client->get(self::URL)->body());

        try {
            $client->get(self::URL)->body();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame('abc', $client->get(self::URL)->body());
    }

    /**
     * The transport's progress hook belongs to the ceiling. A caller
     * cannot pass one that would replace it, and every attempt carries
     * the client's own.
     */
    public function test_the_progress_hook_is_the_clients_own_and_cannot_be_replaced(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 200]]);

        new Http($transport)->withRetries(1)->get(self::URL);

        self::assertCount(2, $transport->options);

        foreach ($transport->options as $options) {
            self::assertInstanceOf(Closure::class, $options['on_progress']);
        }

        try {
            new Http($transport)->send('GET', self::URL, ['on_progress' => static fn (): null => null]);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
            self::assertStringContainsString('withMaxResponseBytes()', $e->getMessage());
        }
    }

    /** The default ceiling is documented as a constant, and it is finite. */
    public function test_the_default_ceiling_is_finite_and_applies_without_configuration(): void
    {
        self::assertSame(8 * 1024 * 1024, Http::DEFAULT_MAX_RESPONSE_BYTES);

        $body = str_repeat('x', 64);

        self::assertSame($body, new Http(new ScriptedTransport([['chunks' => [$body]]]))->get(self::URL)->body());
    }
}
