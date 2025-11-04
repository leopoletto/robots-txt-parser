<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class Comment implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly string $comment
    ) {}

    public function line(): int
    {
        return $this->line;
    }

    /**
     * Check if line is a comment
     */
    public static function isComment(string $line): bool
    {
        $trimmed = trim($line);
        return str_starts_with($trimmed, '#');
    }

    /**
     * Parse comment line
     */
    public static function parse(string $line, int $lineNumber): ?static
    {
        $trimmed = trim($line);
        if (strlen($trimmed) < 2) {
            return null;
        }

        $comment = substr($trimmed, 1); // Remove the #
        return new static($lineNumber, trim($comment));
    }
}

