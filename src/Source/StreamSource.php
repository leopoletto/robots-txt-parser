<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Source;

use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Reads robots.txt content from a PSR-7 stream, so an HTTP body never has to
 * be buffered in full before parsing begins.
 */
final class StreamSource extends ChunkedSource
{
    public function __construct(private readonly StreamInterface $stream, int $maxBytes)
    {
        parent::__construct($maxBytes);
    }

    protected function chunks(): iterable
    {
        try {
            while (! $this->stream->eof()) {
                $chunk = $this->stream->read(self::DEFAULT_CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                yield $chunk;
            }
        } finally {
            try {
                $this->stream->close();
            } catch (Throwable) {
                // A stream that refuses to close has nothing left to tell us.
            }
        }
    }
}
