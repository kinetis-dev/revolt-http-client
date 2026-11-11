<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\RequestPacer;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `withPacer()`: a slot reserved before every wire attempt, retries
 * included, and waited for inside the one operation deadline.
 */
final class HttpPacerTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /** @var list<array{origin: string, maxWait: float}> */
    private array $reservations = [];

    /**
     * A pacer answering each reservation with the next of $answers, the
     * last repeating. A Closure answer is called with the reservation's
     * $maxWaitSeconds, and may throw.
     *
     * @param non-empty-list<float|null|Closure(float): ?float> $answers
     */
    private function pacer(array $answers): RequestPacer
    {
        $record = function (string $origin, float $maxWait) use ($answers): float|null|Closure {
            $answer = $answers[count($this->reservations)] ?? $answers[count($answers) - 1];
            $this->reservations[] = ['origin' => $origin, 'maxWait' => $maxWait];

            return $answer;
        };

        return new class ($record) implements RequestPacer {
            /** @param Closure(string, float): (float|null|Closure(float): ?float) $record */
            public function __construct(private readonly Closure $record) {}

            public function reserve(string $origin, float $maxWaitSeconds): ?float
            {
                $answer = ($this->record)($origin, $maxWaitSeconds);

                return $answer instanceof Closure ? $answer($maxWaitSeconds) : $answer;
            }
        };
    }

    public function test_every_attempt_reserves_a_slot_for_its_origin_with_what_is_left_of_the_budget(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 429], ['status' => 200]]);

        $response = new Http($transport)->withRetries(3)->withTimeout(5.0)->withPacer($this->pacer([0.0]))
            ->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(3, $transport->requests);
        self::assertCount(3, $this->reservations);
        self::assertSame(['https://api.example.com'], array_unique(array_column($this->reservations, 'origin')));

        // Each reservation is offered the budget as it stood. A retry's
        // slot is reserved before its backoff, so the 100 ms backoff
        // separates the second reservation from the third.
        self::assertLessThanOrEqual(5.0, $this->reservations[0]['maxWait']);
        self::assertGreaterThan(4.9, $this->reservations[0]['maxWait']);
        self::assertLessThan($this->reservations[0]['maxWait'], $this->reservations[1]['maxWait']);
        self::assertLessThanOrEqual($this->reservations[1]['maxWait'] - 0.1, $this->reservations[2]['maxWait']);
    }

    public function test_the_first_attempt_waits_for_its_slot(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $start = hrtime(true);

        new Http($transport)->withTimeout(5.0)->withPacer($this->pacer([0.3]))->get(self::URL);

        self::assertGreaterThanOrEqual(0.29, (hrtime(true) - $start) / 1_000_000_000);
        self::assertLessThanOrEqual(5.0 - 0.29, $transport->options[0]['timeout']);
    }

    /** A slot further off than the backoff sets the wait; a nearer one leaves the backoff. */
    public function test_a_retry_waits_for_the_later_of_its_backoff_and_its_slot(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 503], ['status' => 200]]);

        new Http($transport)->withRetries(2)->withTimeout(5.0)->withPacer($this->pacer([0.0, 0.5, 0.0]))
            ->get(self::URL);

        self::assertSame(3, $transport->requests);
        self::assertLessThanOrEqual($transport->options[0]['timeout'] - 0.49, $transport->options[1]['timeout']);
        self::assertGreaterThan($transport->options[1]['timeout'] - 0.5, $transport->options[2]['timeout']);
        self::assertLessThanOrEqual($transport->options[1]['timeout'] - 0.19, $transport->options[2]['timeout']);
    }

    public function test_no_slot_for_the_first_attempt_is_a_timeout_and_sends_nothing(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        try {
            new Http($transport)->withRetries(3)->withPacer($this->pacer([null]))->get(self::URL);
            self::fail('No slot was expected to time the operation out.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        self::assertSame(0, $transport->requests);
    }

    /** The client never waits past its deadline for a slot, whatever the pacer answers. */
    public function test_a_first_slot_that_does_not_fit_the_budget_is_a_timeout_without_waiting(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $start = hrtime(true);

        try {
            new Http($transport)->withTimeout(5.0)
                ->withPacer($this->pacer([static fn (float $maxWait): float => $maxWait]))
                ->get(self::URL);
            self::fail('A slot past the deadline was expected to time the operation out.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        self::assertSame(0, $transport->requests);
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1_000_000_000);
    }

    /** A pacer applies to a request sent once, as it does to every attempt of a retried one. */
    public function test_a_request_without_retries_is_paced(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        try {
            new Http($transport)->withPacer($this->pacer([null]))->post(self::URL, ['a' => 1]);
            self::fail('No slot was expected to time the operation out.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        self::assertSame(0, $transport->requests);
        self::assertCount(1, $this->reservations);
    }

    /** The last answer is returned open, exactly as a Retry-After that does not fit returns it. */
    public function test_no_slot_for_a_retry_returns_the_last_response_unreleased(): void
    {
        $transport = new ScriptedTransport([['status' => 429, 'chunks' => ['{"error":"slow down"}']]]);

        $response = new Http($transport)->withRetries(3)->withPacer($this->pacer([0.0, null]))->get(self::URL);

        self::assertSame(429, $response->status());
        self::assertSame('{"error":"slow down"}', $response->body());
        self::assertSame(1, $transport->requests);
        self::assertSame(0, $transport->cancellations());
    }

    public function test_a_slot_that_does_not_fit_the_budget_returns_the_last_response(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);
        $start = hrtime(true);

        // At the edge of what the contract allows: no further off than offered.
        $response = new Http($transport)->withRetries(3)->withTimeout(5.0)
            ->withPacer($this->pacer([0.0, static fn (float $maxWait): float => $maxWait]))
            ->get(self::URL);

        self::assertSame(503, $response->status());
        self::assertSame(1, $transport->requests);
        self::assertSame(0, $transport->cancellations());
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1_000_000_000);
    }

    public function test_no_slot_after_a_transport_failure_raises_the_transport_failure(): void
    {
        $transport = new ScriptedTransport([['statusFailure' => 'connection reset'], ['status' => 200]]);

        try {
            new Http($transport)->withRetries(3)->withPacer($this->pacer([0.0, null]))->get(self::URL);
            self::fail('A transport failure was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
        }

        self::assertSame(1, $transport->requests);
    }

    public function test_a_pacer_failure_before_the_first_attempt_propagates_unchanged(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $failure = new RuntimeException('pacer store unreachable');

        try {
            new Http($transport)->withPacer($this->pacer([static fn (): never => throw $failure]))->get(self::URL);
            self::fail('The pacer failure was expected to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(0, $transport->requests);
    }

    public function test_a_pacer_failure_before_a_retry_propagates_unchanged_and_releases_the_response(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);
        $failure = new RuntimeException('pacer store unreachable');

        try {
            new Http($transport)->withRetries(3)
                ->withPacer($this->pacer([0.0, static fn (): never => throw $failure]))
                ->get(self::URL);
            self::fail('The pacer failure was expected to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(1, $transport->requests);
        self::assertSame(1, $transport->cancellations());
    }

    /** A negative wait is a slot available now, and leaves a retry's backoff as it is. */
    public function test_a_negative_wait_is_now(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 200]]);

        $response = new Http($transport)->withRetries(1)->withTimeout(5.0)->withPacer($this->pacer([-30.0]))
            ->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(2, $transport->requests);
        self::assertGreaterThan(4.9, $transport->options[0]['timeout']);
        self::assertGreaterThan($transport->options[0]['timeout'] - 0.5, $transport->options[1]['timeout']);
    }

    public function test_with_pacer_leaves_the_original_client_unpaced(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $client = new Http($transport);

        $client->withPacer($this->pacer([null]));

        self::assertSame(200, $client->get(self::URL)->status());
        self::assertSame([], $this->reservations);
    }
}
