<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class UserAgent implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly string $userAgent
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    /**
     * Check if line is a user agent
     */
    public static function isUserAgent(string $line): bool
    {
        return str_starts_with(strtolower(trim($line)), 'user-agent:');
    }

    /**
     * Parse user agent line
     */
    public static function parse(string $line, int $lineNumber): ?static
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $userAgent = trim($parts[1]);
        if ($userAgent === '') {
            return null;
        }

        return new static($lineNumber, $userAgent);
    }
}
