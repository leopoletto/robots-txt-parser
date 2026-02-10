<?php

namespace Leopoletto\RobotsTxtParser\Tests;

use Leopoletto\RobotsTxtParser\Helpers\RobotsMerger;
use PHPUnit\Framework\TestCase;

class RobotsMergerTest extends TestCase
{
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = [
            'index' => true,
            'follow' => true,
            'max_snippet' => -1,
            'max_image_preview' => 'standard',
            'max_video_preview' => -1,
            'archive' => true,
            'translate' => true,
            'image_index' => true,
        ];
    }

    public function testEmptyInputsReturnDefaultPermissiveState(): void
    {
        $result = RobotsMerger::merge([], []);

        $this->assertSame($this->defaults, $result['effective_rules']);
    }

    public function testSourceCountsCorrect(): void
    {
        $meta = [
            $this->makeSource([['name' => 'noindex', 'value' => null]]),
        ];
        $headers = [
            $this->makeSource([['name' => 'nofollow', 'value' => null]]),
            $this->makeSource([['name' => 'noarchive', 'value' => null]]),
        ];

        $result = RobotsMerger::merge($meta, $headers);

        $this->assertSame(1, $result['sources']['meta_count']);
        $this->assertSame(2, $result['sources']['header_count']);
    }

    public function testMetaNoindex(): void
    {
        $meta = [$this->makeSource([['name' => 'noindex', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['index']);
    }

    public function testMetaNofollow(): void
    {
        $meta = [$this->makeSource([['name' => 'nofollow', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['follow']);
    }

    public function testMetaNoarchive(): void
    {
        $meta = [$this->makeSource([['name' => 'noarchive', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['archive']);
    }

    public function testMetaNotranslate(): void
    {
        $meta = [$this->makeSource([['name' => 'notranslate', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['translate']);
    }

    public function testMetaNoimageindex(): void
    {
        $meta = [$this->makeSource([['name' => 'noimageindex', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['image_index']);
    }

    public function testHeaderNoindex(): void
    {
        $headers = [$this->makeSource([['name' => 'noindex', 'value' => null]])];

        $result = RobotsMerger::merge([], $headers);

        $this->assertFalse($result['effective_rules']['index']);
    }

    public function testHeaderMaxSnippet(): void
    {
        $headers = [$this->makeSource([['name' => 'max-snippet', 'value' => '150']])];

        $result = RobotsMerger::merge([], $headers);

        $this->assertSame(150, $result['effective_rules']['max_snippet']);
    }

    public function testHeaderMaxImagePreview(): void
    {
        $headers = [$this->makeSource([['name' => 'max-image-preview', 'value' => 'large']])];

        $result = RobotsMerger::merge([], $headers);

        $this->assertSame('large', $result['effective_rules']['max_image_preview']);
    }

    public function testHeaderOverridesMeta(): void
    {
        $meta = [$this->makeSource([['name' => 'noindex', 'value' => null]])];
        $headers = [$this->makeSource([['name' => 'index', 'value' => null]])];

        $result = RobotsMerger::merge($meta, $headers);

        $this->assertTrue($result['effective_rules']['index']);
    }

    public function testNoneOnlySetsIndexFalse(): void
    {
        $meta = [$this->makeSource([['name' => 'none', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertFalse($result['effective_rules']['index']);
        // Per actual code, 'none' maps to noindex only (not nofollow)
        // follow stays default true
    }

    public function testAllSetsIndexTrue(): void
    {
        // First set noindex, then override with all
        $meta = [
            $this->makeSource([['name' => 'noindex', 'value' => null]]),
            $this->makeSource([['name' => 'all', 'value' => null]]),
        ];

        $result = RobotsMerger::merge($meta, []);

        $this->assertTrue($result['effective_rules']['index']);
    }

    public function testNosnippetSetsMaxSnippetZero(): void
    {
        $meta = [$this->makeSource([['name' => 'nosnippet', 'value' => null]])];

        $result = RobotsMerger::merge($meta, []);

        $this->assertSame(0, $result['effective_rules']['max_snippet']);
    }

    public function testMaxVideoPreviewSetsIntegerValue(): void
    {
        $headers = [$this->makeSource([['name' => 'max-video-preview', 'value' => '30']])];

        $result = RobotsMerger::merge([], $headers);

        $this->assertSame(30, $result['effective_rules']['max_video_preview']);
    }

    public function testMaxImagePreviewIgnoresInvalidValue(): void
    {
        $headers = [$this->makeSource([['name' => 'max-image-preview', 'value' => 'medium']])];

        $result = RobotsMerger::merge([], $headers);

        $this->assertSame('standard', $result['effective_rules']['max_image_preview']);
    }

    public function testComplexMultiDirectiveScenario(): void
    {
        $meta = [
            $this->makeSource([
                ['name' => 'noindex', 'value' => null],
                ['name' => 'nofollow', 'value' => null],
                ['name' => 'noarchive', 'value' => null],
            ]),
        ];

        $headers = [
            $this->makeSource([
                ['name' => 'index', 'value' => null],
                ['name' => 'max-snippet', 'value' => '200'],
                ['name' => 'max-image-preview', 'value' => 'large'],
                ['name' => 'max-video-preview', 'value' => '60'],
            ]),
        ];

        $result = RobotsMerger::merge($meta, $headers);

        // Header overrides meta for index
        $this->assertTrue($result['effective_rules']['index']);
        // Meta nofollow not overridden by header
        $this->assertFalse($result['effective_rules']['follow']);
        // Meta noarchive not overridden
        $this->assertFalse($result['effective_rules']['archive']);
        // Header parametric values
        $this->assertSame(200, $result['effective_rules']['max_snippet']);
        $this->assertSame('large', $result['effective_rules']['max_image_preview']);
        $this->assertSame(60, $result['effective_rules']['max_video_preview']);
    }

    /**
     * Helper to create a source entry with directives array
     */
    private function makeSource(array $directives): array
    {
        return ['directives' => $directives];
    }
}
