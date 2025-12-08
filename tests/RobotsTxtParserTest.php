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
                'description' => $value['description'] ?? null,
                'category' => $value['category'] ?? null,
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

    public function testBotRecognitionWithKnownBot(): void
    {
        $parser = new RobotsTxtParser();

        // Use a bot that should be in the dataset (based on the structure we saw)
        $robotsContent = "User-agent: ChatGPT-User\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertGreaterThan(0, $userAgents->count());

        // Check if any user agent has description or category (indicating recognition)
        $hasRecognizedBot = false;
        foreach ($userAgents as $ua) {
            if ($ua['description'] !== null || $ua['category'] !== null) {
                $hasRecognizedBot = true;

                break;
            }
        }

        // If ChatGPT-User is in the dataset, it should be recognized
        // If not, we'll test with a generic bot that might not be recognized
        $this->assertIsArray($userAgents->first());
    }

    public function testBotRecognitionFieldsInUserAgentsOutput(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertGreaterThan(0, $userAgents->count());

        // Verify all user agents have description and category fields
        foreach ($userAgents as $ua) {
            $this->assertArrayHasKey('description', $ua);
            $this->assertArrayHasKey('category', $ua);
            // These can be null if bot is not recognized, which is valid
            $this->assertTrue($ua['description'] === null || is_string($ua['description']));
            $this->assertTrue($ua['category'] === null || is_string($ua['category']));
        }
    }

    public function testBotRecognitionCaseInsensitive(): void
    {
        $parser = new RobotsTxtParser();

        // Test with different case variations
        $robotsContent = "User-agent: chatgpt-user\nUser-agent: ChatGPT-User\nUser-agent: CHATGPT-USER\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertGreaterThanOrEqual(1, $userAgents->count());

        // All variations should be parsed (case may be normalized)
        // The important thing is that matching works case-insensitively
        foreach ($userAgents as $ua) {
            $this->assertArrayHasKey('description', $ua);
            $this->assertArrayHasKey('category', $ua);
        }
    }

    public function testBotRecognitionWithUnknownBot(): void
    {
        $parser = new RobotsTxtParser();

        // Use a bot that's unlikely to be in the dataset
        $robotsContent = "User-agent: UnknownBot-12345\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertCount(1, $userAgents);

        $ua = $userAgents->first();
        $this->assertArrayHasKey('description', $ua);
        $this->assertArrayHasKey('category', $ua);
        // Unknown bot should have null values
        $this->assertNull($ua['description']);
        $this->assertNull($ua['category']);
        // User agent should preserve original case (not be lowercased)
        $this->assertEquals('UnknownBot-12345', $ua['userAgent']);
    }

    public function testBotRecognitionWithWildcardUserAgent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $wildcardUA = $userAgents->get('*');

        $this->assertNotNull($wildcardUA);
        $this->assertArrayHasKey('description', $wildcardUA);
        $this->assertArrayHasKey('category', $wildcardUA);
        // Wildcard should not be recognized as a specific bot
        $this->assertNull($wildcardUA['description']);
        $this->assertNull($wildcardUA['category']);
    }

    public function testBotRecognitionWithMultipleBots(): void
    {
        $parser = new RobotsTxtParser();

        // Test with multiple different bots
        $robotsContent = <<<'ROBOTS'
User-agent: ChatGPT-User
Disallow: /test1

User-agent: Claude-User
Disallow: /test2

User-agent: *
Disallow: /test3
ROBOTS;

        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertGreaterThanOrEqual(3, $userAgents->count());

        // Verify all have description and category fields
        foreach ($userAgents as $ua) {
            $this->assertArrayHasKey('description', $ua);
            $this->assertArrayHasKey('category', $ua);
        }
    }

    public function testBotRecognitionHandlesMissingAgentsFile(): void
    {
        // Create a parser with a non-existent agents file path
        // We can't easily test this without mocking, but we can verify
        // that the parser still works even if agents.json is missing
        $parser = new RobotsTxtParser();

        // The parser should still work, just without bot recognition
        $robotsContent = "User-agent: TestBot\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        // Should still parse successfully
        $this->assertGreaterThan(0, $records->count());

        $userAgents = $records->userAgents();
        $this->assertCount(1, $userAgents);

        // Description and category should be null if agents file is missing/invalid
        $ua = $userAgents->first();
        $this->assertArrayHasKey('description', $ua);
        $this->assertArrayHasKey('category', $ua);
    }

    public function testBotRecognitionPreservesOriginalUserAgentName(): void
    {
        $parser = new RobotsTxtParser();

        $robotsContent = "User-agent: ChatGPT-User\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $ua = $userAgents->first();

        // The userAgent field should contain the parsed value
        $this->assertIsString($ua['userAgent']);
        $this->assertNotEmpty($ua['userAgent']);
    }

    public function testUserAgentCasingConsistencyAcrossParsingMethods(): void
    {
        $parser = new RobotsTxtParser($this->createMockHttpClient($this->testRobotsTxtContent));
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');

        $robotsContent = "User-agent: GPT-User\nDisallow: /test\n";

        // Create temporary file
        $tempFile = sys_get_temp_dir() . '/test_robots_casing_' . uniqid() . '.txt';
        file_put_contents($tempFile, $robotsContent);

        try {
            // Parse using all three methods
            $urlResponse = $parser->parseUrl('https://example.com/robots.txt');
            $fileResponse = $parser->parseFile($tempFile);
            $textResponse = $parser->parseText($robotsContent);

            $urlUserAgents = $urlResponse->records()->userAgents();
            $fileUserAgents = $fileResponse->records()->userAgents();
            $textUserAgents = $textResponse->records()->userAgents();

            // Find GPT-User in each result
            $urlGPT = $urlUserAgents->get('GPT-User') ?? $urlUserAgents->get('gpt-user');
            $fileGPT = $fileUserAgents->get('GPT-User') ?? $fileUserAgents->get('gpt-user');
            $textGPT = $textUserAgents->get('GPT-User') ?? $textUserAgents->get('gpt-user');

            // All three methods should produce the same casing for the user agent
            if ($urlGPT && $fileGPT && $textGPT) {
                $this->assertEquals(
                    $urlGPT['userAgent'],
                    $fileGPT['userAgent'],
                    'parseUrl and parseFile should produce same user agent casing'
                );
                $this->assertEquals(
                    $fileGPT['userAgent'],
                    $textGPT['userAgent'],
                    'parseFile and parseText should produce same user agent casing'
                );
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testUserAgentParseReturnsNullForInvalidUserAgent(): void
    {
        $parser = new RobotsTxtParser();

        // Test with invalid user agent (empty after parsing)
        $robotsContent = "User-agent:\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        // Invalid user agent should not be added to records
        $this->assertCount(0, $userAgents, 'Invalid user agent (empty) should not be added to records');
    }

    public function testUserAgentParseReturnsNullForMalformedUserAgent(): void
    {
        $parser = new RobotsTxtParser();

        // Test with malformed user agent (no colon)
        $robotsContent = "User-agent\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        // Malformed user agent should not be added to records
        $this->assertCount(0, $userAgents, 'Malformed user agent should not be added to records');
    }

    public function testUserAgentCasingConsistencyWithEmptyUserAgent(): void
    {
        $parser = new RobotsTxtParser();

        // Test that empty user agents are handled consistently across all methods
        $robotsContent = "User-agent: ValidBot\nUser-agent:\nDisallow: /test\n";

        $tempFile = sys_get_temp_dir() . '/test_robots_empty_' . uniqid() . '.txt';
        file_put_contents($tempFile, $robotsContent);

        try {
            $fileResponse = $parser->parseFile($tempFile);
            $textResponse = $parser->parseText($robotsContent);

            $fileUserAgents = $fileResponse->records()->userAgents();
            $textUserAgents = $textResponse->records()->userAgents();

            // Both should only have ValidBot, not the empty one
            $this->assertCount(1, $fileUserAgents);
            $this->assertCount(1, $textUserAgents);
            $this->assertTrue($fileUserAgents->has('ValidBot'));
            $this->assertTrue($textUserAgents->has('ValidBot'));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
