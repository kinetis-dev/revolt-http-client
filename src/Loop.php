<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

use Closure;
use Revolt\EventLoop;
use SensitiveParameter;
use Throwable;

/**
 * The two things this package needs from the event loop: run a read on
 * the loop and wait for it, and wait for a delay without blocking.
 *
 * Called from plain top-level code, Symfony's response-stream loop only
 * polls for transport activity once a second — the Amp bridge does not
 * wake it when a chunk is already in, so a read from outside a fiber
 * pays that full poll tick. Inside a fiber the loop keeps turning and
 * the same read completes in a couple of milliseconds; every caller gets
 * that path through {@see await()}. Resuming before the suspend is
 * reached is fine — a Revolt suspension stores the result either way.
 *
 * @internal
 */
final class Loop
{
    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public static function await(#[SensitiveParameter] Closure $work): mixed
    {
        $suspension = EventLoop::getSuspension();
        $result = null;
        $error = null;

        EventLoop::queue(static function () use ($work, $suspension, &$result, &$error): void {
            try {
                $result = $work();
            } catch (Throwable $e) {
                $error = $e;
            }

            $suspension->resume();
        });

        $suspension->suspend();

        if ($error !== null) {
            throw $error;
        }

        // The queued fiber ran to completion before the suspension
        // resumed, so exactly one of $error/$result is set by now.
        /** @var T $result */
        return $result;
    }

    /** Suspends the caller for $seconds, leaving the loop free to run everything else. */
    public static function pause(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();

        EventLoop::delay($seconds, static fn () => $suspension->resume());

        $suspension->suspend();
    }
}
