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
 * Content decoding, driven from the wire chunks a scripted transport
 * delivers, so a test sees how far a transfer got before it stopped.
 */
final class HttpCompressionTest extends TestCase
{
    private const string URL = 'https://api.example.com/x';

    /** @return iterable<string, array{Closure(HttpResponse): mixed}> */
    public static function readProvider(): iterable
    {
        yield 'body' => [static fn (HttpResponse $r): string => $r->body()];
        yield 'chunks' => [static fn (HttpResponse $r): array => iterator_to_array($r->chunks(), false)];
    }

    /**
     * 32 MiB of zeros compresses to about 32 KiB, delivered a kibibyte
     * at a time. Each wire chunk decodes to about a mebibyte, so a
     * one-mebibyte ceiling is crossed after a few chunks of the
     * thirty-odd: the decoded bytes are counted as they are produced,
     * not after the body is whole.
     *
     * @param Closure(HttpResponse): mixed $read
     */
    #[DataProvider('readProvider')]
    public function test_a_gzip_bomb_is_refused_before_it_is_decoded_whole(Closure $read): void
    {
        $chunks = str_split((string) gzencode(str_repeat("\0", 32 * 1024 * 1024), 9), 1024);
        $transport = new ScriptedTransport([[
            'status' => 200,
            'headers' => ['content-encoding' => ['gzip']],
            'chunks' => $chunks,
        ]]);

        try {
            $read(new Http($transport)->withMaxResponseBytes(1024 * 1024)->get(self::URL));
            self::fail('A body decoding past the ceiling was expected to raise.');
        } catch (HttpRequestException $e) {
            self::assertSame(HttpFailure::ResponseTooLarge, $e->category);
        }

        self::assertGreaterThan(20, count($chunks));
        self::assertLessThan(4, $transport->responses[0]->chunksDelivered);
        self::assertSame(1, $transport->cancellations());
    }

    /**
     * @param Closure(HttpResponse): mixed $read
     */
    #[DataProvider('readProvider')]
    public function test_gzip_and_deflate_bodies_are_decoded(Closure $read): void
    {
        $text = str_repeat('a compressible sentence. ', 400);

        foreach (['gzip' => gzencode($text), 'x-gzip' => gzencode($text), 'deflate' => gzcompress($text)] as $coding => $wire) {
            $response = $this->respond($coding, str_split((string) $wire, 100));

            self::assertSame($text, implode('', (array) $read($response)), $coding);
        }
    }

    /** @return iterable<string, array{string, list<string>, string}> */
    public static function undecodableProvider(): iterable
    {
        $gzip = (string) gzencode(str_repeat('hello world ', 200));
        $corrupt = $gzip;
        $corrupt[20] = chr(ord($corrupt[20]) ^ 0xFF);
        $corrupt[21] = chr(ord($corrupt[21]) ^ 0xFF);

        yield 'corrupt gzip' => ['gzip', [$corrupt], 'a compressed body that is corrupt'];
        yield 'not gzip at all' => ['gzip', ['plain text'], 'a compressed body that is corrupt'];
        yield 'bytes after the gzip stream' => ['gzip', [$gzip . 'trailing'], 'a compressed body that is corrupt'];
        yield 'truncated gzip' => ['gzip', [substr($gzip, 0, intdiv(strlen($gzip), 2))], 'a compressed body that is truncated'];
        yield 'gzip without its trailer' => ['gzip', [substr($gzip, 0, -4)], 'a compressed body that is truncated'];
        yield 'raw deflate' => ['deflate', [(string) gzdeflate('hello')], 'a compressed body that is corrupt'];
        yield 'brotli' => ['br', ['anything'], 'a content encoding this client does not decode'];
        yield 'zstd' => ['zstd', ['anything'], 'a content encoding this client does not decode'];
        yield 'stacked' => ['gzip, deflate', ['anything'], 'a content encoding this client does not decode'];
    }

    /**
     * @param list<string> $chunks
     */
    #[DataProvider('undecodableProvider')]
    public function test_a_body_that_cannot_be_decoded_fails_on_both_read_paths(string $coding, array $chunks, string $problem): void
    {
        foreach (self::readProvider() as $path => [$read]) {
            try {
                $read($this->respond($coding, $chunks));
                self::fail("Reading {$path} was expected to raise.");
            } catch (HttpRequestException $e) {
                self::assertSame(HttpFailure::Conversion, $e->category, $path);
                self::assertSame("GET https://api.example.com returned HTTP 200 with {$problem}.", $e->getMessage(), $path);
            }
        }
    }

    public function test_identity_and_an_absent_encoding_pass_bytes_through(): void
    {
        $gzip = (string) gzencode('kept compressed');

        self::assertSame($gzip, $this->respond('identity', [$gzip])->body());
        self::assertSame($gzip, new Http(new ScriptedTransport([['status' => 200, 'chunks' => [$gzip]]]))->get(self::URL)->body());
    }

    /** A HEAD answer and a 204 carry the encoding of a body they do not send. */
    public function test_an_empty_body_needs_no_decoding(): void
    {
        self::assertSame('', $this->respond('br', [])->body());
        self::assertSame('', $this->respond('gzip', [])->body());
    }

    /**
     * @param list<string> $chunks
     */
    private function respond(string $coding, array $chunks): HttpResponse
    {
        return new Http(new ScriptedTransport([[
            'status' => 200,
            'headers' => ['content-encoding' => [$coding]],
            'chunks' => $chunks,
        ]]))->get(self::URL);
    }
}
