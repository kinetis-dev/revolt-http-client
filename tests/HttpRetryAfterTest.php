<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A retryable response's `Retry-After` sets the least the next attempt
 * waits, inside the one operation deadline, over Symfony's
 * `MockHttpClient`.
 */
final class HttpRetryAfterTest extends TestCase
{
    private const string URL = 'https://api.example.com/orders';

    /** @var list<float> when each attempt was sent, on a monotonic clock */
    private array $sentAt = [];

    /** A transport answering $status with $retryAfter, then 200 to every later attempt. */
    private function transport(int $status, string $retryAfter): MockHttpClient
    {
        return new MockHttpClient(function () use ($status, $retryAfter): ResponseInterface {
            $this->sentAt[] = hrtime(true) / 1_000_000_000;

            return count($this->sentAt) === 1
                ? new MockResponse('', ['http_code' => $status, 'response_headers' => ['Retry-After' => $retryAfter]])
                : new MockResponse('{}');
        });
    }

    private function gap(): float
    {
        return $this->sentAt[1] - $this->sentAt[0];
    }

    public function test_delta_seconds_hold_the_next_attempt_back_for_at_least_that_long(): void
    {
        $mock = $this->transport(429, '1');

        $response = new Http($mock)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(2, $mock->getRequestsCount());
        self::assertGreaterThanOrEqual(0.99, $this->gap());
    }

    /** The server's wait is never shortened to fit, so the answer in hand is returned. */
    public function test_a_wait_longer_than_the_remaining_budget_returns_the_response_after_one_attempt(): void
    {
        $mock = $this->transport(429, '30');
        $start = hrtime(true);

        $response = new Http($mock)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(429, $response->status());
        self::assertSame('30', $response->header('Retry-After'));
        self::assertSame(1, $mock->getRequestsCount());
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1_000_000_000);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function httpDateFormatProvider(): iterable
    {
        yield 'IMF-fixdate' => ['D, d M Y H:i:s \G\M\T'];
        yield 'RFC 850' => ['l, d-M-y H:i:s \G\M\T'];
        yield 'asctime' => ['D M j H:i:s Y'];
    }

    #[DataProvider('httpDateFormatProvider')]
    public function test_an_http_date_past_the_budget_returns_the_response_after_one_attempt(string $format): void
    {
        $mock = $this->transport(503, gmdate($format, time() + 60));

        $response = new Http($mock)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(503, $response->status());
        self::assertSame(1, $mock->getRequestsCount());
    }

    public function test_an_http_date_holds_the_next_attempt_back_until_that_time(): void
    {
        $until = time() + 2;
        $mock = $this->transport(503, gmdate('D, d M Y H:i:s \G\M\T', $until));

        $response = new Http($mock)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(2, $mock->getRequestsCount());
        // The wait is measured on the wall clock and slept on the event
        // loop's monotonic one; 10 ms covers the drift between the two.
        self::assertGreaterThanOrEqual($until - 0.01, microtime(true));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function noWaitProvider(): iterable
    {
        yield 'date in the past' => ['Sun, 06 Nov 1994 08:49:37 GMT'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-30'];
        yield 'fraction' => ['1.5'];
        yield 'relative words' => ['tomorrow'];
        yield 'date out of range' => ['Sun, 32 Nov 2099 08:49:37 GMT'];
        yield 'empty' => [''];
    }

    /** What asks for no wait leaves the ordinary 100 ms first backoff. */
    #[DataProvider('noWaitProvider')]
    public function test_a_value_asking_for_no_wait_keeps_the_exponential_backoff(string $retryAfter): void
    {
        $mock = $this->transport(429, $retryAfter);

        $response = new Http($mock)->withRetries(3)->withTimeout(5.0)->get(self::URL);

        self::assertSame(200, $response->status());
        self::assertSame(2, $mock->getRequestsCount());
        self::assertGreaterThanOrEqual(0.099, $this->gap());
        self::assertLessThan(0.5, $this->gap());
    }

    /** Only a retryable status is retried, whatever it says about waiting. */
    public function test_retry_after_on_a_status_that_is_not_retryable_changes_nothing(): void
    {
        $mock = $this->transport(301, '1');

        self::assertSame(301, new Http($mock)->withRetries(3)->get(self::URL)->status());
        self::assertSame(1, $mock->getRequestsCount());
    }
}
