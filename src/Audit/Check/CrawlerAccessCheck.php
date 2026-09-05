<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\CrawlerDirectory;
use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Reports which notable crawlers may reach the site root, grouped by what
 * blocking them would cost.
 *
 * The distinction the report turns on is purpose, not popularity: blocking a
 * search engine removes a site from search, blocking a user-triggered fetch
 * turns a person's request into an error, and blocking a training crawler
 * costs nothing but the content. The list and its grouping come from
 * CrawlerDirectory, sourced from Cloudflare Radar.
 */
final class CrawlerAccessCheck implements AuditCheck
{
    public function __construct(private readonly CrawlerDirectory $directory = new CrawlerDirectory())
    {
    }

    public function run(Response $response): array
    {
        $document = $response->document();
        $findings = [];

        foreach ($this->directory->groups() as $group) {
            $crawlers = $this->directory->crawlers($group);
            if ($crawlers === []) {
                continue;
            }

            $blocked = [];
            $allowed = [];

            foreach ($crawlers as $name => $purpose) {
                $decision = $document->decide($name, '/');

                if ($decision->allowed) {
                    $allowed[] = $name;

                    continue;
                }

                $blocked[$name] = new Evidence(
                    text: "{$name} — {$purpose}",
                    line: $decision->rule?->line,
                    detail: $decision->rule !== null
                        ? "Blocked by \"Disallow: {$decision->rule->value}\""
                        : null,
                );
            }

            $findings[] = $this->finding($group, $blocked, $allowed, count($crawlers));
        }

        return $findings;
    }

    /**
     * @param array<string, Evidence> $blocked
     * @param list<string>            $allowed
     */
    private function finding(string $group, array $blocked, array $allowed, int $total): Finding
    {
        $label = $this->directory->label($group);
        $id = "crawler-access-{$group}";

        if ($blocked === []) {
            return new Finding(
                id: $id,
                title: "{$label} can reach the site",
                status: Status::Pass,
                summary: sprintf(
                    'All %d checked %s are allowed at the site root.',
                    $total,
                    $this->directory->inlineLabel($group),
                ),
                impact: $this->directory->upside($group),
            );
        }

        $title = sprintf(
            '%d of %d %s are blocked',
            count($blocked),
            $total,
            $this->directory->inlineLabel($group),
        );
        $summary = 'Blocked: ' . implode(', ', array_keys($blocked)) . '.';

        if ($this->directory->isDiscretionary($group)) {
            return new Finding(
                id: $id,
                title: $title,
                status: $this->directory->severity($group),
                summary: $summary,
                impact: $this->directory->downside($group),
                fix: $allowed !== []
                    ? 'If the intent is to opt out entirely, note that ' . implode(', ', $allowed)
                        . ' ' . (count($allowed) === 1 ? 'is' : 'are') . ' still allowed.'
                    : null,
                evidence: array_values($blocked),
            );
        }

        return new Finding(
            id: $id,
            title: $title,
            status: $this->directory->severity($group),
            summary: $summary,
            impact: $this->directory->downside($group),
            fix: 'Remove the matching Disallow rule, or add an Allow rule for these agents. '
                . 'Path matching is longest-wins, so a specific Allow overrides a broader Disallow.',
            evidence: array_values($blocked),
        );
    }
}
