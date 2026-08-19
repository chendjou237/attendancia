<?php

namespace App\Services\Hikvision;

use Illuminate\Support\Facades\Log;

/**
 * §7.2: the alertStream response is `multipart/mixed` and never ends.
 * Between JSON payloads it carries MIME boundary markers and
 * Content-Type/Content-Length headers, arriving as arbitrary byte
 * chunks — a JSON object routinely splits across two or more reads.
 *
 * Strategy: track brace depth, not the first `}`. The naive
 * `strpos($buffer, '}')` finds the brace that closes the nested
 * `AccessControllerEvent` object first and yields truncated garbage —
 * this is the single most likely bug in a hand-rolled version of this
 * parser. Depth-tracking also means multipart boundary lines and
 * headers between objects never need to be parsed at all: outside of
 * an object (depth 0) every byte is simply skipped until the next `{`.
 */
class JsonStreamParser
{
    private const MAX_CAPTURE_BYTES = 1_048_576; // ~1 MB

    private bool $capturing = false;

    private string $current = '';

    private int $depth = 0;

    private bool $inString = false;

    private bool $escaped = false;

    /**
     * @return array<int, string> 0..n complete JSON strings
     */
    public function feed(string $chunk): array
    {
        $complete = [];
        $len = strlen($chunk);

        for ($i = 0; $i < $len; $i++) {
            $char = $chunk[$i];

            if (! $this->capturing) {
                if ($char === '{') {
                    $this->capturing = true;
                    $this->current = '{';
                    $this->depth = 1;
                    $this->inString = false;
                    $this->escaped = false;
                }

                continue;
            }

            $this->current .= $char;

            if ($this->inString) {
                if ($this->escaped) {
                    $this->escaped = false;
                } elseif ($char === '\\') {
                    $this->escaped = true;
                } elseif ($char === '"') {
                    $this->inString = false;
                }

                $this->guardCaptureSize();

                continue;
            }

            if ($char === '"') {
                $this->inString = true;
            } elseif ($char === '{') {
                $this->depth++;
            } elseif ($char === '}') {
                // Reset to 0 rather than go negative — happens when the
                // first read of a stream lands mid-object, so an early
                // unmatched `}` must not poison the depth count for
                // everything that follows.
                $this->depth = max(0, $this->depth - 1);

                if ($this->depth === 0) {
                    $complete[] = $this->current;
                    $this->capturing = false;
                    $this->current = '';

                    continue;
                }
            }

            $this->guardCaptureSize();
        }

        return $complete;
    }

    /**
     * A capture that never closes (malformed stream, or a genuine but
     * pathological payload) would otherwise grow without bound until
     * the worker is OOM-killed. Rather than keep a truncated tail whose
     * depth/string state can no longer be trusted, abandon the capture
     * entirely and resync on the next `{` — simpler and safer than
     * trying to recover a partially-discarded, possibly mid-string
     * buffer, and the worker recovers as soon as valid JSON reappears.
     */
    private function guardCaptureSize(): void
    {
        if (strlen($this->current) <= self::MAX_CAPTURE_BYTES) {
            return;
        }

        Log::warning('JsonStreamParser: capture exceeded max size, discarding and resyncing', [
            'discarded_bytes' => strlen($this->current),
        ]);

        $this->capturing = false;
        $this->current = '';
        $this->depth = 0;
        $this->inString = false;
        $this->escaped = false;
    }
}
