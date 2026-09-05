<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

/**
 * The single instant one operation must finish by, measured on a clock
 * that only moves forward.
 *
 * `hrtime()` is monotonic: an NTP correction, a leap second, or a
 * manual clock change during a long request cannot move it, so a
 * deadline set from it cannot silently grow or expire early the way one
 * derived from wall-clock time can. Nothing here reads the wall clock.
 *
 * One instance covers a whole operation — every attempt, every backoff
 * between them, and every read of the response that comes out of it.
 *
 * Every method here that consults the clock is marked
 * `@phpstan-impure`: the answer is a reading rather than a property, so
 * two calls on one instance are two answers. An analyzer that carried
 * the first answer forward would take every later check for a dead
 * branch, and {@see Http} and {@see HttpResponse} depend on those later
 * checks to bound a transport that ignores the duration it was handed.
 *
 * @internal
 */
final readonly class Deadline
{
    private function __construct(
        private float $expiresAt,
        public float $budget,
    ) {}

    /** @param float $budget seconds, already validated by {@see Preflight::timeout()} */
    public static function startingNow(float $budget): self
    {
        return new self(self::now() + $budget, $budget);
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

    /** @phpstan-impure */
    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
