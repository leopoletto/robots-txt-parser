<?php

namespace Leopoletto\RobotsTxtParser\Tests;

use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use PHPUnit\Framework\TestCase;

class MetaDirectiveTest extends TestCase
{
    public function testStandardRobotsMetaTag(): void
    {
        $html = '<meta name="robots" content="noindex, nofollow">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('robots', $results[0]['tag_name']);
        $this->assertCount(2, $results[0]['directives']);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
        $this->assertSame('nofollow', $results[0]['directives'][1]['name']);
    }

    public function testReversedAttributeOrder(): void
    {
        $html = '<meta content="noindex" name="robots">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('robots', $results[0]['tag_name']);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
    }

    public function testGooglebotMetaTag(): void
    {
        $html = '<meta name="googlebot" content="noindex">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('googlebot', $results[0]['tag_name']);
    }

    public function testGooglebotNewsMetaTag(): void
    {
        $html = '<meta name="googlebot-news" content="nosnippet">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('googlebot-news', $results[0]['tag_name']);
    }

    public function testBingbotMetaTag(): void
    {
        $html = '<meta name="bingbot" content="noarchive">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('bingbot', $results[0]['tag_name']);
    }

    public function testMultipleMetaTagsInHtml(): void
    {
        $html = '<meta name="robots" content="noindex"><meta name="googlebot" content="nofollow">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(2, $results);
        $this->assertSame('robots', $results[0]['tag_name']);
        $this->assertSame('googlebot', $results[1]['tag_name']);
    }

    public function testFullHtmlDocumentWithMultipleTags(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="robots" content="index, follow">
    <meta name="googlebot" content="max-snippet:150">
    <meta name="description" content="A test page">
</head>
<body><p>Hello</p></body>
</html>
HTML;

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(2, $results);
        $this->assertSame('robots', $results[0]['tag_name']);
        $this->assertSame('googlebot', $results[1]['tag_name']);
    }

    public function testConflictDetectionIntegration(): void
    {
        $html = '<meta name="robots" content="index, noindex">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertFalse($results[0]['valid']);
        $this->assertNotEmpty($results[0]['conflicts']);
    }

    public function testFullSpecDetectionIntegration(): void
    {
        $html = '<meta name="robots" content="index, max-snippet:150, max-image-preview:large, max-video-preview:-1">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertTrue($results[0]['is_full_spec']);
    }

    public function testRedundancyDetectionIntegration(): void
    {
        $html = '<meta name="robots" content="all, index">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertNotEmpty($results[0]['redundancies']);
    }

    public function testCaseInsensitiveNameMatching(): void
    {
        $html = '<meta name="ROBOTS" content="noindex">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertCount(1, $results);
        $this->assertSame('robots', $results[0]['tag_name']);
    }

    public function testNonRobotMetaTagsIgnored(): void
    {
        $html = '<meta name="description" content="test"><meta name="keywords" content="foo">';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertEmpty($results);
    }

    public function testNoMetaTagsReturnsEmptyResult(): void
    {
        $html = '<div>No meta tags here</div>';

        $results = MetaDirective::parseMetaTags($html);

        $this->assertEmpty($results);
    }

    public function testEmptyStringReturnsEmptyResult(): void
    {
        $results = MetaDirective::parseMetaTags('');

        $this->assertEmpty($results);
    }
}
