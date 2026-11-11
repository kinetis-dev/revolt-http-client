<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient;

/**
 * Spaces the attempts {@see Http} sends to one origin, typically across
 * every worker process sharing a database or Redis. `Http::withPacer()`
 * asks before every wire attempt, retries included, and waits out the
 * answer inside the operation's deadline.
 *
 * `docs/revolt-http-client.md` states how the answer is applied, and
 * `docs/application-recipes.md` gives implementations.
 */
interface RequestPacer
{
    /**
     * Reserves the next send slot for $origin and returns the seconds to
     * wait before using it (0.0 = now), or null, reserving nothing, when
     * the slot is more than $maxWaitSeconds away. Any I/O suspends the
     * calling Fiber, never the process. Holds no request state: one
     * instance serves every request for the worker's lifetime.
     *
     * @param string $origin the validated `scheme://host[:port]` the attempt goes to
     */
    public function reserve(string $origin, float $maxWaitSeconds): ?float;
}
