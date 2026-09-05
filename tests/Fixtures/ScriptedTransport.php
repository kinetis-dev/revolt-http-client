<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Tests\Fixtures;

use Closure;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A transport that answers from a script, one step per attempt and the
 * last step repeating. Every step is a plain array so a test reads as
 * the sequence of answers it is about:
 *
 *     new ScriptedTransport([
 *         ['throw' => true],
 *         ['status' => 200, 'chunks' => ['{"ok":true}']],
 *     ]);
 *
 * `throw` raises where a transport refuses to build a request, and its
 * message plants the secrets a real client's would; `chunks` is the body
 * as it arrives, `headers` what precedes it, `statusDelay` how long the
 * status takes, and `readFailure` a body that never comes.
 */
final class ScriptedTransport implements HttpClientInterface
{
    public int $requests = 0;

    /** @var list<array<string, mixed>> the options each attempt was given */
    public array $options = [];

    /** @var list<string> the URL each attempt was sent to */
    public array $urls = [];

    /** @var list<ScriptedResponse> the responses handed out, in order */
    public array $responses = [];

    /** @param list<array<string, mixed>> $script */
    public function __construct(private readonly array $script) {}

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $step = $this->script[$this->requests] ?? $this->script[count($this->script) - 1];
        ++$this->requests;
        $this->options[] = $options;
        $this->urls[] = $url;

        if (($step['requestDelay'] ?? 0.0) > 0.0) {
            usleep((int) ($step['requestDelay'] * 1_000_000));
        }

        if (($step['throw'] ?? false) === true) {
            throw new RuntimeException(
                'cannot connect to https://SENTINELUSER:SENTINELPASS@api.example.com/SENTINELPATH?key=SENTINELQUERY',
            );
        }

        $progress = $options['on_progress'] ?? null;

        return $this->responses[] = new ScriptedResponse(
            $step['status'] ?? 200,
            $step['headers'] ?? [],
            $step['chunks'] ?? ['{}'],
            $progress instanceof Closure ? $progress : null,
            $step['statusDelay'] ?? 0.0,
            $step['readFailure'] ?? null,
            $step['statusFailure'] ?? null,
            $step['readDelay'] ?? 0.0,
            $step['progressDelay'] ?? 0.0,
            $step['progressBeforeStatus'] ?? 0,
        );
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new RuntimeException('this transport hands out complete scripted answers, never a stream');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }

    /** How many of the responses handed out have been cancelled. */
    public function cancellations(): int
    {
        return array_sum(array_map(static fn (ScriptedResponse $one): int => $one->cancelled, $this->responses));
    }
}
