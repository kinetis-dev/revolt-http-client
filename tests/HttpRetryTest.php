<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\Tests\Fixtures\ScriptedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one retry layer, and the one deadline it runs inside, driven by a
 * scripted transport: one step per attempt, the last step repeating.
 */
final class HttpRetryTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /** @param list<array<string, mixed>> $script */
    private function client(array $script, int $retries = 2, float $timeout = 5.0): Http
    {
        return new Http(new ScriptedTransport($script))->withRetries($retries)->withTimeout($timeout);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function retryableStatusProvider(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_a_retryable_status_is_sent_again_until_one_answer_is_not(int $status): void
    {
        $transport = new ScriptedTransport([['status' => $status], ['status' => $status], ['status' => 200]]);

        $response = new Http($transport)->withRetries(3)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(3, $transport->requests);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function terminalStatusProvider(): iterable
    {
        foreach ([200, 301, 400, 404, 409, 418, 501, 507] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_a_status_that_is_not_retryable_is_returned_at_once(int $status): void
    {
        $transport = new ScriptedTransport([['status' => $status]]);

        self::assertSame($status, new Http($transport)->withRetries(3)->get(self::URL)->status());
        self::assertSame(1, $transport->requests);
    }

    public function test_retries_are_bounded_and_the_last_answer_is_returned(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);

        self::assertSame(503, new Http($transport)->withRetries(2)->get(self::URL)->status());
        self::assertSame(3, $transport->requests);
    }

    public function test_no_retries_configured_means_exactly_one_attempt(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);

        self::assertSame(503, new Http($transport)->get(self::URL)->status());
        self::assertSame(1, $transport->requests);
    }

    public function test_a_transport_failure_is_retried_and_raised_when_it_outlives_the_retries(): void
    {
        $transport = new ScriptedTransport([['statusFailure' => 'connection reset']]);

        try {
            new Http($transport)->withRetries(2)->get(self::URL);
            self::fail('A transport failure was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
            self::assertSame('GET https://api.example.com failed before any response arrived.', $e->getMessage());
        }

        self::assertSame(3, $transport->requests);
        self::assertSame(3, $transport->cancellations());
    }

    public function test_a_transport_failure_recovers_when_a_later_attempt_answers(): void
    {
        $transport = new ScriptedTransport([['statusFailure' => 'connection reset'], ['status' => 200]]);

        self::assertSame(200, new Http($transport)->withRetries(2)->get(self::URL)->status());
        self::assertSame(2, $transport->requests);
    }

    /**
     * A transport that refuses to construct the request answers the same
     * way however many times it is asked, so it is refused rather than
     * repeated — and the vendor message it refused with never surfaces.
     */
    public function test_a_transport_that_refuses_to_build_the_request_is_not_retried(): void
    {
        $transport = new ScriptedTransport([['throw' => true]]);

        try {
            new Http($transport)->withRetries(3)->get(self::URL);
            self::fail('A refused request was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
        }

        self::assertSame(1, $transport->requests);
    }

    public function test_an_oversized_response_is_not_retried(): void
    {
        $transport = new ScriptedTransport([['status' => 503, 'chunks' => ['xxxxxxxx'], 'progressBeforeStatus' => 8]]);

        try {
            new Http($transport)->withRetries(3)->withMaxResponseBytes(4)->get(self::URL);
            self::fail('An over-large response was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertSame(1, $transport->requests);
    }

    public function test_a_deadline_spent_waiting_for_the_status_stops_the_operation(): void
    {
        $transport = new ScriptedTransport([['status' => 503, 'statusDelay' => 0.15]]);

        try {
            new Http($transport)->withRetries(3)->withTimeout(0.1)->get(self::URL);
            self::fail('A spent deadline was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        // Not retried past the budget, and the response was released.
        self::assertSame(1, $transport->requests);
        self::assertSame(1, $transport->cancellations());
    }

    public function test_a_read_after_the_deadline_is_a_timeout_rather_than_a_read(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'chunks' => ['{"ok":true}']]]);
        $response = new Http($transport)->withTimeout(0.05)->get(self::URL);

        usleep(80_000);

        try {
            $response->body();
            self::fail('A read past the deadline was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }
    }

    public function test_the_total_timeout_is_not_renewed_for_each_attempt(): void
    {
        $transport = new ScriptedTransport([['status' => 503, 'statusDelay' => 0.05]]);
        $start = microtime(true);

        try {
            new Http($transport)->withRetries(5)->withTimeout(0.3)->get(self::URL);
        } catch (HttpRequestException) {
            // Either outcome is inside the budget; the elapsed time is
            // what this test is about.
        }

        self::assertLessThan(0.6, microtime(true) - $start);
    }

    /**
     * Backoff doubles from 100 ms and waits inside the one deadline, so a
     * budget too small for the next one returns the answer already in
     * hand rather than spending past it.
     */
    public function test_a_budget_too_small_for_a_backoff_returns_the_last_response(): void
    {
        $transport = new ScriptedTransport([['status' => 503]]);

        self::assertSame(503, new Http($transport)->withRetries(5)->withTimeout(0.05)->get(self::URL)->status());
        self::assertSame(1, $transport->requests);
    }

    public function test_each_attempt_is_given_only_what_is_left_of_the_budget(): void
    {
        $transport = new ScriptedTransport([['status' => 503, 'statusDelay' => 0.05]]);

        new Http($transport)->withRetries(1)->withTimeout(1.0)->get(self::URL);

        self::assertGreaterThan($transport->options[1]['timeout'], $transport->options[0]['timeout']);
        self::assertSame($transport->options[0]['timeout'], $transport->options[0]['max_duration']);
    }

    public function test_a_per_call_timeout_overrides_the_clients_own(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        new Http($transport)->withTimeout(30.0)->send('GET', self::URL, ['timeout' => 2.0])->status();

        self::assertLessThanOrEqual(2.0, $transport->options[0]['timeout']);
    }

    public function test_a_replayable_body_is_sent_again_byte_for_byte(): void
    {
        $transport = new ScriptedTransport([['status' => 503], ['status' => 200]]);

        new Http($transport)->withRetries(1)->send('POST', self::URL, ['body' => 'a=1'])->status();

        self::assertSame('a=1', $transport->options[0]['body']);
        self::assertSame('a=1', $transport->options[1]['body']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonReplayableBodyProvider(): iterable
    {
        yield 'stream resource' => [fopen('php://memory', 'r')];
        yield 'Closure' => [static fn (): string => 'chunk'];
    }

    #[DataProvider('nonReplayableBodyProvider')]
    public function test_a_body_that_cannot_be_replayed_is_refused_by_a_retrying_client(mixed $body): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        try {
            new Http($transport)->withRetries(1)->send('POST', self::URL, ['body' => $body]);
            self::fail('A body that cannot be replayed was expected to be refused.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
        }

        self::assertSame(0, $transport->requests);
    }

    #[DataProvider('nonReplayableBodyProvider')]
    public function test_a_body_that_cannot_be_replayed_is_accepted_without_retries(mixed $body): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);

        self::assertSame(200, new Http($transport)->send('POST', self::URL, ['body' => $body])->status());
        self::assertSame(1, $transport->requests);
    }
}
