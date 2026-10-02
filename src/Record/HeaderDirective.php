<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Validation\ValidationResult;

/**
 * One `X-Robots-Tag` HTTP header value.
 *
 * Each header line is its own record — a response may send several, and one
 * may target a specific crawler ("googlebot: noindex, nofollow").
 */
final readonly class HeaderDirective implements Record
{
    public function __construct(
        public string $userAgent,
        public bool $userAgentKnown,
        public string $raw,
        public ValidationResult $validation,
        public string $origin = 'robots.txt',
    ) {
    }

    public function line(): int
    {
        // Headers have no position in the document body.
        return 0;
    }

    public function appliesToAll(): bool
    {
        return $this->userAgent === '*';
    }

    public function toArray(): array
    {
        return [
            'user_agent' => $this->userAgent,
            'user_agent_valid' => $this->userAgentKnown,
            'origin' => $this->origin,
            'raw' => $this->raw,
        ] + $this->validation->toArray();
    }
}
