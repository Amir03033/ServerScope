<?php

namespace App\Monitoring\Net;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Response sink that stops accepting data after $limit bytes. cURL aborts
 * the transfer (CURLE_WRITE_ERROR) when a write is refused, so oversized
 * responses are never downloaded completely.
 */
class LimitedSink implements StreamInterface
{
    public bool $exceeded = false;

    private StreamInterface $buffer;

    public function __construct(private int $limit)
    {
        $this->buffer = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        $remaining = $this->limit - $this->buffer->getSize();

        if (strlen($string) > $remaining) {
            $this->buffer->write(substr($string, 0, max(0, $remaining)));
            $this->exceeded = true;

            return 0;
        }

        return $this->buffer->write($string);
    }

    public function __toString(): string
    {
        return (string) $this->buffer;
    }

    public function close(): void
    {
        $this->buffer->close();
    }

    public function detach()
    {
        return $this->buffer->detach();
    }

    public function getSize(): ?int
    {
        return $this->buffer->getSize();
    }

    public function tell(): int
    {
        return $this->buffer->tell();
    }

    public function eof(): bool
    {
        return $this->buffer->eof();
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->buffer->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->buffer->rewind();
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->buffer->read($length);
    }

    public function getContents(): string
    {
        return $this->buffer->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->buffer->getMetadata($key);
    }
}
