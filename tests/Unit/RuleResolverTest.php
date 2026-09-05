<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Agents\NullAgentRepository;
use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Parsing\DocumentParser;
use Leopoletto\RobotsTxtParser\Source\TextSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleResolverTest extends TestCase
{
    private function parse(string $content): Document
    {
        return (new DocumentParser(new NullAgentRepository()))
            ->parse(new TextSource($content, 500 * 1024));
    }

    #[Test]
    public function it_allows_a_path_no_rule_covers(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: /admin");

        $this->assertTrue($document->isAllowed('AnyBot', '/public'));
        $this->assertTrue($document->decide('AnyBot', '/public')->byDefault());
    }

    #[Test]
    public function the_longer_pattern_wins(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: *
            Disallow: /path
            Allow: /path/specific
            TXT);

        $this->assertFalse($document->isAllowed('Bot', '/path/other'));
        $this->assertTrue($document->isAllowed('Bot', '/path/specific'));
    }

    #[Test]
    public function pattern_order_in_the_file_does_not_change_the_outcome(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: *
            Allow: /path/specific
            Disallow: /path
            TXT);

        $this->assertTrue($document->isAllowed('Bot', '/path/specific'));
    }

    #[Test]
    public function an_empty_disallow_forbids_nothing(): void
    {
        $document = $this->parse("User-agent: *\nDisallow:");

        $this->assertTrue($document->isAllowed('Bot', '/anything'));
    }

    #[Test]
    public function a_bare_slash_disallow_blocks_the_whole_site(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: /");

        $this->assertFalse($document->isAllowed('Bot', '/'));
        $this->assertFalse($document->isAllowed('Bot', '/anything/at/all'));
    }

    #[Test]
    public function a_named_group_takes_precedence_over_the_wildcard_group(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: *
            Disallow: /

            User-agent: GPTBot
            Allow: /
            TXT);

        $this->assertTrue($document->isAllowed('GPTBot', '/anywhere'));
        $this->assertFalse($document->isAllowed('OtherBot', '/anywhere'));
    }

    #[Test]
    public function user_agent_matching_is_case_insensitive(): void
    {
        $document = $this->parse("User-agent: GPTBot\nDisallow: /secret");

        $this->assertFalse($document->isAllowed('gptbot', '/secret'));
        $this->assertFalse($document->isAllowed('GPTBOT', '/secret'));
    }

    #[Test]
    public function repeated_groups_for_one_agent_are_merged(): void
    {
        // Some documents declare the same agent more than once; both sets of
        // rules apply.
        $document = $this->parse(<<<'TXT'
            User-agent: GPTBot
            Disallow: /first

            User-agent: GPTBot
            Disallow: /second
            TXT);

        $this->assertFalse($document->isAllowed('GPTBot', '/first'));
        $this->assertFalse($document->isAllowed('GPTBot', '/second'));
    }

    #[Test]
    public function allow_wins_a_tie_with_disallow(): void
    {
        $document = $this->parse(<<<'TXT'
            User-agent: *
            Disallow: /page
            Allow: /page
            TXT);

        $this->assertTrue($document->isAllowed('Bot', '/page'));
    }

    #[Test]
    public function it_matches_a_full_url_as_well_as_a_path(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: /search");

        $this->assertFalse($document->isAllowed('Bot', 'https://example.com/search?q=hi'));
    }

    #[Test]
    public function the_decision_names_the_rule_that_produced_it(): void
    {
        $document = $this->parse("User-agent: *\nDisallow: /admin");

        $decision = $document->decide('Bot', '/admin/users');

        $this->assertFalse($decision->allowed);
        $this->assertSame(2, $decision->rule?->line);
        $this->assertSame('/admin', $decision->rule?->value);
    }

    #[Test]
    public function a_document_with_no_rules_allows_everything(): void
    {
        $document = $this->parse("# nothing here\nSitemap: https://example.com/sitemap.xml");

        $this->assertTrue($document->isAllowed('Bot', '/anything'));
    }
}
