<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class UserAgent implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly string $userAgent,
        public readonly string $originalDeclaredName,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
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
    public static function parse(
        string $line, 
        int $lineNumber, 
        string $originalDeclaredAgentName, 
        Collection $agentsDataset
        ): ?static
    {
        $userAgent = self::parseAgent($line);
        $originalDeclaredAgentName = self::parseAgent($originalDeclaredAgentName);

        $agent = $agentsDataset->first(function($agent) use ($originalDeclaredAgentName) {
            return strtolower($agent['agent']) === strtolower($originalDeclaredAgentName);
        });

        $description = $agent ? ($agent['description'] ?? null) : null;
        $category = $agent ? ($agent['category'] ?? null) : null;

        return new static($lineNumber, $userAgent, $originalDeclaredAgentName, $description, $category);
    }

    private static function parseAgent(string $line): string
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return '';
        }

        $userAgent = trim($parts[1]);
        if ($userAgent === '') {
            return '';
        }

        return $userAgent;
    }
}
