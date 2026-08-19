<?php

namespace Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A minimal StreamInterface test double that yields a scripted sequence
 * of reads — including empty strings that are NOT eof, simulating a
 * live HTTP connection that goes briefly quiet between events. Guzzle's
 * ordinary string-backed streams can't represent that: read() only
 * ever returns '' once truly at EOF.
 */
class FakeChunkedStream implements StreamInterface
{
    private int $index = 0;

    /**
     * @param  array<int, string>  $chunks  '' entries are empty-but-not-eof reads
     */
    public function __construct(private readonly array $chunks) {}

    public function read($length): string
    {
        if ($this->index >= count($this->chunks)) {
            return '';
        }

        return $this->chunks[$this->index++];
    }

    public function eof(): bool
    {
        return $this->index >= count($this->chunks);
    }

    public function __toString(): string
    {
        return implode('', $this->chunks);
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->index;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}
