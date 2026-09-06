<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\CrawlerDirectory;
use Leopoletto\RobotsTxtParser\Audit\CrawlerVerdict;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Reports which notable crawlers may reach the site root, grouped by purpose.
 *
 * This check states policy and never grades it. Blocking a crawler is a choice
 * a site is entitled to make, and the same rule can be a mistake on one site
 * and the whole point on another — a file that turns away every AI crawler is
 * not broken, and a report that calls it critical is simply wrong about what
 * robots.txt is for. So every finding here is Info or Pass. Where a
 * configuration really does contradict itself, CrawlerCoherenceCheck says so
 * on evidence rather than on the assumption that blocking is a defect.
 *
 * Purpose is still the load-bearing distinction, because the consequence
 * differs enormously: blocking a training crawler declines a content donation,
 * blocking a user-triggered fetcher refuses a person who asked for the page,
 * and blocking a search engine removes the site from search. The list and its
 * grouping come from CrawlerDirectory, sourced from Cloudflare Radar.
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

                $verdict = new CrawlerVerdict(
                    agent: $name,
                    operator: $this->directory->operator($name),
                    purpose: $purpose,
                    allowed: $decision->allowed,
                    rule: $decision->rule !== null
                        ? "{$decision->rule->type->label()}: {$decision->rule->value}"
                        : null,
                    line: $decision->rule?->line,
                );

                $decision->allowed ? $allowed[] = $verdict : $blocked[] = $verdict;
            }

            $findings[] = $this->finding($group, $blocked, $allowed);
        }

        return $findings;
    }

    /**
     * @param list<CrawlerVerdict> $blocked
     * @param list<CrawlerVerdict> $allowed
     */
    private function finding(string $group, array $blocked, array $allowed): Finding
    {
        $label = $this->directory->label($group);
        $inline = $this->directory->inlineLabel($group);
        $id = "crawler-access-{$group}";
        $total = count($blocked) + count($allowed);

        if ($blocked === []) {
            return new Finding(
                id: $id,
                title: "{$label} can reach the site",
                status: Status::Pass,
                summary: sprintf('All %d checked %s are allowed at the site root.', $total, $inline),
                impact: $this->directory->upside($group),
                crawlers: $allowed,
            );
        }

        return new Finding(
            id: $id,
            title: sprintf('%d of %d %s are blocked', count($blocked), $total, $inline),
            status: Status::Info,
            summary: 'Blocked: ' . implode(', ', array_map(
                static fn (CrawlerVerdict $c): string => $c->agent,
                $blocked,
            )) . '.',
            impact: $this->directory->consequence($group),
            fix: $this->directory->remedy($group),
            intent: sprintf(
                'If your intention is to %s, the current configuration achieves that%s.',
                $this->directory->intent($group),
                $allowed === [] ? '' : ' for these crawlers — the rest of the group is still allowed',
            ),
            crawlers: array_merge($blocked, $allowed),
        );
    }
}
