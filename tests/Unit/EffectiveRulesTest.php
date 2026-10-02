<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
use Leopoletto\RobotsTxtParser\Extraction\MetaTagExtractor;
use Leopoletto\RobotsTxtParser\Model\EffectiveRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectiveRulesTest extends TestCase
{
    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function resolve(string $html = '', array $headers = []): array
    {
        return EffectiveRules::from(
            (new MetaTagExtractor())->extract($html),
            (new HeaderExtractor())->extract($headers),
        )->toArray()['effective_rules'];
    }

    #[Test]
    public function it_defaults_to_permissive(): void
    {
        $rules = $this->resolve();

        $this->assertTrue($rules['index']);
        $this->assertTrue($rules['follow']);
        $this->assertSame(-1, $rules['max_snippet']);
        $this->assertSame('standard', $rules['max_image_preview']);
    }

    #[Test]
    public function a_noindex_meta_tag_turns_indexing_off(): void
    {
        $rules = $this->resolve('<head><meta name="robots" content="noindex"></head>');

        $this->assertFalse($rules['index']);
    }

    #[Test]
    public function none_disables_index_and_follow(): void
    {
        $rules = $this->resolve('<head><meta name="robots" content="none"></head>');

        $this->assertFalse($rules['index']);
        $this->assertFalse($rules['follow']);
    }

    #[Test]
    public function a_later_index_never_reopens_an_earlier_noindex(): void
    {
        // The most restrictive reading wins, whatever the order.
        $rules = $this->resolve(
            '<head><meta name="robots" content="noindex"></head>',
            ['index, follow'],
        );

        $this->assertFalse($rules['index']);
    }

    #[Test]
    public function the_tighter_snippet_limit_wins(): void
    {
        $rules = $this->resolve(
            '<head><meta name="robots" content="max-snippet:100"></head>',
            ['max-snippet:20'],
        );

        $this->assertSame(20, $rules['max_snippet']);
    }

    #[Test]
    public function an_unlimited_snippet_yields_to_a_concrete_limit(): void
    {
        $rules = $this->resolve('<head><meta name="robots" content="max-snippet:50"></head>');

        $this->assertSame(50, $rules['max_snippet']);
    }

    #[Test]
    public function nosnippet_beats_any_max_snippet(): void
    {
        $rules = $this->resolve('<head><meta name="robots" content="max-snippet:50, nosnippet"></head>');

        $this->assertSame(0, $rules['max_snippet']);
    }

    #[Test]
    public function the_tighter_image_preview_wins(): void
    {
        $rules = $this->resolve(
            '<head><meta name="robots" content="max-image-preview:large"></head>',
            ['max-image-preview:none'],
        );

        $this->assertSame('none', $rules['max_image_preview']);
    }

    #[Test]
    public function headers_can_restrict_beyond_the_meta_tags(): void
    {
        $rules = $this->resolve(
            '<head><meta name="robots" content="index, follow"></head>',
            ['noarchive'],
        );

        $this->assertFalse($rules['archive']);
        $this->assertTrue($rules['index']);
    }

    #[Test]
    public function it_counts_its_sources(): void
    {
        $sources = EffectiveRules::from(
            (new MetaTagExtractor())->extract('<head><meta name="robots" content="noindex"></head>'),
            (new HeaderExtractor())->extract(['noarchive', 'nofollow']),
        )->toArray()['sources'];

        $this->assertSame(1, $sources['meta_count']);
        $this->assertSame(2, $sources['header_count']);
    }
}
