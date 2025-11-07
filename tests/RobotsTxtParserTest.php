<?php

namespace Leopoletto\RobotsTxtParser\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    private string $testRobotsTxtContent;
    private string $testRobotsTxtFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Test robots.txt content
        $this->testRobotsTxtContent = <<<'ROBOTS'
User-agent: *
User-agent: GPT-User
Disallow: /article
Disallow: /site-explorer/ajax/
Allow: /site-explorer/$
Disallow: /site-explorer/*
Allow: /link-intersect/$
Disallow: /link-intersect/*
Disallow: /v4*
Disallow: /blog/*?s=*
Disallow: /blog/*?archive*
Disallow: /seo/for/*?*draft
Disallow: /academy/*?*draft
Disallow: /seo-toolbar/welcome
Disallow: /seo-toolbar/uninstall
Disallow: /*/seo-toolbar/welcome
Disallow: /*/seo-toolbar/uninstall
Disallow: /*?input
Disallow: /draft/*
Disallow: /academy/draft/*
Allow: /agencies/*?services[]=*
Allow: /agencies/*&services[]=*
Disallow: /agencies/*?*languages[]=*
Disallow: /agencies/*&*languages[]=*
Disallow: /agencies/*?*industries[]=*
Disallow: /agencies/*&*industries[]=*
Disallow: /agencies/*?*budget=*
Disallow: /agencies/*&*budget=*
Disallow: /agencies/*?*businessSize=*
Disallow: /agencies/*&*businessSize=*
Disallow: /cdn-cgi/
ROBOTS;

        // Create temporary file for testing
        $this->testRobotsTxtFile = sys_get_temp_dir() . '/test_robots_' . uniqid() . '.txt';
        file_put_contents($this->testRobotsTxtFile, $this->testRobotsTxtContent);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temporary file
        if (file_exists($this->testRobotsTxtFile)) {
            unlink($this->testRobotsTxtFile);
        }
    }

    /**
     * Create a mock HTTP client that returns the robots.txt content
     */
    private function createMockHttpClient(string $robotsContent): Client
    {
        $mock = new MockHandler([
            // Response for robots.txt
            new Response(200, [], $robotsContent),
        ]);

        $handlerStack = HandlerStack::create($mock);

        return new Client(['handler' => $handlerStack]);
    }

    public function testParseUrlWithMockedClient(): void
    {
        $parser = new RobotsTxtParser($this->createMockHttpClient($this->testRobotsTxtContent));
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');

        $response = $parser->parseUrl('https://example.com/robots.txt');
        $records = $response->records();

        // Verify we got records
        $this->assertGreaterThan(0, $records->count());

        // Verify user agents
        $userAgents = $records->userAgents();
        $this->assertCount(2, $userAgents);
        $this->assertTrue($userAgents->has('*'));
        $this->assertTrue($userAgents->has('GPT-User'));

        // Verify directives
        $disallowed = $records->disallowed();
        $this->assertCount(25, $disallowed);

        $allowed = $records->allowed();
        $this->assertCount(4, $allowed);
    }

    public function testParseFile(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Verify we got records
        $this->assertGreaterThan(0, $records->count());

        // Verify user agents
        $userAgents = $records->userAgents();
        $this->assertCount(2, $userAgents);
        $this->assertTrue($userAgents->has('*'));
        $this->assertTrue($userAgents->has('GPT-User'));

        // Verify directives
        $disallowed = $records->disallowed();
        $this->assertCount(25, $disallowed);

        $allowed = $records->allowed();
        $this->assertCount(4, $allowed);
    }

    public function testParseText(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseText($this->testRobotsTxtContent);
        $records = $response->records();

        // Verify we got records
        $this->assertGreaterThan(0, $records->count());

        // Verify user agents (parseText lowercases, so check for lowercase version)
        $userAgents = $records->userAgents();
        $this->assertCount(2, $userAgents);
        $this->assertTrue($userAgents->has('*'));
        // parseText lowercases the user agent value
        $this->assertTrue($userAgents->has('gpt-user') || $userAgents->has('GPT-User'));

        // Verify directives
        $disallowed = $records->disallowed();
        $this->assertCount(25, $disallowed);

        $allowed = $records->allowed();
        $this->assertCount(4, $allowed);
    }

    public function testAllParsingMethodsProduceSameResults(): void
    {
        $parser = new RobotsTxtParser($this->createMockHttpClient($this->testRobotsTxtContent));
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');

        // Parse using all three methods
        $urlResponse = $parser->parseUrl('https://example.com/robots.txt');
        $fileResponse = $parser->parseFile($this->testRobotsTxtFile);
        $textResponse = $parser->parseText($this->testRobotsTxtContent);

        $urlRecords = $urlResponse->records();
        $fileRecords = $fileResponse->records();
        $textRecords = $textResponse->records();

        // Compare user agents - URL and File should match exactly
        $this->assertEquals(
            $this->normalizeUserAgents($urlRecords->userAgents()),
            $this->normalizeUserAgents($fileRecords->userAgents())
        );

        // Compare disallowed directives - URL and File should match exactly
        $this->assertEquals(
            $this->normalizeDirectives($urlRecords->disallowed()),
            $this->normalizeDirectives($fileRecords->disallowed())
        );

        // Compare allowed directives - URL and File should match exactly
        $this->assertEquals(
            $this->normalizeDirectives($urlRecords->allowed()),
            $this->normalizeDirectives($fileRecords->allowed())
        );

        // Compare crawl delays - URL and File should match exactly
        $this->assertEquals(
            $this->normalizeDirectives($urlRecords->crawlDelay()),
            $this->normalizeDirectives($fileRecords->crawlDelay())
        );

        // For text parsing, verify it produces the same structure (case may differ)
        // Verify counts match
        $this->assertEquals(
            $fileRecords->disallowed()->count(),
            $textRecords->disallowed()->count()
        );
        $this->assertEquals(
            $fileRecords->allowed()->count(),
            $textRecords->allowed()->count()
        );
        $this->assertEquals(
            $fileRecords->userAgents()->count(),
            $textRecords->userAgents()->count()
        );
    }

    public function testUserAgentGroups(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test that both user agents in the same group return the same directives
        $disallowedStar = $records->disallowed('*')->toArray();
        $disallowedGPT = $records->disallowed('GPT-User')->toArray();

        $this->assertCount(25, $disallowedStar);
        $this->assertCount(25, $disallowedGPT);
        $this->assertEquals(
            $this->normalizeDirectivesArray($disallowedStar),
            $this->normalizeDirectivesArray($disallowedGPT)
        );

        $allowedStar = $records->allowed('*')->toArray();
        $allowedGPT = $records->allowed('GPT-User')->toArray();

        $this->assertCount(4, $allowedStar);
        $this->assertCount(4, $allowedGPT);
        $this->assertEquals(
            $this->normalizeDirectivesArray($allowedStar),
            $this->normalizeDirectivesArray($allowedGPT)
        );
    }

    public function testDisplayUserAgentExpansion(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Without displayUserAgent - should return unique directives
        $disallowedWithout = $records->disallowed()->toArray();
        $this->assertCount(25, $disallowedWithout);

        // With displayUserAgent and no user agent specified - should return unique directives with user agent array
        $disallowedWith = $records->displayUserAgent(true)->disallowed()->toArray();
        $this->assertCount(25, $disallowedWith); // Still 25 unique directives

        // Verify all entries have userAgent field as an array
        foreach ($disallowedWith as $item) {
            $this->assertArrayHasKey('userAgent', $item);
            $this->assertIsArray($item['userAgent']);
            $this->assertContains('*', $item['userAgent']);
            $this->assertContains('GPT-User', $item['userAgent']);
        }

        // With displayUserAgent and specific user agent - should expand for all user agents in group
        $disallowedWithUA = $records->displayUserAgent(true)->disallowed('*')->toArray();
        $this->assertCount(50, $disallowedWithUA); // 25 directives × 2 user agents

        // Verify all entries have userAgent field as a string
        foreach ($disallowedWithUA as $item) {
            $this->assertArrayHasKey('userAgent', $item);
            $this->assertIsString($item['userAgent']);
            $this->assertContains($item['userAgent'], ['*', 'GPT-User']);
        }
    }

    public function testNoDuplicatesWhenNoUserAgentSpecified(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Should return unique directives (no duplicates)
        $disallowed = $records->disallowed()->toArray();
        $paths = array_column($disallowed, 'path');

        // Check for duplicates
        $uniquePaths = array_unique($paths);
        $this->assertEquals(count($paths), count($uniquePaths), 'Found duplicate paths in disallowed directives');
    }

    public function testSyntaxErrorCount(): void
    {
        $parser = new RobotsTxtParser();

        // Valid robots.txt should have no syntax errors
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();
        $syntaxErrors = $records->syntaxErrors();
        $this->assertCount(0, $syntaxErrors);

        // Invalid robots.txt with directive before user agent
        $invalidContent = "Disallow: /test\nUser-agent: *";
        $response = $parser->parseText($invalidContent);
        $records = $response->records();
        $syntaxErrors = $records->syntaxErrors();
        $this->assertGreaterThan(0, $syntaxErrors->count(), 'Should have syntax error for directive before user agent');
    }

    /**
     * Normalize user agents collection for comparison
     */
    private function normalizeUserAgents($userAgents): array
    {
        $normalized = [];
        foreach ($userAgents as $key => $value) {
            $normalized[$key] = [
                'line' => $value['line'],
                'userAgent' => $value['userAgent'],
                'allow' => $this->normalizeDirectivesArray($value['allow']),
                'disallow' => $this->normalizeDirectivesArray($value['disallow']),
                'crawlDelay' => $this->normalizeDirectivesArray($value['crawlDelay']),
            ];
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * Normalize directives collection for comparison
     */
    private function normalizeDirectives($directives): array
    {
        return $this->normalizeDirectivesArray($directives->toArray());
    }

    /**
     * Normalize directives array for comparison
     */
    private function normalizeDirectivesArray(array $directives): array
    {
        $normalized = [];
        foreach ($directives as $directive) {
            $key = $directive['line'] . '|' . ($directive['path'] ?? ($directive['delay'] ?? ''));
            $normalized[$key] = [
                'line' => $directive['line'],
                'directive' => $directive['directive'],
                'path' => $directive['path'] ?? null,
                'delay' => $directive['delay'] ?? null,
            ];
        }
        ksort($normalized);

        return array_values($normalized);
    }
}
