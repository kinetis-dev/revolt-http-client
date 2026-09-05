<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use SensitiveParameter;

/**
 * What one attempt's response may consume: bytes and time. {@see Http}
 * builds a fresh instance per attempt and hands the surviving one to the
 * {@see HttpResponse} it returns, so nothing here outlives the operation
 * it was made for — a worker that serves a million requests holds a
 * million separate budgets, never one accumulating counter.
 *
 * @internal
 */
final class ResponseBudget
{
    /**
     * Set by {@see progressGuard()} when a transfer passed the ceiling.
     * The transport reports that abort as a failure of its own; this
     * flag is how {@see HttpResponse} tells it apart from a dropped
     * connection and reports the real reason.
     */
    public bool $exceeded = false;

    public function __construct(
        public readonly string $method,
        public readonly string $origin,
        public readonly Deadline $deadline,
        public readonly int $maxBytes,
        public readonly int $attempt,
    ) {}

    /**
     * The transport's own progress hook, owned here rather than exposed.
     * It is the one place inside a transfer this package gets to run, so
     * it carries both of the bounds an attempt has:
     *
     * - the byte ceiling, aborting at the first byte past it, so a
     *   response with no `Content-Length`, or one whose `Content-Length`
     *   understates it, cannot first materialize in memory and be
     *   measured afterwards;
     * - the deadline, aborting a transfer that is still arriving after
     *   the operation's budget is spent, which is what bounds a
     *   transport that ignored the duration it was handed.
     *
     * Only bytes that arrived are counted, and — because
     * {@see Http} asks for identity encoding — they are the same bytes
     * that end up held. A transport also passes the declared size and
     * its own info array, and this guard takes neither: an over-large
     * `Content-Length` is a reason to refuse to materialize a body,
     * checked in {@see HttpResponse::body()}, not a reason to refuse a
     * caller the status of a large resource they never asked to
     * download.
     *
     * Both exceptions it raises are this package's own, so a transport
     * that logs or wraps one still records only a method, an origin, and
     * a number.
     *
     * @return Closure(int): void
     */
    public function progressGuard(): Closure
    {
        return function (int $downloaded): void {
            if ($this->deadline->expired()) {
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
        return HttpRequestException::timedOut($this->method, $this->origin, $this->deadline->budget, $this->attempt);
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
        $remaining = $this->deadline->remaining();

        return [
            ...$options,
            'timeout' => $remaining,
            'max_duration' => $remaining,
            'on_progress' => $this->progressGuard(),
        ];
    }
}
