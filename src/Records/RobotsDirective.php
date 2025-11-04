<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class RobotsDirective implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly UserAgent $userAgent,
        public readonly string $directive,
        public readonly string $path
    ) {}

    public function line(): int
    {
        return $this->line;
    }

    /**
     * Check if line is a directive
     */
    public static function isDirective(string $line): bool
    {
        $trimmed = strtolower(trim($line));
        return str_starts_with($trimmed, 'allow:')
            || str_starts_with($trimmed, 'disallow:')
            || str_starts_with($trimmed, 'crawl-delay:');
    }

    /**
     * Parse directive line
     */
    public static function parse(string $line, int $lineNumber, UserAgent $currentUserAgent): ?static
    {
        $trimmed = trim($line);
        $lowerTrimmed = strtolower($trimmed);

        if (str_starts_with($lowerTrimmed, 'allow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new static($lineNumber, $currentUserAgent, 'allow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'disallow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new static($lineNumber, $currentUserAgent, 'disallow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'crawl-delay:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new static($lineNumber, $currentUserAgent, 'crawl-delay', $path);
        }

        return null;
    }
}

