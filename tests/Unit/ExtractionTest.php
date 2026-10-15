<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
use Leopoletto\RobotsTxtParser\Extraction\MetaTagExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtractionTest extends TestCase
{
    #[Test]
    public function it_reads_an_untargeted_header(): void
    {
        $records = (new HeaderExtractor())->extract(['noindex, nofollow']);

        $this->assertCount(1, $records);
        $this->assertSame('*', $records[0]->userAgent);
        $this->assertTrue($records[0]->validation->has('noindex'));
        $this->assertTrue($records[0]->validation->has('nofollow'));
    }

    #[Test]
    public function it_reads_a_header_targeted_at_a_crawler(): void
    {
        $records = (new HeaderExtractor())->extract(['googlebot: noindex']);

        $this->assertSame('googlebot', $records[0]->userAgent);
        $this->assertTrue($records[0]->userAgentKnown);
        $this->assertTrue($records[0]->validation->has('noindex'));
    }

    #[Test]
    public function a_parametric_directive_is_not_mistaken_for_a_target(): void
    {
        // "max-snippet:-1" is a directive, not a crawler named "max-snippet".
        $records = (new HeaderExtractor())->extract(['max-snippet:-1, max-image-preview:large']);

        $this->assertSame('*', $records[0]->userAgent);
        $this->assertTrue($records[0]->validation->has('max-snippet'));
        $this->assertTrue($records[0]->validation->isValid());
    }

    #[Test]
    public function it_flags_an_unrecognised_target(): void
    {
        $records = (new HeaderExtractor())->extract(['weirdbot: noindex']);

        $this->assertFalse($records[0]->userAgentKnown);
        $this->assertNotEmpty($records[0]->validation->issues);
    }

    #[Test]
    public function it_finds_a_robots_meta_tag(): void
    {
        $records = (new MetaTagExtractor())->extract(
            '<html><head><meta name="robots" content="noindex, nofollow"></head></html>'
        );

        $this->assertCount(1, $records);
        $this->assertSame('robots', $records[0]->name);
        $this->assertSame('noindex, nofollow', $records[0]->raw);
    }

    #[Test]
    public function it_finds_a_tag_with_the_attributes_reversed(): void
    {
        $records = (new MetaTagExtractor())->extract(
            '<head><meta content="noindex" name="robots" /></head>'
        );

        $this->assertCount(1, $records);
        $this->assertTrue($records[0]->validation->has('noindex'));
    }

    #[Test]
    public function it_accepts_single_quoted_attributes(): void
    {
        $records = (new MetaTagExtractor())->extract("<head><meta name='googlebot' content='noindex'></head>");

        $this->assertSame('googlebot', $records[0]->name);
    }

    #[Test]
    public function it_ignores_meta_tags_that_are_not_about_robots(): void
    {
        $records = (new MetaTagExtractor())->extract(
            '<head><meta name="description" content="noindex"><meta name="viewport" content="width=device-width"></head>'
        );

        $this->assertSame([], $records);
    }

    #[Test]
    public function it_ignores_tags_below_the_head(): void
    {
        $records = (new MetaTagExtractor())->extract(
            '<head><meta name="robots" content="noindex"></head><body><meta name="robots" content="index"></body>'
        );

        $this->assertCount(1, $records);
        $this->assertSame('noindex', $records[0]->raw);
    }

    #[Test]
    public function it_finds_several_tags(): void
    {
        $records = (new MetaTagExtractor())->extract(<<<'HTML'
            <head>
                <meta name="robots" content="index, follow">
                <meta name="googlebot" content="noarchive">
            </head>
            HTML);

        $this->assertCount(2, $records);
        $this->assertSame(['robots', 'googlebot'], array_map(static fn ($r): string => $r->name, $records));
    }

    #[Test]
    public function it_decodes_html_entities_in_a_content_value(): void
    {
        $records = (new MetaTagExtractor())->extract(
            '<head><meta name="robots" content="noindex,&#32;nofollow"></head>'
        );

        $this->assertTrue($records[0]->validation->has('nofollow'));
    }

    #[Test]
    public function a_commented_out_tag_is_not_read(): void
    {
        $html = '<head><!-- <meta name="robots" content="noindex"> --></head>';

        $this->assertSame([], (new MetaTagExtractor())->extract($html));
    }

    #[Test]
    public function a_tag_inside_noscript_or_script_is_not_read(): void
    {
        $html = '<head><noscript><meta name="robots" content="noindex"></noscript>'
            . '<script>document.write(\'<meta name="robots" content="noindex">\');</script></head>';

        $this->assertSame([], (new MetaTagExtractor())->extract($html));
    }

    #[Test]
    public function a_real_tag_after_a_comment_is_read(): void
    {
        $html = '<head><!-- old: <meta name="robots" content="index"> --><meta name="robots" content="noindex"></head>';

        $records = (new MetaTagExtractor())->extract($html);

        $this->assertCount(1, $records);
        $this->assertSame('noindex', $records[0]->raw);
    }

    #[Test]
    public function an_unterminated_comment_hides_everything_after_it(): void
    {
        $html = '<head><!-- <meta name="robots" content="noindex">';

        $this->assertSame([], (new MetaTagExtractor())->extract($html));
        $this->assertFalse((new MetaTagExtractor())->endsInsideTag($html));
    }

    #[Test]
    public function it_knows_when_html_stops_inside_a_meta_tag(): void
    {
        $extractor = new MetaTagExtractor();

        $this->assertTrue($extractor->endsInsideTag('<head><meta name="robots" content="noi'));
        $this->assertFalse($extractor->endsInsideTag('<head><meta name="robots" content="noindex">'));
    }
}
