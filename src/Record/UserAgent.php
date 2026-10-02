<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Agents\Agent;
use Leopoletto\RobotsTxtParser\Contract\Record;

/**
 * A `User-agent:` line. The declared token is preserved verbatim; any matching
 * entry from the bundled dataset is attached as descriptive metadata.
 */
final readonly class UserAgent implements Record
{
    public function __construct(
        public int $line,
        public string $token,
        public ?Agent $agent = null,
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function isWildcard(): bool
    {
        return $this->token === '*';
    }

    public function matches(string $token): bool
    {
        return strcasecmp($this->token, $token) === 0;
    }

    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'userAgent' => $this->token,
            'category' => $this->agent?->category,
            'description' => $this->agent?->description,
            'known' => $this->agent !== null,
        ];
    }
}
