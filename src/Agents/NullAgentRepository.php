<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Agents;

use Leopoletto\RobotsTxtParser\Contract\AgentRepository;

/**
 * Resolves nothing. Use when agent metadata is unwanted, to skip all dataset I/O.
 */
final class NullAgentRepository implements AgentRepository
{
    public function find(string $name): ?Agent
    {
        return null;
    }
}
