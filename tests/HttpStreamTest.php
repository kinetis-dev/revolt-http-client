<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests;

use Closure;
use Fiber;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\RevoltHttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `HttpResponse::chunks()` against a real server that sends its body
 * over time: what arrives when, what the budget stops, and what the
 * server sees when the caller stops reading.
 */
final class HttpStreamTest extends TestCase
{
    private const string HOST = '127.0.0.1:8095';

    /** @var resource */
    private static $serverProcess;

    private static ?HttpClientInterface $transport = null;

    /** @var list<string> */
    private array $markFiles = [];

    public static function setUpBeforeClass(): void
    {
        // Several workers, so a stream a test abandoned does not hold up
        // the next test's request behind it.
        self::$serverProcess = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/stream-server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4'],
        );

        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://' . self::HOST, timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        self::fail('The stream fixture server never started accepting connections.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
    }

    protected function tearDown(): void
    {
        foreach ($this->markFiles as $file) {
            @unlink($file);
        }
    }

    private function http(): Http
    {
        return new Http(self::$transport ??= AmpHttpClientFactory::create())->withBaseUrl('http://' . self::HOST);
    }

    private function markFile(): string
    {
        return $this->markFiles[] = sys_get_temp_dir() . '/kinetis-stream-' . bin2hex(random_bytes(6));
    }

    private static function pause(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    public function test_pieces_arrive_as_the_server_sends_them(): void
    {
        $start = microtime(true);
        $arrivals = [];
        $body = '';

        foreach ($this->http()->get('/frames/5/300')->throw()->chunks() as $piece) {
            $arrivals[] = microtime(true) - $start;
            $body .= $piece;
        }

        self::assertSame("data: 0\n\ndata: 1\n\ndata: 2\n\ndata: 3\n\ndata: 4\n\n", $body);
        // The server spends 1.2 s sending; a buffered body would arrive
        // as one piece at the end of it.
        self::assertLessThan(0.6, $arrivals[0]);
        self::assertGreaterThan(0.9, $arrivals[count($arrivals) - 1] - $arrivals[0]);
    }

    public function test_the_loop_keeps_running_while_a_stream_is_read(): void
    {
        $ticks = 0;
        $timer = EventLoop::repeat(0.1, static function () use (&$ticks): void {
            ++$ticks;
        });

        $fiber = new Fiber(function () use ($timer): int {
            $pieces = 0;

            foreach ($this->http()->get('/frames/5/300')->chunks() as $piece) {
                ++$pieces;
            }

            EventLoop::cancel($timer);

            return $pieces;
        });
        $fiber->start();

        EventLoop::run();

        self::assertSame(5, $fiber->getReturn());
        // About twelve ticks fit in the 1.2 s stream; a read that
        // blocked the process would leave the timer none.
        self::assertGreaterThanOrEqual(8, $ticks);
    }

    public function test_an_undeclared_body_past_the_ceiling_stops_before_the_piece_that_passes_it(): void
    {
        $received = 0;

        try {
            foreach ($this->http()->withMaxResponseBytes(4096)->get('/bytes/65536')->chunks() as $piece) {
                $received += strlen($piece);
            }

            self::fail('A stream past the ceiling was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertLessThanOrEqual(4096, $received);
    }

    public function test_a_declared_length_past_the_ceiling_is_refused_before_streaming(): void
    {
        $response = $this->http()->withMaxResponseBytes(4096)->get('/declared/8192');

        try {
            $response->chunks();
            self::fail('A declared length past the ceiling was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }
    }

    public function test_a_body_exactly_at_the_ceiling_streams_whole(): void
    {
        $body = implode('', iterator_to_array($this->http()->withMaxResponseBytes(4096)->get('/bytes/4096')->chunks(), false));

        self::assertSame(4096, strlen($body));
    }

    public function test_a_stalled_stream_times_out_at_the_operation_deadline(): void
    {
        $response = $this->http()->withTimeout(0.5)->get('/stall');
        $start = microtime(true);
        $pieces = [];

        try {
            foreach ($response->chunks() as $piece) {
                $pieces[] = $piece;
            }

            self::fail('A stalled stream was expected to time out.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Timeout, $e->category);
            self::assertStringContainsString('after 1 attempt(s)', $e->getMessage());
        }

        self::assertSame(["data: first\n\n"], $pieces);
        self::assertSame(1, $response->attempts());
        self::assertGreaterThan(0.3, microtime(true) - $start);
        self::assertLessThan(1.5, microtime(true) - $start);
    }

    public function test_leaving_the_loop_early_closes_the_connection(): void
    {
        $mark = $this->markFile();
        $response = $this->http()->withTimeout(10)->get('/until-abort', ['mark' => $mark]);

        foreach ($response->chunks() as $piece) {
            break;
        }

        // $response is still held, so only the stream's own release can
        // have closed the connection the server is writing to.
        $deadline = microtime(true) + 3.0;

        while (!file_exists($mark) && microtime(true) < $deadline) {
            self::pause(0.05);
        }

        self::assertFileExists($mark, 'The server kept writing to a connection nothing reads.');
        self::assertStringStartsWith('aborted after', (string) file_get_contents($mark));

        try {
            $response->body();
            self::fail('A body read after the stream was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Consumed, $e->category);
        }
    }

    public function test_a_retried_get_streams_only_the_final_attempt(): void
    {
        $response = $this->http()->withRetries(2)->withTimeout(5)->get('/flaky', ['mark' => $this->markFile()]);

        self::assertSame('final', implode('', iterator_to_array($response->throw()->chunks(), false)));
        self::assertSame(2, $response->attempts());
    }

    public function test_an_error_status_streams_its_body_with_status_and_headers_still_readable(): void
    {
        $response = $this->http()->get('/missing');

        self::assertSame([], iterator_to_array($response->chunks(), false));
        self::assertSame(404, $response->status());
        self::assertStringContainsString('text/event-stream', (string) $response->header('Content-Type'));
    }

    /**
     * @return iterable<string, array{0: Closure(HttpResponse): mixed, 1: Closure(HttpResponse): mixed}>
     */
    public static function competingReadProvider(): iterable
    {
        $chunks = static fn (HttpResponse $r): mixed => $r->chunks();

        yield 'body after chunks' => [$chunks, static fn (HttpResponse $r): string => $r->body()];
        yield 'json after chunks' => [$chunks, static fn (HttpResponse $r): array => $r->json()];
        yield 'chunks after chunks' => [$chunks, $chunks];
        yield 'chunks after body' => [static fn (HttpResponse $r): string => $r->body(), $chunks];
    }

    /**
     * @param Closure(HttpResponse): mixed $first
     * @param Closure(HttpResponse): mixed $second
     */
    #[DataProvider('competingReadProvider')]
    public function test_a_body_is_read_once_either_buffered_or_streamed(Closure $first, Closure $second): void
    {
        $response = $this->http()->get('/frames/2/0');
        $first($response);

        try {
            $second($response);
            self::fail('A second read of the body was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::Consumed, $e->category);
        }
    }
}
