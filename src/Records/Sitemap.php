<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class Sitemap implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly string $url,
        public readonly bool $valid
    ) {}

    public function line(): int
    {
        return $this->line;
    }

    /**
     * Check if line is a sitemap
     */
    public static function isSitemap(string $line): bool
    {
        $trimmed = trim($line);
        return str_starts_with(strtolower($trimmed), 'sitemap:');
    }

    /**
     * Parse sitemap line
     */
    public static function parse(string $line, int $lineNumber): ?static
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $url = trim($parts[1]);
        $valid = filter_var($url, FILTER_VALIDATE_URL) !== false && str_ends_with(strtolower($url), '.xml');

        return new static($lineNumber, $url, $valid);
    }
}

