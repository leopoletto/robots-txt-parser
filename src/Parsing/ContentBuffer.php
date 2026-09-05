<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

/**
 * Accumulates the raw document while it streams past, for callers that want to
 * display or store the original alongside the parse.
 *
 * Line endings are normalised to "\n" regardless of the source, so text, file
 * and URL parses all produce byte-identical content for the same document.
 */
final class ContentBuffer
{
    private string $content = '';

    public function append(string $line): void
    {
        $this->content .= $line . "\n";
    }

    public function content(): string
    {
        // The trailing newline is an artefact of line-wise accumulation, not
        // part of the document.
        return rtrim($this->content, "\n");
    }
}
