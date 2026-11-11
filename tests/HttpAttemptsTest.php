<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\RequestPacer;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\TestCase;

/**
 * `HttpResponse::attempts()`: the wire attempts the operation sent,
 * counted where they are sent rather than where a retry is considered.
 */
final class HttpAttemptsTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /**
     * A pacer answering each reservation with the next of $answers, the
     * last repeating, and counting the reservations it was asked for.
     *
     * @param non-empty-list<?float> $answers
     */
    private static function pacer(array $answers): RequestPacer
    {
        return new class ($answers) implements RequestPacer {
            public int $reservations = 0;

            /** @param non-empty-list<?float> $answers */
            public function __construct(private readonly array $answers) {}

            public function reserve(string $origin, float $maxWaitSeconds): ?float
            {
                return $this->answers[$this->reservations++] ?? $this->answers[count($this->answers) - 1];
            }
        };
    }

    public function test_a_client_without_retries_reports_one_attempt(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);

        $response = new Http($transport)->get(self::URL);

        self::assertSame(1, $response->attempts());
        self::assertSame(503, $response->status());
    }

    public function test_a_response_after_retries_counts_every_attempt_sent(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 503], ['status' => 200]]);

        $response = new Http($transport)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(3, $response->attempts());
        self::assertSame($transport->requests, $response->attempts());
    }

    public function test_a_retry_the_budget_leaves_no_room_for_is_not_counted(): void
    {
        $transport = new ScriptedTransport([
            ['status' => 503],
            ['status' => 429, 'headers' => ['retry-after' => ['30']]],
            ['status' => 200],
        ]);
        $pacer = self::pacer([0.0]);

        $response = new Http($transport)->withRetries(3)->withTimeout(5.0)->withPacer($pacer)->get(self::URL);

        self::assertSame(429, $response->status());
        self::assertSame(2, $response->attempts());
        self::assertSame(2, $transport->requests);
        self::assertSame(2, $pacer->reservations);
    }

    public function test_a_retry_the_pacer_refuses_is_reserved_but_not_counted(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 503], ['status' => 200]]);
        $pacer = self::pacer([0.0, 0.0, null]);

        $response = new Http($transport)->withRetries(3)->withTimeout(5.0)->withPacer($pacer)->get(self::URL);

        self::assertSame(503, $response->status());
        self::assertSame(2, $response->attempts());
        self::assertSame(2, $transport->requests);
        self::assertSame(3, $pacer->reservations);
    }

    public function test_the_count_still_answers_after_discard(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 200]]);

        $response = new Http($transport)->withRetries(1)->withTimeout(5.0)->get(self::URL);
        $response->discard();

        self::assertSame(2, $response->attempts());
        self::assertSame(1, $transport->responses[1]->cancelled);
    }
}
