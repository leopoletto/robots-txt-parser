<?php

namespace Leopoletto\RobotsTxtParser\Tests;

use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use PHPUnit\Framework\TestCase;

class HeaderDirectiveTest extends TestCase
{
    public function testSimpleNoindexHeader(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['noindex']);

        $this->assertCount(1, $results);
        $this->assertSame('*', $results[0]['user_agent']);
        $this->assertCount(1, $results[0]['directives']);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
    }

    public function testMultipleDirectivesInSingleHeader(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['noindex, nofollow']);

        $this->assertCount(1, $results);
        $this->assertCount(2, $results[0]['directives']);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
        $this->assertSame('nofollow', $results[0]['directives'][1]['name']);
    }

    public function testMultipleSeparateHeaders(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['noindex', 'nofollow']);

        $this->assertCount(2, $results);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
        $this->assertSame('nofollow', $results[1]['directives'][0]['name']);
    }

    public function testEmptyArrayReturnsEmptyResult(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders([]);

        $this->assertEmpty($results);
    }

    public function testUserAgentPrefix(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['googlebot: noindex']);

        $this->assertCount(1, $results);
        $this->assertSame('googlebot', $results[0]['user_agent']);
        $this->assertTrue($results[0]['user_agent_valid']);
        $this->assertCount(1, $results[0]['directives']);
        $this->assertSame('noindex', $results[0]['directives'][0]['name']);
    }

    public function testUnknownUserAgentPrefix(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['unknownbot: noindex']);

        $this->assertCount(1, $results);
        $this->assertSame('unknownbot', $results[0]['user_agent']);
        $this->assertFalse($results[0]['user_agent_valid']);

        $uaIssues = array_filter(
            $results[0]['issues'],
            fn ($i) => $i['type'] === 'unknown_user_agent'
        );
        $this->assertNotEmpty($uaIssues);
    }

    public function testNoPrefixDefaultsToWildcard(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['noindex']);

        $this->assertSame('*', $results[0]['user_agent']);
        $this->assertTrue($results[0]['user_agent_valid']);
    }

    public function testUserAgentCaseNormalization(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['Googlebot: noindex']);

        $this->assertSame('googlebot', $results[0]['user_agent']);
        $this->assertTrue($results[0]['user_agent_valid']);
    }

    public function testConflictDetectionIntegration(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['index, noindex']);

        $this->assertFalse($results[0]['valid']);
        $this->assertNotEmpty($results[0]['conflicts']);
    }

    public function testFullSpecDetectionIntegration(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders([
            'index, max-snippet:150, max-image-preview:large, max-video-preview:-1',
        ]);

        $this->assertTrue($results[0]['is_full_spec']);
    }

    public function testParametricDirectiveWithUserAgentPrefix(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['googlebot: max-snippet:150']);

        $this->assertSame('googlebot', $results[0]['user_agent']);
        $this->assertSame('max-snippet', $results[0]['directives'][0]['name']);
        $this->assertSame('150', $results[0]['directives'][0]['value']);
        $this->assertTrue($results[0]['directives'][0]['valid']);
    }

    public function testMergedIssuesFromValidatorAndUserAgentValidation(): void
    {
        $results = HeaderDirective::parseXRobotsTagHeaders(['unknownbot: noodp']);

        // Should have both deprecated issue (from directive validator) and unknown_user_agent issue
        $types = array_column($results[0]['issues'], 'type');
        $this->assertContains('deprecated', $types);
        $this->assertContains('unknown_user_agent', $types);
    }
}
