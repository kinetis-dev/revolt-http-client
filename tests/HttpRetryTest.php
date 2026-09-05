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
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The one retry layer and the one total timeout, driven through a mock
 * transport so every case is decided by the client's own logic rather
 * than by a server's timing.
 */
final class HttpRetryTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /**
     * @param list<int|string> $answers a status to answer with, or a
     *     transport error message to fail the attempt with
     */
    private static function transportAnswering(array $answers, ?array &$seen = null): MockHttpClient
    {
        $index = 0;

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($answers, &$index, &$seen): MockResponse {
            $answer = $answers[$index] ?? end($answers);
            ++$index;
            $seen[] = $options;

            return is_int($answer)
                ? new MockResponse('{"attempt":' . $index . '}', ['http_code' => $answer])
                : new MockResponse('', ['error' => $answer]);
        });
    }

    public function test_a_retryable_status_is_sent_again_until_one_answer_is_not(): void
    {
        $transport = self::transportAnswering([503, 500, 200]);

        $response = new Http($transport)->withRetries(3)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(3, $transport->getRequestsCount());
    }

    public function test_a_status_that_is_not_retryable_is_returned_at_once(): void
    {
        $transport = self::transportAnswering([404, 200]);

        $response = new Http($transport)->withRetries(3)->get(self::URL);

        self::assertSame(404, $response->status());
        self::assertSame(1, $transport->getRequestsCount());
    }

    /**
     * The retry count is a count of *extra* attempts, and running out of
     * them is not itself a failure: the last answer the server gave is
     * the answer the caller gets.
     */
    public function test_retries_are_bounded_and_the_last_answer_is_returned(): void
    {
        $transport = self::transportAnswering([503]);

        $response = new Http($transport)->withRetries(1)->get(self::URL);

        self::assertSame(503, $response->status());
        self::assertSame(2, $transport->getRequestsCount());
    }

    public function test_no_retries_configured_means_exactly_one_attempt(): void
    {
        $transport = self::transportAnswering([503]);

        self::assertSame(503, new Http($transport)->get(self::URL)->status());
        self::assertSame(1, $transport->getRequestsCount());
    }

    /**
     * A transport that refuses to build the request fails where the
     * request is made rather than where it is read. It is one kind of
     * transport failure, not a separate rule, so it is retried on the
     * same terms as one that surfaced from the wire.
     */
    public function test_a_transport_that_refuses_to_build_the_request_is_retried_too(): void
    {
        $transport = new ScriptedTransport([['throw' => true], ['status' => 200]]);

        self::assertSame(200, new Http($transport)->withRetries(2)->get(self::URL)->status());
        self::assertSame(2, $transport->requests);
    }

    public function test_a_refused_request_that_outlives_the_retries_is_raised_by_send(): void
    {
        $transport = new ScriptedTransport([['throw' => true]]);

        try {
            new Http($transport)->withRetries(2)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
        }

        self::assertSame(3, $transport->requests);
    }

    /**
     * The attempt that finally fails is released like every one before
     * it. Throwing leaves nobody holding the response, so a response
     * left open would be one connection lost per failed operation.
     */
    public function test_the_last_failed_response_is_released_before_the_failure_is_raised(): void
    {
        $transport = new ScriptedTransport([['statusFailure' => 'the connection dropped']]);

        try {
            new Http($transport)->withRetries(2)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
        }

        self::assertSame(3, $transport->requests);
        self::assertSame(3, $transport->cancellations());
    }

    /**
     * Waiting for the status spends part of the budget, so the deadline
     * is asked again before a response is handed over — otherwise an
     * operation could return a response that every later read of it
     * would refuse.
     */
    public function test_a_deadline_spent_waiting_for_the_status_stops_the_operation(): void
    {
        $transport = new ScriptedTransport([['status' => 200, 'statusDelay' => 0.35]]);

        try {
            new Http($transport)->withRetries(1)->withTimeout(0.2)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        self::assertSame(1, $transport->requests);
        self::assertSame(1, $transport->cancellations());
    }

    /**
     * The budget covers reading the response too, and on a clock that
     * only moves forward. A read attempted after it has run out is a
     * timeout, not a transport failure — and the transport is never
     * asked.
     */
    public function test_a_read_after_the_deadline_is_a_timeout_rather_than_a_read(): void
    {
        $transport = new ScriptedTransport([['status' => 200]]);
        $response = new Http($transport)->withTimeout(0.05)->get(self::URL);

        usleep(120_000);

        try {
            $response->status();

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('ran out of its 0.050s total timeout', $e->getMessage());
        }

        self::assertSame(1, $transport->cancellations());
    }

    /**
     * @return iterable<string, array{step: array<string, mixed>, read: ?Closure(HttpResponse): mixed}>
     */
    public static function transportIgnoringItsDeadlineProvider(): iterable
    {
        yield 'blocking inside request()' => [
            'step' => ['requestDelay' => 0.35],
            'read' => null,
        ];

        yield 'blocking inside request() and then refusing it' => [
            'step' => ['requestDelay' => 0.35, 'throw' => true],
            'read' => null,
        ];

        yield 'blocking while the status arrives' => [
            'step' => ['statusDelay' => 0.35],
            'read' => static fn (HttpResponse $response): int => $response->status(),
        ];

        yield 'blocking while the headers arrive, read through the body' => [
            'step' => ['readDelay' => 0.35],
            'read' => static fn (HttpResponse $response): string => $response->body(),
        ];

        yield 'reporting progress after the deadline' => [
            'step' => ['progressDelay' => 0.35, 'chunks' => ['a', 'b']],
            'read' => static fn (HttpResponse $response): string => $response->body(),
        ];
    }

    /**
     * `timeout` and `max_duration` are given to every attempt, and a
     * transport is free to ignore both. The deadline is this package's
     * to enforce, so each of these blocks well past a 0.15s budget and
     * each is a Timeout — never a Transport failure, and never a
     * successful answer from an operation that is already over.
     *
     * @param array<string, mixed> $step
     * @param ?Closure(HttpResponse): mixed $read
     */
    #[DataProvider('transportIgnoringItsDeadlineProvider')]
    public function test_a_transport_that_ignores_its_deadline_still_times_out(array $step, ?Closure $read): void
    {
        $transport = new ScriptedTransport([$step]);
        $client = new Http($transport)->withTimeout(0.15);

        try {
            $response = $client->get(self::URL);

            if ($read === null) {
                self::fail('Expected HttpRequestException.');
            }

            $read($response);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('ran out of its 0.150s total timeout', $e->getMessage());
        }

        self::assertSame(1, $transport->requests);
        self::assertSame(count($transport->responses), $transport->cancellations());
    }

    /**
     * The same holds for a retrying client: the deadline is spent by the
     * first attempt, so nothing is sent again on a budget that is gone.
     */
    public function test_a_transport_that_ignores_its_deadline_is_not_retried_past_it(): void
    {
        $transport = new ScriptedTransport([['requestDelay' => 0.35, 'status' => 503]]);

        try {
            new Http($transport)->withRetries(3)->withTimeout(0.15)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
        }

        self::assertSame(1, $transport->requests);
    }

    public function test_a_transport_failure_is_retried_the_same_way_a_retryable_status_is(): void
    {
        $transport = self::transportAnswering(['connection refused', 200]);

        self::assertSame(200, new Http($transport)->withRetries(2)->get(self::URL)->status());
        self::assertSame(2, $transport->getRequestsCount());
    }

    /**
     * A transport failure that outlives the retries has no response to
     * hand back, so a retrying client raises it where the decision was
     * made rather than storing a response that can only fail on read.
     */
    public function test_a_transport_failure_that_outlives_the_retries_is_raised_by_send(): void
    {
        $transport = self::transportAnswering(['connection refused']);

        try {
            new Http($transport)->withRetries(1)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
            self::assertSame('GET https://api.example.com failed before any response arrived.', $e->getMessage());
        }

        self::assertSame(2, $transport->getRequestsCount());
    }

    /**
     * Without retries there is nothing to decide before returning, so
     * the response stays deferred and a transport failure surfaces from
     * the first read — the path that lets concurrently() overlap
     * requests.
     */
    public function test_without_retries_a_transport_failure_surfaces_from_the_read_instead(): void
    {
        $response = new Http(self::transportAnswering(['connection refused']))->get(self::URL);

        $this->expectException(HttpRequestException::class);
        $this->expectExceptionMessage('failed before any response arrived');

        $response->status();
    }

    /**
     * The budget covers every attempt and every backoff between them.
     * With 5 retries allowed and a quarter-second budget, the second
     * backoff does not fit, so the operation ends there instead of
     * spending five fresh timeouts.
     */
    public function test_the_total_timeout_is_not_renewed_for_each_attempt(): void
    {
        $transport = self::transportAnswering([503]);
        $startedAt = microtime(true);

        try {
            new Http($transport)->withRetries(5)->withTimeout(0.25)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('GET https://api.example.com ran out of its 0.250s total timeout', $e->getMessage());
        }

        self::assertSame(2, $transport->getRequestsCount());
        self::assertLessThan(0.5, microtime(true) - $startedAt);
    }

    public function test_a_budget_too_small_for_a_backoff_stops_after_the_first_attempt(): void
    {
        $transport = self::transportAnswering([503]);

        try {
            new Http($transport)->withRetries(3)->withTimeout(0.05)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('after 1 attempt(s)', $e->getMessage());
        }

        self::assertSame(1, $transport->getRequestsCount());
    }

    /** Each attempt is handed what is left of the budget, not the whole of it again. */
    public function test_each_attempt_is_given_only_what_is_left_of_the_budget(): void
    {
        $seen = [];

        new Http(self::transportAnswering([503, 200], $seen))->withRetries(2)->withTimeout(5.0)->get(self::URL);

        self::assertCount(2, $seen);
        self::assertLessThan($seen[0]['max_duration'], $seen[1]['max_duration']);
        self::assertGreaterThan(0.0, $seen[1]['max_duration']);
    }

    public function test_a_per_call_timeout_overrides_the_clients_own(): void
    {
        $seen = [];

        new Http(self::transportAnswering([200], $seen))->withTimeout(30.0)->send('GET', self::URL, ['timeout' => 2.0]);

        self::assertLessThanOrEqual(2.0, $seen[0]['max_duration']);
    }

    public function test_every_attempt_forbids_redirects(): void
    {
        $seen = [];

        new Http(self::transportAnswering([503, 200], $seen))->withRetries(1)->get(self::URL);

        self::assertSame([0, 0], array_column($seen, 'max_redirects'));
    }

    /** A replayed request is the same request: the same bytes go out again. */
    public function test_a_replayable_body_is_sent_again_byte_for_byte(): void
    {
        $seen = [];

        new Http(self::transportAnswering([503, 200], $seen))->withRetries(1)->post(self::URL, ['sku' => 'A1']);

        self::assertSame('{"sku":"A1"}', $seen[0]['body']);
        self::assertSame($seen[0]['body'], $seen[1]['body']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonReplayableBodyProvider(): iterable
    {
        yield 'a stream resource' => [fopen('php://memory', 'r')];
        yield 'a Closure' => [static fn (): string => 'chunk'];
    }

    /**
     * A stream and a Closure are read once. A retrying client refuses
     * them outright rather than sending a second request with a body
     * that has already been consumed.
     *
     */
    #[DataProvider('nonReplayableBodyProvider')]
    public function test_a_body_that_cannot_be_replayed_is_refused_by_a_retrying_client(mixed $body): void
    {
        $transport = self::transportAnswering([200]);

        try {
            new Http($transport)->withRetries(2)->send('POST', self::URL, ['body' => $body]);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::InvalidRequest, $e->category);
            self::assertStringContainsString('cannot be replayed', $e->getMessage());
        }

        self::assertSame(0, $transport->getRequestsCount());
    }

    /** The same body is fine on a client that will only ever send it once. */
    public function test_a_body_that_cannot_be_replayed_is_accepted_without_retries(): void
    {
        $transport = self::transportAnswering([200]);

        $response = new Http($transport)->send('POST', self::URL, ['body' => static fn (): string => '']);

        self::assertSame(200, $response->status());
        self::assertSame(1, $transport->getRequestsCount());
    }

    /**
     * Building the request can fail synchronously, inside the transport.
     * That exception comes from a library holding the full URL, so it is
     * replaced rather than wrapped, and nothing of it reaches the caller.
     */
    public function test_a_synchronous_transport_failure_is_replaced_by_this_packages_own(): void
    {
        $transport = new MockHttpClient(static function (): MockResponse {
            throw new RuntimeException('cannot connect to https://user:SENTINEL@api.example.com/orders?key=SENTINEL');
        });

        try {
            new Http($transport)->get(self::URL);

            self::fail('Expected HttpRequestException.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Transport, $e->category);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
            self::assertSame('GET https://api.example.com failed before any response arrived.', $e->getMessage());
        }
    }
}
