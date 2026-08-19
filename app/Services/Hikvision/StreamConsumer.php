<?php

namespace App\Services\Hikvision;

use Psr\Http\Message\StreamInterface;

/**
 * The read loop shared by hikvision:dump (bounded by a duration) and
 * hikvision:stream (bounded by an idle timeout and signals) — the same
 * "do not fork the logic between paths" principle §7.2 applies to the
 * event *filtering* extends naturally to the byte-reading mechanism
 * both commands need.
 */
class StreamConsumer
{
    public function __construct(
        private readonly JsonStreamParser $parser,
    ) {}

    /**
     * Reads from $stream until it ends or $shouldStop() returns true,
     * calling $onObject(string $json) for each complete JSON object.
     * An empty read is not itself an error — a slow but healthy stream
     * legitimately goes quiet between events — but more than
     * $idleTimeoutSeconds of consecutive silence throws, since the
     * device's own keepalives mean genuine silence always means a
     * half-open connection, never a quiet device.
     *
     * @param  callable(string): void  $onObject
     * @param  callable(): bool  $shouldStop  checked before every read,
     *                                        e.g. a deadline or a signal flag
     *
     * @throws StreamIdleTimeoutException
     */
    public function consume(
        StreamInterface $stream,
        callable $onObject,
        int $idleTimeoutSeconds,
        callable $shouldStop,
        int $readChunkBytes = 8192,
    ): void {
        $lastDataAt = time();

        while (! $stream->eof()) {
            if ($shouldStop()) {
                return;
            }

            $chunk = $stream->read($readChunkBytes);

            if ($chunk === '') {
                if (time() - $lastDataAt > $idleTimeoutSeconds) {
                    throw new StreamIdleTimeoutException(
                        "No data received for over {$idleTimeoutSeconds}s — assuming a half-open connection."
                    );
                }

                usleep(200_000);

                continue;
            }

            $lastDataAt = time();

            foreach ($this->parser->feed($chunk) as $json) {
                $onObject($json);
            }
        }
    }
}
