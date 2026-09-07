<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use SensitiveParameter;

/**
 * What one operation may consume: time and bytes, plus the method and
 * origin every failure of it is named by. {@see Http} builds a fresh
 * instance per call, so nothing here outlives the operation it was made
 * for. The deadline is read from `hrtime()`, which only moves forward,
 * and covers every attempt, every backoff, and every read.
 *
 * {@see remaining()} and {@see expired()} are `@phpstan-impure`: each
 * answer is a reading rather than a property, and the later readings are
 * what bound a transport ignoring the duration it was handed.
 *
 * @internal
 */
final class ResponseBudget
{
    /**
     * Set by {@see progressGuard()} when a transfer passed the ceiling.
     * The transport reports that abort as a failure of its own, and this
     * flag is how {@see HttpResponse} tells it apart from a dropped
     * connection without consulting the exception's type.
     */
    public bool $exceeded = false;

    /** Wire attempts made so far, for the timeout message. */
    public int $attempts = 0;

    private readonly float $expiresAt;

    public function __construct(
        public readonly string $method,
        public readonly string $origin,
        public readonly float $timeout,
        public readonly int $maxBytes,
    ) {
        $this->expiresAt = self::now() + $timeout;
    }

    /**
     * What is left, in seconds; zero or less once the budget is spent.
     *
     * @phpstan-impure
     */
    public function remaining(): float
    {
        return $this->expiresAt - self::now();
    }

    /** @phpstan-impure */
    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /**
     * The transport's own progress hook, owned here rather than exposed:
     * the one place inside a transfer this package runs, so it carries
     * both bounds. The ceiling aborts at the first byte past it, so a
     * response declaring no length or understating it cannot materialize
     * and be measured afterwards; the deadline aborts a transfer still
     * arriving after the budget is spent. Only bytes that arrived are
     * counted — a declared size belongs to {@see HttpResponse::body()}.
     *
     * @return Closure(int): void
     */
    public function progressGuard(): Closure
    {
        return function (int $downloaded): void {
            if ($this->expired()) {
                throw $this->timedOut();
            }

            if ($downloaded <= $this->maxBytes) {
                return;
            }

            $this->exceeded = true;

            throw $this->tooLarge();
        };
    }

    /** A fresh failure for a ceiling this budget has already proven passed. */
    public function tooLarge(): HttpRequestException
    {
        return HttpRequestException::responseTooLarge($this->method, $this->origin, $this->maxBytes);
    }

    /** A fresh failure for the whole operation running past its deadline. */
    public function timedOut(): HttpRequestException
    {
        return HttpRequestException::timedOut($this->method, $this->origin, $this->timeout, $this->attempts);
    }

    /** A fresh failure for a response that never arrived, or stopped arriving. */
    public function transportFailure(): HttpRequestException
    {
        return HttpRequestException::transportFailure($this->method, $this->origin);
    }

    /**
     * The transport options this budget imposes on one attempt: what is
     * left of the deadline, and the byte ceiling as a live guard.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function applyTo(#[SensitiveParameter] array $options): array
    {
        $remaining = $this->remaining();

        return [
            ...$options,
            'timeout' => $remaining,
            'max_duration' => $remaining,
            'on_progress' => $this->progressGuard(),
        ];
    }

    /** @phpstan-impure */
    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
