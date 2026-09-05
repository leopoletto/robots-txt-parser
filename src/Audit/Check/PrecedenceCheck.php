<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\Group;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Finds rules that do not do what reading the file top to bottom suggests.
 *
 * robots.txt is not evaluated in order: the longest matching pattern wins, and
 * ties go to the least restrictive rule. Files are written and reviewed as if
 * order mattered, so the rules that quietly never apply are easy to miss.
 */
final class PrecedenceCheck implements AuditCheck
{
    public function run(Response $response): array
    {
        $findings = [];
        $contradictions = [];
        $duplicates = [];
        $shadowed = [];

        foreach ($response->document()->groups() as $group) {
            $this->inspect($group, $contradictions, $duplicates, $shadowed);
        }

        if ($contradictions !== []) {
            $findings[] = new Finding(
                id: 'precedence-contradiction',
                title: sprintf('%d path%s is both allowed and disallowed', count($contradictions), count($contradictions) === 1 ? '' : 's'),
                status: Status::Warning,
                summary: 'The same group declares Allow and Disallow for an identical path.',
                impact: 'Order in the file does not decide this. Equal-length patterns resolve to the '
                    . 'least restrictive rule, so the Allow wins and the Disallow has no effect — '
                    . 'the opposite of what a top-to-bottom reading suggests.',
                fix: 'Delete whichever rule is not wanted. If the intent was to disallow a directory '
                    . 'but permit one page inside it, make the Allow more specific than the '
                    . 'Disallow rather than identical to it.',
                evidence: array_slice($contradictions, 0, 10),
            );
        }

        if ($shadowed !== []) {
            $findings[] = new Finding(
                id: 'precedence-shadowed',
                title: sprintf('%d rule%s can never take effect', count($shadowed), count($shadowed) === 1 ? '' : 's'),
                status: Status::Notice,
                summary: 'A broader rule of the same type already covers these paths.',
                impact: 'These lines change nothing. They are harmless, but they add length to a file '
                    . 'that has a size limit, and they make the real policy harder to read.',
                fix: 'Remove the redundant rules, or narrow the broader rule if it is catching more '
                    . 'than intended.',
                evidence: array_slice($shadowed, 0, 10),
            );
        }

        if ($duplicates !== []) {
            $findings[] = new Finding(
                id: 'precedence-duplicate',
                title: sprintf('%d rule%s is declared more than once', count($duplicates), count($duplicates) === 1 ? '' : 's'),
                status: Status::Notice,
                summary: 'The same directive and path appear repeatedly within one group.',
                impact: 'Duplicates have no effect on crawling, but they inflate the file toward the '
                    . '500 KB limit and usually signal that the file is generated without dedup.',
                fix: 'Remove the repeats.',
                evidence: array_slice($duplicates, 0, 10),
            );
        }

        return $findings;
    }

    /**
     * @param list<Evidence> $contradictions
     * @param list<Evidence> $duplicates
     * @param list<Evidence> $shadowed
     */
    private function inspect(Group $group, array &$contradictions, array &$duplicates, array &$shadowed): void
    {
        $allows = $group->directives(DirectiveType::Allow);
        $disallows = $group->directives(DirectiveType::Disallow);
        $agents = implode(', ', $group->tokens());

        // Identical path declared as both Allow and Disallow.
        $allowPaths = [];
        foreach ($allows as $allow) {
            $allowPaths[$allow->value] ??= $allow;
        }

        foreach ($disallows as $disallow) {
            $twin = $allowPaths[$disallow->value] ?? null;
            if ($twin !== null && $disallow->value !== '') {
                $contradictions[] = new Evidence(
                    text: "Allow: {$disallow->value} and Disallow: {$disallow->value}",
                    line: $disallow->line,
                    detail: "In the group for {$agents}; the Allow on line {$twin->line} wins",
                );
            }
        }

        $this->collect($allows, DirectiveType::Allow, $agents, $duplicates, $shadowed);
        $this->collect($disallows, DirectiveType::Disallow, $agents, $duplicates, $shadowed);
    }

    /**
     * @param list<Directive> $directives
     * @param list<Evidence>  $duplicates
     * @param list<Evidence>  $shadowed
     */
    private function collect(array $directives, DirectiveType $type, string $agents, array &$duplicates, array &$shadowed): void
    {
        $seen = [];

        foreach ($directives as $directive) {
            if ($directive->value === '') {
                continue;
            }

            if (isset($seen[$directive->value])) {
                $duplicates[] = new Evidence(
                    text: "{$type->value}: {$directive->value}",
                    line: $directive->line,
                    detail: "Already declared on line {$seen[$directive->value]->line} for {$agents}",
                );

                continue;
            }

            $seen[$directive->value] = $directive;
        }

        // A rule is shadowed when a shorter, wildcard-free rule of the same
        // type is a prefix of it: the broader one already matches everything
        // the narrower one would.
        foreach ($seen as $path => $directive) {
            foreach ($seen as $otherPath => $other) {
                if ($otherPath === $path || strlen($otherPath) >= strlen($path)) {
                    continue;
                }

                if (str_contains($otherPath, '*') || str_ends_with($otherPath, '$')) {
                    continue;
                }

                if (str_starts_with($path, $otherPath)) {
                    $shadowed[] = new Evidence(
                        text: "{$type->value}: {$path}",
                        line: $directive->line,
                        detail: "Already covered by \"{$type->value}: {$otherPath}\" on line {$other->line}",
                    );

                    break;
                }
            }
        }
    }
}
