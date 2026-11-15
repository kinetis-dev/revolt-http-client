<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Generator;
use InflateContext;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * Inflates a gzip or deflate response body as it arrives, a bounded
 * slice at a time. `inflate_add()` has no output limit, so the input is
 * cut into slices small enough that one call's output stays near a
 * mebibyte even at deflate's highest ratio, and {@see HttpResponse}
 * counts every decoded slice against the byte ceiling before taking the
 * next.
 *
 * Every failure is an UnexpectedValueException whose message is fixed
 * text describing the body's shape, never its bytes.
 *
 * @internal
 */
final class ContentDecoder
{
    /** At deflate's maximum ratio of about 1032:1, one slice inflates to about a mebibyte. */
    private const int SLICE_BYTES = 1024;

    private const string UNSUPPORTED = 'a content encoding this client does not decode';

    private const string CORRUPT = 'a compressed body that is corrupt';

    private const string TRUNCATED = 'a compressed body that is truncated';

    private int $fed = 0;

    private bool $ended = false;

    private function __construct(private readonly InflateContext $context) {}

    /**
     * The decoder a response's `Content-Encoding` values call for: none
     * for no encoding or `identity`, one for `gzip` (or `x-gzip`) or
     * `deflate` alone. Anything else, a stacked encoding included, is
     * refused rather than passed through.
     *
     * @param list<string> $contentEncoding
     * @throws UnexpectedValueException
     */
    public static function for(array $contentEncoding): ?self
    {
        $codings = [];

        foreach ($contentEncoding as $value) {
            foreach (explode(',', $value) as $coding) {
                $coding = strtolower(trim($coding));

                if ($coding !== '' && $coding !== 'identity') {
                    $codings[] = $coding;
                }
            }
        }

        if ($codings === []) {
            return null;
        }

        $encoding = match ($codings) {
            ['gzip'], ['x-gzip'] => ZLIB_ENCODING_GZIP,
            ['deflate'] => ZLIB_ENCODING_DEFLATE,
            default => throw new UnexpectedValueException(self::UNSUPPORTED),
        };

        $context = inflate_init($encoding);

        if ($context === false) {
            throw new UnexpectedValueException(self::UNSUPPORTED);
        }

        return new self($context);
    }

    /**
     * The decoded output of $wire, one slice of input at a time.
     *
     * @return Generator<int, string>
     * @throws UnexpectedValueException
     */
    public function push(#[SensitiveParameter] string $wire): Generator
    {
        foreach (str_split($wire, self::SLICE_BYTES) as $slice) {
            // Bytes after the end of the compressed stream are not part
            // of the body.
            if ($this->ended) {
                throw new UnexpectedValueException(self::CORRUPT);
            }

            $this->fed += strlen($slice);
            $output = @inflate_add($this->context, $slice, ZLIB_SYNC_FLUSH);

            if ($output === false) {
                throw new UnexpectedValueException(self::CORRUPT);
            }

            if (inflate_get_status($this->context) === ZLIB_STREAM_END) {
                $this->ended = true;

                if (inflate_get_read_len($this->context) < $this->fed) {
                    throw new UnexpectedValueException(self::CORRUPT);
                }
            }

            if ($output !== '') {
                yield $output;
            }
        }
    }

    /**
     * What is left once the wire body has ended. A compressed stream that
     * never reached its own end is truncated.
     *
     * @return Generator<int, string>
     * @throws UnexpectedValueException
     */
    public function finish(): Generator
    {
        if ($this->ended) {
            return;
        }

        $output = @inflate_add($this->context, '', ZLIB_FINISH);

        if ($output === false || inflate_get_status($this->context) !== ZLIB_STREAM_END) {
            throw new UnexpectedValueException(self::TRUNCATED);
        }

        $this->ended = true;

        if ($output !== '') {
            yield $output;
        }
    }
}
