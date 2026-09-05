<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

use Leopoletto\RobotsTxtParser\Contract\AgentRepository;
use Leopoletto\RobotsTxtParser\Model\Group;
use Leopoletto\RobotsTxtParser\Record\UserAgent;

/**
 * The group state machine, shared by every parser for the duration of one parse.
 *
 * Grouping rule (RFC 9309 §2.2.1): consecutive `User-agent:` lines form one
 * group together with the directives that follow. A directive closes the
 * user-agent run, so the next `User-agent:` opens a fresh group. Comments,
 * sitemaps and blank lines do not break a run — they carry no grouping meaning.
 */
final class ParseContext
{
    /** @var list<Group> */
    private array $groups = [];

    private ?Group $current = null;

    private bool $runOpen = false;

    public function __construct(public readonly AgentRepository $agents)
    {
    }

    /**
     * Attach a user agent, opening a new group if the previous run was closed
     * by a directive.
     */
    public function declareUserAgent(UserAgent $userAgent): Group
    {
        if (! $this->runOpen || $this->current === null) {
            $this->current = new Group();
            $this->groups[] = $this->current;
            $this->runOpen = true;
        }

        $this->current->addUserAgent($userAgent);

        return $this->current;
    }

    /**
     * The group a directive on this line belongs to, or null when the document
     * declares a directive before any user agent.
     */
    public function openGroup(): ?Group
    {
        // A directive ends the run of user-agent lines that preceded it.
        $this->runOpen = false;

        return $this->current;
    }

    /**
     * @return list<Group>
     */
    public function groups(): array
    {
        return $this->groups;
    }
}
