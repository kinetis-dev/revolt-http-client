<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests\Fixtures;

use Closure;
use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * One scripted answer, delivered the way a real transport delivers one:
 * headers first, then the body a chunk at a time, reporting progress
 * after each chunk. That is what lets a test drive the byte ceiling from
 * the transfer itself rather than from a length someone declared.
 */
final class ScriptedResponse implements ResponseInterface
{
    public int $cancelled = 0;

    /** How many chunks the transfer got through before it was stopped. */
    public int $chunksDelivered = 0;

    /**
     * @param array<string, list<string>> $headers
     * @param list<string> $chunks
     * @param (Closure(int): void)|null $onProgress
     * @param float $statusDelay seconds the status takes to arrive
     * @param string|null $readFailure a transport message raised instead of a body
     * @param string|null $statusFailure a transport message raised instead of a status
     * @param float $readDelay seconds the body takes to arrive
     * @param float $progressDelay seconds before the first progress report
     * @param int $progressBeforeStatus bytes reported to the progress
     *     hook while the status is being answered, the way a transport
     *     that buffers a body reports them before anyone asked for one
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly array $chunks,
        private readonly ?Closure $onProgress,
        private readonly float $statusDelay = 0.0,
        private readonly ?string $readFailure = null,
        private readonly ?string $statusFailure = null,
        private readonly float $readDelay = 0.0,
        private readonly float $progressDelay = 0.0,
        private readonly int $progressBeforeStatus = 0,
    ) {}

    public function getStatusCode(): int
    {
        if ($this->statusFailure !== null) {
            throw new RuntimeException($this->statusFailure);
        }

        if ($this->statusDelay > 0.0) {
            usleep((int) ($this->statusDelay * 1_000_000));
        }

        if ($this->progressBeforeStatus > 0 && $this->onProgress !== null) {
            ($this->onProgress)($this->progressBeforeStatus);
        }

        return $this->status;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        if ($this->readFailure !== null) {
            throw new RuntimeException($this->readFailure);
        }

        if ($this->readDelay > 0.0) {
            usleep((int) ($this->readDelay * 1_000_000));
        }

        $body = '';

        foreach ($this->chunks as $chunk) {
            $body .= $chunk;
            ++$this->chunksDelivered;

            if ($this->onProgress !== null) {
                if ($this->progressDelay > 0.0) {
                    usleep((int) ($this->progressDelay * 1_000_000));
                }

                ($this->onProgress)(strlen($body));
            }
        }

        return $body;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
        ++$this->cancelled;
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}
