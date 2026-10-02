<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Source;

use Leopoletto\RobotsTxtParser\Exception\SourceUnavailable;

/**
 * Reads robots.txt content from a local file, a chunk at a time.
 */
final class FileSource extends ChunkedSource
{
    public function __construct(private readonly string $path, int $maxBytes)
    {
        parent::__construct($maxBytes);
    }

    protected function chunks(): iterable
    {
        if (! is_file($this->path)) {
            throw SourceUnavailable::fileNotFound($this->path);
        }

        if (! is_readable($this->path)) {
            throw SourceUnavailable::fileNotReadable($this->path);
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw SourceUnavailable::fileNotReadable($this->path);
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::DEFAULT_CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
    }
}
