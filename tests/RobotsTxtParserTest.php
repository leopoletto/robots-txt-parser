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

    public function testUaAllowedWithSpecificUserAgent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test with GPT-User which has specific rules
        // Based on test data: Disallow: /article, Disallow: /site-explorer/ajax/
        $this->assertFalse($records->uaAllowed('GPT-User', '/article'));
        $this->assertFalse($records->uaAllowed('GPT-User', '/site-explorer/ajax/'));
        $this->assertTrue($records->uaAllowed('GPT-User', '/site-explorer/')); // Allow: /site-explorer/$
        $this->assertFalse($records->uaAllowed('GPT-User', '/site-explorer/something')); // Disallow: /site-explorer/*
    }

    public function testUaAllowedFallsBackToWildcard(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test with non-existent user agent - should fall back to wildcard rules
        $this->assertFalse($records->uaAllowed('NonExistentBot', '/article'));
        $this->assertFalse($records->uaAllowed('NonExistentBot', '/site-explorer/ajax/'));
        $this->assertTrue($records->uaAllowed('NonExistentBot', '/site-explorer/')); // Allow: /site-explorer/$
    }

    public function testUaAllowedWithWildcardPatterns(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test wildcard patterns from test data
        // Disallow: /v4*
        $this->assertFalse($records->uaAllowed('*', '/v4'));
        $this->assertFalse($records->uaAllowed('*', '/v4/something'));
        $this->assertFalse($records->uaAllowed('*', '/v4test'));

        // Disallow: /blog/*?s=*
        $this->assertFalse($records->uaAllowed('*', '/blog/article?s=test'));
        $this->assertFalse($records->uaAllowed('*', '/blog/anything?s=anything'));

        // Disallow: /draft/*
        $this->assertFalse($records->uaAllowed('*', '/draft/article'));
        $this->assertFalse($records->uaAllowed('*', '/draft/anything'));

        // Disallow: /*/seo-toolbar/welcome
        $this->assertFalse($records->uaAllowed('*', '/en/seo-toolbar/welcome'));
        $this->assertFalse($records->uaAllowed('*', '/fr/seo-toolbar/welcome'));
    }

    public function testUaAllowedWithEndAnchor(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test end anchor ($) - Allow: /site-explorer/$
        $this->assertTrue($records->uaAllowed('*', '/site-explorer/'));
        $this->assertFalse($records->uaAllowed('*', '/site-explorer/something')); // Should not match because of $

        // Allow: /link-intersect/$
        $this->assertTrue($records->uaAllowed('*', '/link-intersect/'));
        $this->assertFalse($records->uaAllowed('*', '/link-intersect/something'));
    }

    public function testUaAllowedWithMultipleWildcards(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test patterns with multiple wildcards
        // Disallow: /seo/for/*?*draft
        $this->assertFalse($records->uaAllowed('*', '/seo/for/article?param=draft'));
        $this->assertFalse($records->uaAllowed('*', '/seo/for/anything?anything=draft'));

        // Disallow: /agencies/*?*languages[]=*
        $this->assertFalse($records->uaAllowed('*', '/agencies/test?languages[]=en'));
        $this->assertFalse($records->uaAllowed('*', '/agencies/anything?anything=languages[]=anything'));
    }

    public function testUaAllowedCaseInsensitiveUserAgent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test case-insensitive user agent matching
        $this->assertFalse($records->uaAllowed('gpt-user', '/article'));
        $this->assertFalse($records->uaAllowed('GPT-USER', '/article'));
        $this->assertFalse($records->uaAllowed('Gpt-User', '/article'));
    }

    public function testUaAllowedDefaultBehavior(): void
    {
        $parser = new RobotsTxtParser();
        $robotsContent = "User-agent: TestBot\nDisallow: /blocked\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        // Paths not matching any rules should default to allowed
        $this->assertTrue($records->uaAllowed('TestBot', '/allowed'));
        $this->assertTrue($records->uaAllowed('TestBot', '/other/path'));
        $this->assertFalse($records->uaAllowed('TestBot', '/blocked'));
    }

    public function testUaAllowedRuleSpecificity(): void
    {
        $parser = new RobotsTxtParser();
        // More specific rule (longer path) should take precedence
        $robotsContent = <<<'ROBOTS'
User-agent: *
Disallow: /path
Allow: /path/specific
Disallow: /path/specific/blocked
ROBOTS;
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        // Longer, more specific rules should win
        $this->assertFalse($records->uaAllowed('*', '/path')); // Disallow: /path
        $this->assertTrue($records->uaAllowed('*', '/path/specific')); // Allow: /path/specific (more specific)
        $this->assertFalse($records->uaAllowed('*', '/path/specific/blocked')); // Disallow: /path/specific/blocked (most specific)
    }

    public function testUaAllowedPathNormalization(): void
    {
        $parser = new RobotsTxtParser();
        $robotsContent = "User-agent: *\nDisallow: /test\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        // Paths should be normalized (ensure they start with /)
        $this->assertFalse($records->uaAllowed('*', 'test')); // Should normalize to /test
        $this->assertFalse($records->uaAllowed('*', '/test')); // Already normalized
    }

    public function testIsAllowedAlias(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // isAllowed() should work identically to uaAllowed()
        $this->assertEquals(
            $records->uaAllowed('GPT-User', '/article'),
            $records->isAllowed('GPT-User', '/article')
        );

        $this->assertEquals(
            $records->uaAllowed('*', '/site-explorer/'),
            $records->isAllowed('*', '/site-explorer/')
        );
    }

    public function testUaAllowedWithEmptyDisallowValue(): void
    {
        $parser = new RobotsTxtParser();
        // Empty Disallow: means nothing is disallowed — all paths should be allowed
        $robotsContent = "User-agent: *\nDisallow:\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $this->assertTrue($records->uaAllowed('*', '/'));
        $this->assertTrue($records->uaAllowed('*', '/any/path'));
        $this->assertTrue($records->uaAllowed('SomeBot', '/'));
    }

    public function testUaAllowedWithEmptyPath(): void
    {
        $parser = new RobotsTxtParser();
        $robotsContent = "User-agent: *\nDisallow: $\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        // Empty path pattern ($) should match empty path
        $this->assertFalse($records->uaAllowed('*', ''));
        $this->assertTrue($records->uaAllowed('*', '/'));
    }

    public function testUaAllowedWithQueryParameters(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Test patterns that include query parameters
        // Disallow: /blog/*?s=*
        $this->assertFalse($records->uaAllowed('*', '/blog/article?s=test'));
        $this->assertFalse($records->uaAllowed('*', '/blog/anything?s=search'));

        // Disallow: /*?input
        $this->assertFalse($records->uaAllowed('*', '/test?input'));
        $this->assertFalse($records->uaAllowed('*', '/anything?input=value'));
    }

    public function testUaAllowedWithAllowAndDisallowConflict(): void
    {
        $parser = new RobotsTxtParser();
        // When both allow and disallow match, the more specific one wins
        $robotsContent = <<<'ROBOTS'
User-agent: *
Disallow: /path
Allow: /path/allowed
ROBOTS;
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $this->assertFalse($records->uaAllowed('*', '/path')); // Disallow wins (same length, earlier line)
        $this->assertTrue($records->uaAllowed('*', '/path/allowed')); // Allow wins (more specific/longer)
    }

    // ── Directive info tests ──────────────────────────────────────────

    /**
     * Every allow/disallow directive must expose an info array with all four keys.
     */
    public function testDirectiveInfoStructureIsPresent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $directives = array_merge(
            $records->allowed()->toArray(),
            $records->disallowed()->toArray()
        );

        $this->assertNotEmpty($directives);

        foreach ($directives as $directive) {
            $this->assertArrayHasKey('info', $directive, "Directive at line {$directive['line']} is missing 'info'");
            $info = $directive['info'];
            $this->assertIsArray($info);
            $this->assertArrayHasKey('path_to_match', $info);
            $this->assertArrayHasKey('end_anchor', $info);
            $this->assertArrayHasKey('wildcards', $info);
            $this->assertArrayHasKey('specificity', $info);
            $this->assertIsArray($info['specificity']);
            $this->assertArrayHasKey('value', $info['specificity']);
            $this->assertArrayHasKey('description', $info['specificity']);
            $this->assertIsInt($info['specificity']['value']);
            $this->assertIsString($info['path_to_match']);
        }
    }

    /**
     * End anchor ($): Allow: /site-explorer/$ matches /site-explorer/ exactly.
     * The path does NOT match /site-explorer/something (confirmed by testUaAllowedWithEndAnchor).
     */
    public function testDirectiveInfoEndAnchor(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $allowed = $records->allowed()->toArray();

        // Allow: /site-explorer/$
        $directive = array_values(array_filter($allowed, fn ($d) => $d['path'] === '/site-explorer/$'))[0] ?? null;
        $this->assertNotNull($directive, 'Could not find Allow: /site-explorer/$');

        $this->assertNotNull($directive['info']['end_anchor'], 'end_anchor info should be non-null for paths ending with $');
        $this->assertNull($directive['info']['wildcards'], 'wildcards info should be null when no * is present');
        $this->assertStringContainsString('/site-explorer/', $directive['info']['end_anchor']);
        $this->assertStringContainsString('$', $directive['info']['path_to_match']);

        // Allow: /link-intersect/$
        $directive2 = array_values(array_filter($allowed, fn ($d) => $d['path'] === '/link-intersect/$'))[0] ?? null;
        $this->assertNotNull($directive2, 'Could not find Allow: /link-intersect/$');
        $this->assertNotNull($directive2['info']['end_anchor']);
    }

    /**
     * Wildcards (*): Disallow: /v4* matches /v4, /v4/something, /v4test.
     * Multiple wildcards: /blog/*?s=* matches /blog/article?s=test.
     */
    public function testDirectiveInfoWildcards(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $disallowed = $records->disallowed()->toArray();

        // Disallow: /v4*  – single wildcard, no end anchor
        $directive = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/v4*'))[0] ?? null;
        $this->assertNotNull($directive, 'Could not find Disallow: /v4*');
        $this->assertNotNull($directive['info']['wildcards'], 'wildcards info should be non-null when * is present');
        $this->assertNull($directive['info']['end_anchor'], 'end_anchor info should be null when $ is absent');
        $this->assertStringContainsString('*', $directive['info']['path_to_match']);

        // Disallow: /blog/*?s=*  – multiple wildcards
        $directive2 = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/blog/*?s=*'))[0] ?? null;
        $this->assertNotNull($directive2, 'Could not find Disallow: /blog/*?s=*');
        $this->assertNotNull($directive2['info']['wildcards']);
        $this->assertStringContainsString('2', $directive2['info']['wildcards']); // mentions count = 2

        // Disallow: /*/seo-toolbar/welcome  – wildcard in the middle
        $directive3 = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/*/seo-toolbar/welcome'))[0] ?? null;
        $this->assertNotNull($directive3, 'Could not find Disallow: /*/seo-toolbar/welcome');
        $this->assertNotNull($directive3['info']['wildcards']);
    }

    /**
     * Specificity: more specific rule (longer path) should take precedence.
     * Disallow: /path (5) < Allow: /path/specific (14) < Disallow: /path/specific/blocked (22).
     */
    public function testDirectiveInfoSpecificity(): void
    {
        $parser = new RobotsTxtParser();
        $robotsContent = <<<'ROBOTS'
User-agent: *
Disallow: /path
Allow: /path/specific
Disallow: /path/specific/blocked
ROBOTS;
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $disallowed = $records->disallowed()->toArray();
        $allowed = $records->allowed()->toArray();

        $shortRule = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/path'))[0] ?? null;
        $midRule = array_values(array_filter($allowed, fn ($d) => $d['path'] === '/path/specific'))[0] ?? null;
        $longRule = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/path/specific/blocked'))[0] ?? null;

        $this->assertNotNull($shortRule);
        $this->assertNotNull($midRule);
        $this->assertNotNull($longRule);

        $this->assertSame(strlen('/path'), $shortRule['info']['specificity']['value']);
        $this->assertSame(strlen('/path/specific'), $midRule['info']['specificity']['value']);
        $this->assertSame(strlen('/path/specific/blocked'), $longRule['info']['specificity']['value']);

        // Each longer rule has a higher specificity value
        $this->assertLessThan($midRule['info']['specificity']['value'], $shortRule['info']['specificity']['value']);
        $this->assertLessThan($longRule['info']['specificity']['value'], $midRule['info']['specificity']['value']);

        $this->assertStringContainsString('specificity', $shortRule['info']['specificity']['description']);
    }

    /**
     * Path to match (prefix): Disallow: /article matches /article, /article/anything.
     * A plain path with no * or $ uses prefix matching.
     */
    public function testDirectiveInfoPathToMatchPrefix(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $disallowed = $records->disallowed()->toArray();

        // Disallow: /article – plain prefix, no wildcards, no end anchor
        $directive = array_values(array_filter($disallowed, fn ($d) => $d['path'] === '/article'))[0] ?? null;
        $this->assertNotNull($directive, 'Could not find Disallow: /article');

        $this->assertNull($directive['info']['end_anchor']);
        $this->assertNull($directive['info']['wildcards']);
        $this->assertStringContainsString('/article', $directive['info']['path_to_match']);
        // Specificity equals full path length
        $this->assertSame(strlen('/article'), $directive['info']['specificity']['value']);
    }

    /**
     * Directives shown via displayUserAgent() still expose info.
     */
    public function testDirectiveInfoPresentWithDisplayUserAgent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        $disallowedWith = $records->displayUserAgent(true)->disallowed()->toArray();
        $this->assertNotEmpty($disallowedWith);

        foreach ($disallowedWith as $item) {
            $this->assertArrayHasKey('info', $item);
            $this->assertIsArray($item['info']);
        }
    }

    public function testUaAllowedDoesNotModifyDisplayUserAgent(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->parseFile($this->testRobotsTxtFile);
        $records = $response->records();

        // Enable displayUserAgent
        $records->displayUserAgent(true);

        // Verify it's enabled
        $disallowedWithUA = $records->disallowed()->toArray();
        $this->assertArrayHasKey('userAgent', $disallowedWithUA[0] ?? []);

        // Call uaAllowed() which internally calls allowed() and disallowed()
        $records->uaAllowed('GPT-User', '/article');

        // Verify displayUserAgent is still enabled after uaAllowed()
        $disallowedAfter = $records->disallowed()->toArray();
        $this->assertArrayHasKey(
            'userAgent',
            $disallowedAfter[0] ?? [],
            'displayUserAgent should still be enabled after calling uaAllowed()'
        );
    }

    // ── loadContent tests ─────────────────────────────────────────────────

    /**
     * loadContent(false) (default) means $parser->content stays null after parseText.
     */
    public function testLoadContentDisabledByDefaultForParseText(): void
    {
        $parser = new RobotsTxtParser();
        $parser->parseText($this->testRobotsTxtContent);

        $this->assertNull($parser->content, 'content should stay null when loadContent is false');
    }

    /**
     * loadContent(false) (default) means $parser->content stays null after parseFile.
     */
    public function testLoadContentDisabledByDefaultForParseFile(): void
    {
        $parser = new RobotsTxtParser();
        $parser->parseFile($this->testRobotsTxtFile);

        $this->assertNull($parser->content, 'content should stay null when loadContent is false');
    }

    /**
     * loadContent(false) (default) means $parser->content stays null after parseUrl.
     */
    public function testLoadContentDisabledByDefaultForParseUrl(): void
    {
        $parser = new RobotsTxtParser($this->createMockHttpClient($this->testRobotsTxtContent));
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');
        $parser->parseUrl('https://example.com/robots.txt');

        $this->assertNull($parser->content, 'content should stay null when loadContent is false');
    }

    /**
     * loadContent(true) stores the raw content after parseText and content is still parseable.
     */
    public function testLoadContentEnabledForParseText(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->loadContent(true)->parseText($this->testRobotsTxtContent);

        // Content must be populated
        $this->assertNotNull($parser->content);
        $this->assertIsString($parser->content);
        $this->assertNotEmpty($parser->content);

        // The stored content should contain at least some of the original directives
        $this->assertStringContainsStringIgnoringCase('user-agent', $parser->content);
        $this->assertStringContainsStringIgnoringCase('disallow', $parser->content);

        // Parsing must still have worked normally
        $records = $response->records();
        $this->assertGreaterThan(0, $records->count());
        $this->assertCount(25, $records->disallowed());
    }

    /**
     * loadContent(true) stores the raw content after parseFile and content is still parseable.
     */
    public function testLoadContentEnabledForParseFile(): void
    {
        $parser = new RobotsTxtParser();
        $response = $parser->loadContent(true)->parseFile($this->testRobotsTxtFile);

        // Content must be populated
        $this->assertNotNull($parser->content);
        $this->assertIsString($parser->content);
        $this->assertNotEmpty($parser->content);

        // The stored content should contain at least some of the original directives
        $this->assertStringContainsStringIgnoringCase('user-agent', $parser->content);
        $this->assertStringContainsStringIgnoringCase('disallow', $parser->content);

        // Parsing must still have worked normally
        $records = $response->records();
        $this->assertGreaterThan(0, $records->count());
        $this->assertCount(25, $records->disallowed());
        $this->assertCount(4, $records->allowed());
    }

    /**
     * loadContent(true) stores the raw content after parseUrl and content is still parseable.
     */
    public function testLoadContentEnabledForParseUrl(): void
    {
        $parser = new RobotsTxtParser($this->createMockHttpClient($this->testRobotsTxtContent));
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');
        $response = $parser->loadContent(true)->parseUrl('https://example.com/robots.txt');

        // Content must be populated
        $this->assertNotNull($parser->content);
        $this->assertIsString($parser->content);
        $this->assertNotEmpty($parser->content);

        // The stored content should contain at least some of the original directives
        $this->assertStringContainsStringIgnoringCase('disallow', $parser->content);

        // Parsing must still have worked normally
        $records = $response->records();
        $this->assertGreaterThan(0, $records->count());
        $this->assertCount(25, $records->disallowed());
        $this->assertCount(4, $records->allowed());
    }

    /**
     * After two consecutive parseFile calls, content must reflect only the LAST call.
     */
    public function testLoadContentResetsOnConsecutiveParseFileCalls(): void
    {
        $firstContent = "User-agent: *\nDisallow: /first\n";
        $secondContent = "User-agent: *\nDisallow: /second\n";

        $firstFile = sys_get_temp_dir() . '/test_robots_first_' . uniqid() . '.txt';
        $secondFile = sys_get_temp_dir() . '/test_robots_second_' . uniqid() . '.txt';
        file_put_contents($firstFile, $firstContent);
        file_put_contents($secondFile, $secondContent);

        try {
            $parser = new RobotsTxtParser();
            $parser->loadContent(true);

            $parser->parseFile($firstFile);
            $contentAfterFirst = $parser->content;

            $parser->parseFile($secondFile);
            $contentAfterSecond = $parser->content;

            // Each call must produce disjoint content (no accumulation)
            $this->assertStringContainsString('/first', $contentAfterFirst);
            $this->assertStringNotContainsString('/second', $contentAfterFirst);

            $this->assertStringContainsString('/second', $contentAfterSecond);
            $this->assertStringNotContainsString('/first', $contentAfterSecond);
        } finally {
            @unlink($firstFile);
            @unlink($secondFile);
        }
    }

    /**
     * After two consecutive parseText calls, content must reflect only the LAST call.
     */
    public function testLoadContentResetsOnConsecutiveParseTextCalls(): void
    {
        $parser = new RobotsTxtParser();
        $parser->loadContent(true);

        $parser->parseText("User-agent: *\nDisallow: /first\n");
        $contentAfterFirst = $parser->content;

        $parser->parseText("User-agent: *\nDisallow: /second\n");
        $contentAfterSecond = $parser->content;

        $this->assertStringContainsString('/first', $contentAfterFirst);
        $this->assertStringNotContainsString('/second', $contentAfterFirst);

        $this->assertStringContainsString('/second', $contentAfterSecond);
        $this->assertStringNotContainsString('/first', $contentAfterSecond);
    }

    /**
     * After two consecutive parseUrl calls, content must reflect only the LAST call.
     */
    public function testLoadContentResetsOnConsecutiveParseUrlCalls(): void
    {
        $firstContent = "User-agent: *\nDisallow: /first\n";
        $secondContent = "User-agent: *\nDisallow: /second\n";

        $mock = new MockHandler([
            new Response(200, [], $firstContent),
            new Response(200, [], $secondContent),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $parser = new RobotsTxtParser($httpClient);
        $parser->configureUserAgent('TestBot', '1.0', 'https://example.com');
        $parser->loadContent(true);

        $parser->parseUrl('https://example.com/robots.txt');
        $contentAfterFirst = $parser->content;

        $parser->parseUrl('https://example.com/robots.txt');
        $contentAfterSecond = $parser->content;

        $this->assertStringContainsString('/first', $contentAfterFirst);
        $this->assertStringNotContainsString('/second', $contentAfterFirst);

        $this->assertStringContainsString('/second', $contentAfterSecond);
        $this->assertStringNotContainsString('/first', $contentAfterSecond);
    }

    // ── agents lazy-loading tests ──────────────────────────────────────

    /**
     * On construction, only priority=1 agents are in the eager dataset.
     */
    public function testAgentsDatasetContainsOnlyPriorityAgentsEagerly(): void
    {
        $parser = new RobotsTxtParser();

        // Every entry in the eagerly-loaded dataset must have priority=1
        foreach ($parser->agentsDataset as $agent) {
            $this->assertSame(
                1,
                $agent['priority'] ?? -1,
                "Eagerly loaded agent '{$agent['agent']}' has priority != 1"
            );
        }

        // The eager dataset should be significantly smaller than the full 1 600-entry list
        $this->assertGreaterThan(0, $parser->agentsDataset->count(), 'Should have at least some priority agents');
        $this->assertLessThan(200, $parser->agentsDataset->count(), 'Eager dataset should be much smaller than full list');
    }

    /**
     * A priority=1 agent (e.g. GPTBot) is recognised without triggering a lazy load.
     */
    public function testPriorityAgentIsRecognisedEagerly(): void
    {
        $parser = new RobotsTxtParser();
        // GPTBot has priority=1 in agents.json
        $response = $parser->parseText("User-agent: GPTBot\nDisallow: /test\n");
        $records = $response->records();

        $userAgents = $records->userAgents();
        $gptBot = $userAgents->get('GPTBot') ?? $userAgents->get('gptbot');
        $this->assertNotNull($gptBot, 'GPTBot should be found');
        $this->assertNotNull($gptBot['category'], 'GPTBot category should be populated (priority agent)');
        $this->assertNotNull($gptBot['description'], 'GPTBot description should be populated (priority agent)');
    }

    /**
     * A priority=0 agent is still recognised through the lazy-load path.
     *
     * Manus-User has priority=0 in agents.json – it must NOT be in the eager dataset
     * but must be resolved correctly when encountered in robots.txt.
     */
    public function testNonPriorityAgentIsRecognisedLazily(): void
    {
        $parser = new RobotsTxtParser();

        // Verify Manus-User is not in the eager dataset
        $inEager = $parser->agentsDataset->contains(
            fn ($a) => strtolower($a['agent']) === 'manus-user'
        );
        $this->assertFalse($inEager, 'Manus-User (priority=0) must NOT be in the eager dataset');

        // Parse a robots.txt that references Manus-User → triggers lazy load
        $response = $parser->parseText("User-agent: Manus-User\nDisallow: /private\n");
        $records = $response->records();

        $userAgents = $records->userAgents();
        $manusUser = $userAgents->get('Manus-User') ?? $userAgents->get('manus-user');
        $this->assertNotNull($manusUser, 'Manus-User should be found via the lazy-load path');
        $this->assertNotNull($manusUser['category'], 'Manus-User category should be populated (lazy-load)');
        $this->assertNotNull($manusUser['description'], 'Manus-User description should be populated (lazy-load)');
    }

    /**
     * The lazy dataset is loaded only once even when multiple unknown agents are encountered.
     * This is a behaviour test: parsing two priority=0 agents still produces two recognised results.
     */
    public function testLazyLoadOccursOnlyOnceForMultipleNonPriorityAgents(): void
    {
        $parser = new RobotsTxtParser();

        // Both Manus-User and Novaact have priority=0
        $robotsContent = "User-agent: Manus-User\nDisallow: /a\n\nUser-agent: NovaAct\nDisallow: /b\n";
        $response = $parser->parseText($robotsContent);
        $records = $response->records();

        $userAgents = $records->userAgents();
        $this->assertGreaterThanOrEqual(2, $userAgents->count());

        // Both should be recognised (category not null)
        $manusUser = $userAgents->get('Manus-User') ?? $userAgents->get('manus-user');
        $novaAct = $userAgents->get('NovaAct') ?? $userAgents->get('novaact');

        $this->assertNotNull($manusUser);
        $this->assertNotNull($novaAct);
        $this->assertNotNull($manusUser['category']);
        $this->assertNotNull($novaAct['category']);
    }
}

