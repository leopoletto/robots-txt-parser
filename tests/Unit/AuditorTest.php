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
        $this->assertStringContainsString('Googlebot', (string) $search?->summary);

        // Blocking is reported, never graded: the critical verdict on this file
        // comes from BlanketRuleCheck, which can prove nothing is reachable.
        $this->assertSame(Status::Info, $search?->status);
    }

    #[Test]
    public function a_default_deny_file_with_named_groups_is_an_allowlist_not_a_closed_site(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow: /

            User-agent: Googlebot
            Allow: /
            TXT);

        // A named group replaces the wildcard group, so Googlebot is not
        // covered by "Disallow: /" and the site is not closed.
        $this->assertNull($report->find('blanket-block'));

        $finding = $report->find('blanket-allowlist');
        $this->assertSame(Status::Notice, $finding?->status);
        $this->assertStringContainsString('1 group names crawlers', (string) $finding?->summary);
    }

    #[Test]
    public function a_default_deny_file_with_allow_exceptions_is_not_a_closed_site(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow: /
            Allow: /public
            TXT);

        $this->assertNull($report->find('blanket-block'));
        $this->assertStringContainsString(
            '1 Allow rule opens specific paths',
            (string) $report->find('blanket-allowlist')?->summary,
        );
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
    public function blocking_a_training_crawler_is_reported_without_judgement(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: GPTBot
            Disallow: /

            Sitemap: https://example.com/sitemap.xml
            TXT);

        $finding = $report->find('crawler-access-ai_training');
        $this->assertSame(Status::Info, $finding?->status);
        $this->assertStringContainsString('GPTBot', (string) $finding?->summary);
        $this->assertFalse($finding?->isActionable());

        // A site that declines to feed model training has nothing to fix.
        $this->assertSame(Status::Pass, $report->worst());
    }

    #[Test]
    public function blocking_a_user_triggered_fetcher_while_allowing_collection_is_incoherent(): void
    {
        // ChatGPT-User fetches because a person asked for the page; GPTBot and
        // OAI-SearchBot collect content. Refusing the person while feeding the
        // collectors is not a position anyone chooses on purpose.
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: ChatGPT-User
            Disallow: /
            TXT);

        $this->assertSame(Status::Info, $report->find('crawler-access-ai_user')?->status);

        $finding = $report->find('crawler-coherence');
        $this->assertSame(Status::Warning, $finding?->status);
        $this->assertStringContainsString('ChatGPT-User is blocked', (string) $finding?->summary);
    }

    #[Test]
    public function blocking_a_whole_operator_is_coherent_and_raises_nothing(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: ChatGPT-User
            Disallow: /

            User-agent: GPTBot
            Disallow: /

            User-agent: OAI-SearchBot
            Disallow: /

            Sitemap: https://example.com/sitemap.xml
            TXT);

        // Shutting OpenAI out entirely is a coherent policy, not a defect.
        $this->assertNull($report->find('crawler-coherence'));
        $this->assertSame(Status::Pass, $report->worst());
    }

    #[Test]
    public function blocking_collection_while_allowing_people_through_is_coherent(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: *
            Disallow:

            User-agent: GPTBot
            Disallow: /
            TXT);

        $this->assertNull($report->find('crawler-coherence'));
    }

    #[Test]
    public function a_blocked_crawler_carries_its_purpose_and_the_rule_that_blocked_it(): void
    {
        $report = $this->audit(<<<'TXT'
            User-agent: GPTBot
            Disallow: /
            TXT);

        $finding = $report->find('crawler-access-ai_training');
        $verdict = null;
        foreach ((array) $finding?->crawlers as $crawler) {
            if ($crawler->agent === 'GPTBot') {
                $verdict = $crawler;
            }
        }

        $this->assertNotNull($verdict);
        $this->assertSame('OpenAI', $verdict->operator);
        $this->assertSame('OpenAI model training', $verdict->purpose);
        $this->assertFalse($verdict->allowed);
        $this->assertSame('Disallow: /', $verdict->rule);
        $this->assertSame(2, $verdict->line);
        $this->assertStringContainsString('blocks GPTBot', $verdict->policy());

        $this->assertStringContainsString(
            'If your intention is to prevent this content being used as model training data',
            (string) $finding?->intent,
        );
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

        // The Allow wins, so nothing is actually blocked — worth reporting
        // because the file does not do what it looks like it does, but it is
        // not costing the site anything.
        $this->assertSame(Status::Notice, $report->find('precedence-contradiction')?->status);
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
