<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Source;

/**
 * Reads robots.txt content already held in memory.
 */
final class TextSource extends ChunkedSource
{
    public function __construct(private readonly string $content, int $maxBytes)
    {
        parent::__construct($maxBytes);
    }

    protected function chunks(): iterable
    {
        // The content is already resident; handing it over in one piece avoids
        // needless copying, and ChunkedSource applies the byte limit itself.
        yield $this->content;
    }
}
