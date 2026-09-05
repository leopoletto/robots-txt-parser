<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Report;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditorTest extends TestCase
{
    private function audit(string $robots): Report
    {
        return (new Auditor())->audit((new RobotsTxtParser())->parseText($robots));
    }

    #[Test]
    public function it_reports_a_site_closed_to_everyone(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /");

        $finding = $report->find('blanket-block');
        $this->assertNotNull($finding);
        $this->assertSame(Status::Critical, $finding->status);
        $this->assertSame(Status::Critical, $report->worst());
    }

    #[Test]
    public function a_blanket_block_also_reports_search_engines_as_blocked(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /");

        $search = $report->find('crawler-access-search');
        $this->assertSame(Status::Critical, $search?->status);
        $this->assertStringContainsString('Googlebot', (string) $search?->summary);
    }

    #[Test]
    public function an_open_file_passes_every_access_group(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /private\nSitemap: https://example.com/sitemap.xml");

        foreach (['search', 'ai_search', 'ai_user', 'social', 'ai_training'] as $group) {
            $this->assertSame(
                Status::Pass,
                $report->find("crawler-access-{$group}")?->status,
                "group {$group} should pass",
            );
        }
    }

    #[Test]
    public function blocking_a_training_crawler_is_a_notice_not_a_fault(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: GPTBot
            Disallow: /
            TXT);

        $finding = $report->find('crawler-access-ai_training');
        $this->assertSame(Status::Notice, $finding?->status);
        $this->assertStringContainsString('GPTBot', (string) $finding?->summary);

        // Blocking a training crawler must not drag the whole report down.
        $this->assertNotSame(Status::Critical, $report->worst());
    }

    #[Test]
    public function blocking_a_user_triggered_fetcher_is_a_warning(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: ChatGPT-User
            Disallow: /
            TXT);

        $finding = $report->find('crawler-access-ai_user');
        $this->assertSame(Status::Warning, $finding?->status);
    }

    #[Test]
    public function it_warns_when_no_sitemap_is_declared(): void
    {
        $this->assertSame(Status::Warning, $this->audit("User-agent: *\nDisallow: /x")->find('sitemap-missing')?->status);
    }

    #[Test]
    public function it_flags_a_sitemap_that_is_not_an_absolute_url(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /x\nSitemap: /sitemap.xml");

        $this->assertSame(Status::Warning, $report->find('sitemap-malformed')?->status);
    }

    #[Test]
    public function it_flags_a_sitemap_blocked_by_its_own_file(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow: /assets

            Sitemap: https://example.com/assets/sitemap.xml
            TXT);

        $this->assertSame(Status::Critical, $report->find('sitemap-blocked')?->status);
    }

    #[Test]
    public function it_flags_disclosed_sensitive_paths(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow: /admin
            Disallow: /staging/
            Disallow: /.env
            TXT);

        $finding = $report->find('sensitive-paths');
        $this->assertSame(Status::Warning, $finding?->status);
        $this->assertCount(3, (array) $finding?->evidence);
    }

    #[Test]
    public function it_does_not_mistake_a_similar_path_for_a_sensitive_one(): void
    {
        // "/apidocs" is public documentation, not the "/api" endpoint.
        $report = $this->audit("User-agent: *\nDisallow: /apidocs");

        $this->assertNull($report->find('sensitive-paths'));
    }

    #[Test]
    public function it_reports_crawl_delay_as_ignored_by_google(): void
    {
        $report = $this->audit("User-agent: *\nCrawl-delay: 10");

        $this->assertSame(Status::Notice, $report->find('deprecated-crawl-delay')?->status);
    }

    #[Test]
    public function it_reports_noindex_in_robots_txt(): void
    {
        $report = $this->audit("User-agent: *\nNoindex: /private");

        $finding = $report->find('deprecated-noindex');
        $this->assertSame(Status::Warning, $finding?->status);
        $this->assertStringContainsString('2019', (string) $finding?->summary);
    }

    #[Test]
    public function it_reports_a_path_that_is_both_allowed_and_disallowed(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /page\nAllow: /page");

        $this->assertSame(Status::Warning, $report->find('precedence-contradiction')?->status);
    }

    #[Test]
    public function it_reports_a_rule_a_broader_rule_already_covers(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /admin\nDisallow: /admin/users");

        $this->assertSame(Status::Notice, $report->find('precedence-shadowed')?->status);
    }

    #[Test]
    public function it_reports_duplicate_rules(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /x\nDisallow: /x");

        $this->assertSame(Status::Notice, $report->find('precedence-duplicate')?->status);
    }

    #[Test]
    public function it_reports_misspelled_directives(): void
    {
        $report = $this->audit("User-agent: *\nDissalow: /admin");

        $finding = $report->find('syntax-issues');
        $this->assertSame(Status::Warning, $finding?->status);
        $this->assertStringContainsString('unknown_directive', (string) $finding?->summary);
    }

    #[Test]
    public function findings_are_ordered_worst_first(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /\nCrawl-delay: 5");

        $weights = array_map(static fn ($f): int => $f->status->weight(), $report->findings);
        $sorted = $weights;
        rsort($sorted);

        $this->assertSame($sorted, $weights);
    }

    #[Test]
    public function every_actionable_finding_explains_itself(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow: /admin
            Dissalow: /typo
            Crawl-delay: 5
            TXT);

        foreach ($report->actionable() as $finding) {
            $this->assertNotSame('', $finding->summary, "{$finding->id} has no summary");
            $this->assertNotSame('', $finding->impact, "{$finding->id} does not say why it matters");
        }
    }

    #[Test]
    public function it_serialises_to_arrays(): void
    {
        $array = $this->audit("User-agent: *\nDisallow: /")->toArray();

        $this->assertSame('critical', $array['status']);
        $this->assertArrayHasKey('counts', $array);
        $this->assertNotEmpty($array['findings']);
        $this->assertArrayHasKey('fix', $array['findings'][0]);
    }

    #[Test]
    public function it_groups_declared_agents_by_what_they_are_for(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: Googlebot
            Disallow:

            User-agent: GPTBot
            User-agent: ClaudeBot
            Disallow: /
            TXT);

        $breakdown = $report->breakdown;
        $this->assertNotNull($breakdown);
        $this->assertSame(3, $breakdown->declared);

        $blocked = array_map(
            static fn ($t): string => $t->category,
            $breakdown->fullyBlocked(),
        );
        $this->assertContains('AI Data Scraper', $blocked);

        $allowed = array_map(
            static fn ($t): string => $t->category,
            $breakdown->fullyAllowed(),
        );
        $this->assertContains('Search Engine Crawler', $allowed);
    }

    #[Test]
    public function the_breakdown_counts_a_repeated_agent_once(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: GPTBot
            Disallow: /a

            User-agent: GPTBot
            Disallow: /b
            TXT);

        $this->assertSame(1, $report->breakdown?->declared);
    }

    #[Test]
    public function the_breakdown_names_the_wildcard_group_plainly(): void
    {
        $report = $this->audit("User-agent: *\nDisallow: /");

        $categories = array_map(
            static fn ($t): string => $t->category,
            (array) $report->breakdown?->categories,
        );

        $this->assertContains('Every other crawler', $categories);
    }

    #[Test]
    public function the_breakdown_describes_each_category(): void
    {
        $report = $this->audit("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /");

        $tally = (array) $report->breakdown?->fullyBlocked();
        $this->assertNotEmpty($tally);
        $this->assertSame('all 2 blocked', $tally[0]->describe());
    }
}
