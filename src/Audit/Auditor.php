<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

use Leopoletto\RobotsTxtParser\Audit\Check\BlanketRuleCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\CrawlerAccessCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\CrawlerCoherenceCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\DeprecatedDirectiveCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\FileSizeCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\IndexingDirectiveCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\PrecedenceCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\SensitivePathCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\SitemapCheck;
use Leopoletto\RobotsTxtParser\Audit\Check\SyntaxCheck;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Turns a parsed robots.txt into a report someone can act on.
 *
 * The parser answers "what does this file say". The audit answers "what does
 * that mean for this site, and what should change" — which is the question
 * people actually arrive with.
 *
 * Checks are independent and additive: each returns its own findings, or none,
 * so adding a question never means editing the report.
 */
final class Auditor
{
    /** @var list<AuditCheck> */
    private readonly array $checks;

    /**
     * @param list<AuditCheck>|null $checks Override the default set.
     * @param SitemapProbe|null     $probe  Supply to fetch and validate declared
     *                                      sitemaps rather than only parsing them.
     */
    public function __construct(?array $checks = null, ?SitemapProbe $probe = null)
    {
        $this->checks = $checks ?? [
            new BlanketRuleCheck(),
            new CrawlerAccessCheck(),
            new CrawlerCoherenceCheck(),
            new IndexingDirectiveCheck(),
            new SitemapCheck($probe),
            new SyntaxCheck(),
            new DeprecatedDirectiveCheck(),
            new SensitivePathCheck(),
            new PrecedenceCheck(),
            new FileSizeCheck(),
        ];
    }

    public function audit(Response $response): Report
    {
        $findings = [];

        foreach ($this->checks as $check) {
            foreach ($check->run($response) as $finding) {
                $findings[] = $finding;
            }
        }

        // Most severe first, so the report opens on what is worth fixing.
        usort($findings, static fn (Finding $a, Finding $b): int => $b->status->weight() <=> $a->status->weight());

        // The findings judge the crawlers that matter; the breakdown describes
        // how the file treats everything it actually names.
        return new Report($findings, CategoryBreakdown::of($response->document()));
    }
}
