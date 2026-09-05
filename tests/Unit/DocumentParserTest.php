<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Agents\NullAgentRepository;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Parsing\DocumentParser;
use Leopoletto\RobotsTxtParser\Source\TextSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentParserTest extends TestCase
{
    private function parse(string $content): Document
    {
        return (new DocumentParser(new NullAgentRepository()))
            ->parse(new TextSource($content, 500 * 1024));
    }

    #[Test]
    public function it_reports_line_numbers_matching_the_source(): void
    {
        $document = $this->parse(<<<'TXT'
            # a comment

            User-agent: *

            Disallow: /admin
            TXT);

        $this->assertSame(1, $document->comments()[0]->line);
        $this->assertSame(3, $document->userAgents()[0]->line);
        $this->assertSame(5, $document->disallowed()[0]->line);
    }

    #[Test]
    public function it_groups_consecutive_user_agents_together(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: GPTBot
            User-agent: ChatGPT-User
            Disallow: /private
            TXT);

        $this->assertCount(1, $document->groups());
        $this->assertSame(['GPTBot', 'ChatGPT-User'], $document->groups()[0]->tokens());

        // Both agents are governed by the one directive.
        $this->assertCount(1, $document->disallowed('GPTBot'));
        $this->assertCount(1, $document->disallowed('ChatGPT-User'));
    }

    #[Test]
    public function a_directive_closes_the_group_so_the_next_user_agent_starts_a_new_one(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: A
            Disallow: /a
            User-agent: B
            Disallow: /b
            TXT);

        $this->assertCount(2, $document->groups());
        $this->assertSame('/a', $document->disallowed('A')[0]->value);
        $this->assertSame('/b', $document->disallowed('B')[0]->value);
    }

    #[Test]
    public function comments_and_sitemaps_do_not_break_a_user_agent_run(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: A
            # a note between agents
            User-agent: B
            Disallow: /shared
            TXT);

        $this->assertCount(1, $document->groups());
        $this->assertSame(['A', 'B'], $document->groups()[0]->tokens());
    }

    #[Test]
    public function it_keeps_duplicate_directives_that_belong_to_different_groups(): void
    {
        // A previous implementation deduplicated identical lines, silently
        // dropping the second group's rule.
        $document = $this->parse(<<<'TXT'
            User-agent: A
            Disallow: /admin
            User-agent: B
            Disallow: /admin
            TXT);

        $this->assertCount(2, $document->directives(DirectiveType::Disallow));
        $this->assertCount(1, $document->disallowed('A'));
        $this->assertCount(1, $document->disallowed('B'));
    }

    #[Test]
    public function it_preserves_the_declared_casing_of_a_user_agent(): void
    {
        $document = $this->parse("User-agent: ChatGPT-User\nDisallow: /");

        $this->assertSame('ChatGPT-User', $document->userAgents()[0]->token);
    }

    #[Test]
    public function it_reports_a_directive_declared_before_any_user_agent(): void
    {
        $document = $this->parse("Disallow: /admin\nUser-agent: *\nDisallow: /x");

        $issues = $document->issues();
        $this->assertCount(1, $issues);
        $this->assertSame('orphan_directive', $issues[0]->type);
        $this->assertSame(1, $issues[0]->line);
    }

    #[Test]
    public function it_reports_a_misspelled_directive_rather_than_ignoring_it(): void
    {
        $document = $this->parse("User-agent: *\nDissalow: /admin");

        $this->assertSame('unknown_directive', $document->issues()[0]->type);
        $this->assertSame(2, $document->issues()[0]->line);
    }

    #[Test]
    public function it_tolerates_non_standard_but_published_fields(): void
    {
        $document = $this->parse("User-agent: Yandex\nHost: example.com\nClean-param: ref /page");

        $this->assertSame([], $document->issues());
    }

    #[Test]
    public function it_tolerates_the_content_signal_field(): void
    {
        // Cloudflare's AI-policy signal is deployed in the wild; flagging it as
        // unknown would be noise on exactly the sites this package cares about.
        $document = $this->parse("User-agent: *\nContent-Signal: ai-train=no, search=yes");

        $this->assertSame([], $document->issues());
    }

    #[Test]
    public function it_reports_a_non_numeric_crawl_delay(): void
    {
        $document = $this->parse("User-agent: *\nCrawl-delay: soon");

        $this->assertSame('invalid_value', $document->issues()[0]->type);
    }

    #[Test]
    public function it_reads_a_crawl_delay_as_a_number(): void
    {
        $document = $this->parse("User-agent: *\nCrawl-delay: 2.5");

        $this->assertSame(2.5, $document->crawlDelay('*')[0]->delay());
    }

    #[Test]
    public function it_reports_a_path_that_does_not_start_with_a_slash(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: admin");

        $this->assertSame('invalid_path', $document->issues()[0]->type);
    }

    #[Test]
    public function it_validates_sitemap_urls(): void
    {
        $document = $this->parse(<<<'TXT'
            Sitemap: https://example.com/sitemap.xml
            Sitemap: /relative/sitemap.xml
            TXT);

        $this->assertTrue($document->sitemaps()[0]->valid);
        $this->assertFalse($document->sitemaps()[1]->valid);
    }

    #[Test]
    public function it_accepts_a_sitemap_without_an_xml_extension(): void
    {
        $document = $this->parse('Sitemap: https://example.com/sitemap-index');

        $this->assertTrue($document->sitemaps()[0]->valid);
    }

    #[Test]
    public function it_handles_crlf_line_endings(): void
    {
        $document = $this->parse("User-agent: *\r\nDisallow: /admin\r\n");

        $this->assertSame('*', $document->userAgents()[0]->token);
        $this->assertSame('/admin', $document->disallowed()[0]->value);
    }

    #[Test]
    public function it_parses_a_final_line_without_a_trailing_newline(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: /last");

        $this->assertSame('/last', $document->disallowed()[0]->value);
    }

    #[Test]
    public function it_flags_truncation_when_content_exceeds_the_limit(): void
    {
        $content = "User-agent: *\nDisallow: /a\n" . str_repeat("Disallow: /padding\n", 100);

        $document = (new DocumentParser(new NullAgentRepository()))
            ->parse(new TextSource($content, 40));

        $this->assertTrue($document->truncated());
        $this->assertSame('truncated', $document->issues()[0]->type);
    }
}
