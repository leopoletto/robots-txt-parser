<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Model;

use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Record\UserAgent;

/**
 * A robots.txt group: one or more consecutive `User-agent:` lines followed by
 * the directives that apply to all of them (RFC 9309 §2.2.1).
 *
 * Groups are assembled by RecordBuilder and are the unit every rule lookup
 * works against, which is why membership never has to be re-derived by
 * scanning the record list.
 */
final class Group
{
    /** @var list<UserAgent> */
    private array $userAgents = [];

    /** @var list<Directive> */
    private array $directives = [];

    public function addUserAgent(UserAgent $userAgent): void
    {
        $this->userAgents[] = $userAgent;
    }

    public function addDirective(Directive $directive): void
    {
        $this->directives[] = $directive;
    }

    /** @return list<UserAgent> */
    public function userAgents(): array
    {
        return $this->userAgents;
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return array_map(static fn (UserAgent $agent): string => $agent->token, $this->userAgents);
    }

    /**
     * @param DirectiveType|null $type Restrict to a single directive type.
     * @return list<Directive>
     */
    public function directives(?DirectiveType $type = null): array
    {
        if ($type === null) {
            return $this->directives;
        }

        return array_values(array_filter(
            $this->directives,
            static fn (Directive $directive): bool => $directive->type === $type
        ));
    }

    public function isEmpty(): bool
    {
        return $this->directives === [];
    }

    /**
     * Whether this group governs the given user-agent token. Matching is
     * case-insensitive, per RFC 9309 §2.2.1.
     */
    public function appliesTo(string $token): bool
    {
        foreach ($this->userAgents as $userAgent) {
            if ($userAgent->matches($token)) {
                return true;
            }
        }

        return false;
    }

    public function isWildcard(): bool
    {
        return $this->appliesTo('*');
    }
}
