<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

use Leopoletto\RobotsTxtParser\Agents\Agent;

/**
 * Resolves a declared user-agent token to a known crawler description.
 *
 * Implementations must match case-insensitively, since robots.txt user-agent
 * tokens are case-insensitive per RFC 9309 §2.2.1.
 */
interface AgentRepository
{
    public function find(string $name): ?Agent;
}
