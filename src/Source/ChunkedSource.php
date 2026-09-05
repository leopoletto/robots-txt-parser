<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Source;

use Generator;
use Leopoletto\RobotsTxtParser\Contract\Source;

/**
 * Turns a stream of arbitrary byte chunks into numbered lines.
 *
 * Every source in this package ultimately reads bytes in chunks — a file
 * handle, an HTTP body, a string in memory — so line splitting, CRLF handling,
 * line numbering and the byte limit all live here exactly once. Subclasses
 * supply nothing but the chunks.
 */
abstract class ChunkedSource implements Source
{
    public const DEFAULT_CHUNK_SIZE = 8 * 1024;

    private int $bytesRead = 0;

    private bool $truncated = false;

    /**
     * @param int $maxBytes Hard ceiling on bytes consumed. Reading stops there
     *                      and truncated() flips to true, mirroring how real
     *                      crawlers treat an oversized robots.txt.
     */
    public function __construct(protected readonly int $maxBytes)
    {
    }

    /**
     * @return iterable<string>
     */
    abstract protected function chunks(): iterable;

    /**
     * @return Generator<int, string>
     */
    public function lines(): Generator
    {
        $buffer = '';
        $offset = 0;
        $number = 0;

        foreach ($this->chunks() as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $remaining = $this->maxBytes - $this->bytesRead;
            if (strlen($chunk) >= $remaining) {
                $chunk = substr($chunk, 0, max(0, $remaining));
                $this->truncated = true;
            }

            $this->bytesRead += strlen($chunk);

            // Discard what has already been yielded before growing the buffer,
            // so it never holds more than the unread remainder.
            if ($offset > 0) {
                $buffer = substr($buffer, $offset);
                $offset = 0;
            }

            $buffer .= $chunk;
            $length = strlen($buffer);

            // Lines are read by advancing an offset rather than trimming the
            // front of the buffer. Re-slicing from position zero copies the
            // whole remainder on every line, which is quadratic — and a source
            // that hands over its content in one piece hits the worst of it.
            while ($offset < $length) {
                $span = strcspn($buffer, "\r\n", $offset);
                $position = $offset + $span;

                // No terminator in what has arrived so far.
                if ($position >= $length) {
                    break;
                }

                $skip = 1;
                if ($buffer[$position] === "\r") {
                    // A CR at the very end may be the first half of a CRLF that
                    // has not arrived; wait for the next chunk to tell.
                    if ($position + 1 >= $length && ! $this->truncated) {
                        break;
                    }

                    if (($buffer[$position + 1] ?? '') === "\n") {
                        $skip = 2;
                    }
                }

                $line = substr($buffer, $offset, $span);
                $offset = $position + $skip;

                yield ++$number => $line;
            }

            if ($this->truncated) {
                break;
            }
        }

        // A final line without a trailing terminator still counts.
        if ($offset < strlen($buffer)) {
            yield ++$number => rtrim(substr($buffer, $offset), "\r\n");
        }
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
