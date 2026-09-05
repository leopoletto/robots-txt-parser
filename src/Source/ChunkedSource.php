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
            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);

                yield ++$number => rtrim($line, "\r");
            }

            if ($this->truncated) {
                break;
            }
        }

        // A final line without a trailing newline still counts.
        if ($buffer !== '') {
            yield ++$number => rtrim($buffer, "\r");
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
