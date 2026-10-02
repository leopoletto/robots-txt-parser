<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Matching;

use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\Group;
use Leopoletto\RobotsTxtParser\Record\Directive;

/**
 * Decides whether a crawler may fetch a path, following RFC 9309 §2.2.2.
 *
 * Group selection: every group naming the token applies, and they are merged —
 * a document may repeat `User-agent: Googlebot` in several places. Only when
 * no group names the token does the wildcard group apply.
 *
 * Rule selection: the longest matching pattern wins; ties break toward the
 * earlier line; `Allow` wins over `Disallow` at identical length and line.
 */
final class RuleResolver
{
    /**
     * @param list<Group> $groups
     */
    public function __construct(private readonly array $groups)
    {
    }

    public function isAllowed(string $userAgent, string $path): bool
    {
        return $this->decide($userAgent, $path)->allowed;
    }

    /**
     * The full decision, including which rule produced it.
     */
    public function decide(string $userAgent, string $path): Decision
    {
        $path = PathMatcher::normalize($path);
        $rules = $this->applicableRules($userAgent);

        $winner = null;
        foreach ($rules as $rule) {
            if (! PathMatcher::matches($rule->value, $path)) {
                continue;
            }

            if ($winner === null || $this->outranks($rule, $winner)) {
                $winner = $rule;
            }
        }

        // No rule matched: crawling is permitted by default.
        if ($winner === null) {
            return new Decision(true, null, $path);
        }

        return new Decision($winner->type === DirectiveType::Allow, $winner, $path);
    }

    /**
     * Allow and Disallow rules governing this user agent, wildcard-fallback included.
     *
     * @return list<Directive>
     */
    public function applicableRules(string $userAgent): array
    {
        $groups = $this->groupsFor($userAgent);
        $rules = [];

        foreach ($groups as $group) {
            foreach ($group->directives() as $directive) {
                if (! $directive->type->isPathRule()) {
                    continue;
                }

                // "Disallow:" with no value forbids nothing, so it can never win.
                if ($directive->type === DirectiveType::Disallow && $directive->value === '') {
                    continue;
                }

                $rules[] = $directive;
            }
        }

        return $rules;
    }

    /**
     * Groups governing this user agent: those naming it, or the wildcard groups
     * when none does.
     *
     * @return list<Group>
     */
    public function groupsFor(string $userAgent): array
    {
        $named = array_values(array_filter(
            $this->groups,
            static fn (Group $group): bool => $group->appliesTo($userAgent)
        ));

        if ($named !== []) {
            return $named;
        }

        return array_values(array_filter(
            $this->groups,
            static fn (Group $group): bool => $group->isWildcard()
        ));
    }

    private function outranks(Directive $candidate, Directive $incumbent): bool
    {
        $difference = $candidate->specificity() <=> $incumbent->specificity();
        if ($difference !== 0) {
            return $difference > 0;
        }

        // Equal specificity: the least restrictive rule wins, so Allow beats
        // Disallow wherever both match a URL equally well. Position in the file
        // does not enter into it.
        if ($candidate->type !== $incumbent->type) {
            return $candidate->type === DirectiveType::Allow;
        }

        // Same type and same specificity: keep the earlier declaration, so the
        // reported rule is stable and matches reading order.
        return $candidate->line < $incumbent->line;
    }
}
