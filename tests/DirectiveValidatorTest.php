<?php

namespace Leopoletto\RobotsTxtParser\Tests;

use Leopoletto\RobotsTxtParser\Validators\DirectiveValidator;
use PHPUnit\Framework\TestCase;

class DirectiveValidatorTest extends TestCase
{
    private DirectiveValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DirectiveValidator();
    }

    // ── validate() tests ──────────────────────────────────────────────

    public function testSingleSimpleDirectiveStructure(): void
    {
        $result = $this->validator->validate('index');

        $this->assertSame('index', $result['raw']);
        $this->assertSame('meta', $result['source']);
        $this->assertNull($result['user_agent']);
        $this->assertIsArray($result['directives']);
        $this->assertIsBool($result['valid']);
        $this->assertIsArray($result['issues']);
        $this->assertIsArray($result['conflicts']);
        $this->assertIsArray($result['redundancies']);
        $this->assertIsBool($result['is_full_spec']);
    }

    public function testAllSimpleDirectivesAreValid(): void
    {
        $simpleDirectives = [
            'index', 'noindex',
            'follow', 'nofollow',
            'nosnippet',
            'noimageindex',
            'noarchive', 'archive',
            'notranslate', 'translate',
            'all', 'none',
            'nositelinkssearchbox',
            'noodp', 'noydir',
        ];

        foreach ($simpleDirectives as $directive) {
            $result = $this->validator->validate($directive);
            $this->assertCount(1, $result['directives']);
            $this->assertTrue(
                $result['directives'][0]['valid'],
                "Directive '$directive' should be valid"
            );
        }
    }

    public function testMultipleSimpleDirectivesInOneString(): void
    {
        $result = $this->validator->validate('index, follow');

        $this->assertCount(2, $result['directives']);
        $this->assertSame('index', $result['directives'][0]['name']);
        $this->assertSame('follow', $result['directives'][1]['name']);
    }

    public function testInvalidDirective(): void
    {
        $result = $this->validator->validate('foobar');

        $this->assertFalse($result['directives'][0]['valid']);
        $this->assertNotEmpty($result['issues']);
        $this->assertSame('invalid_directive', $result['issues'][0]['type']);
        $this->assertSame('high', $result['issues'][0]['severity']);
    }

    public function testCaseInsensitiveParsing(): void
    {
        $result = $this->validator->validate('INDEX, Follow');

        $this->assertCount(2, $result['directives']);
        $this->assertSame('index', $result['directives'][0]['name']);
        $this->assertTrue($result['directives'][0]['valid']);
        $this->assertSame('follow', $result['directives'][1]['name']);
        $this->assertTrue($result['directives'][1]['valid']);
    }

    public function testWhitespaceHandling(): void
    {
        $result = $this->validator->validate('  index ,  follow  ');

        $this->assertCount(2, $result['directives']);
        $this->assertTrue($result['directives'][0]['valid']);
        $this->assertTrue($result['directives'][1]['valid']);
    }

    public function testParametricMaxSnippetValid(): void
    {
        foreach (['150', '-1', '0'] as $value) {
            $result = $this->validator->validate("max-snippet:$value");
            $this->assertTrue(
                $result['directives'][0]['valid'],
                "max-snippet:$value should be valid"
            );
            $this->assertSame('parametric', $result['directives'][0]['type']);
            $this->assertSame($value, $result['directives'][0]['value']);
        }
    }

    public function testParametricMaxSnippetInvalid(): void
    {
        $result = $this->validator->validate('max-snippet:abc');

        $this->assertFalse($result['directives'][0]['valid']);
    }

    public function testParametricMaxImagePreviewValid(): void
    {
        foreach (['none', 'standard', 'large'] as $value) {
            $result = $this->validator->validate("max-image-preview:$value");
            $this->assertTrue(
                $result['directives'][0]['valid'],
                "max-image-preview:$value should be valid"
            );
        }
    }

    public function testParametricMaxImagePreviewInvalid(): void
    {
        $result = $this->validator->validate('max-image-preview:medium');

        $this->assertFalse($result['directives'][0]['valid']);
    }

    public function testParametricMaxVideoPreviewValid(): void
    {
        foreach (['-1', '30'] as $value) {
            $result = $this->validator->validate("max-video-preview:$value");
            $this->assertTrue(
                $result['directives'][0]['valid'],
                "max-video-preview:$value should be valid"
            );
        }
    }

    public function testConflictDetectionIndexNoindex(): void
    {
        $result = $this->validator->validate('index, noindex');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('noindex', $result['conflicts'][0]['resolution']);
    }

    public function testConflictDetectionFollowNofollow(): void
    {
        $result = $this->validator->validate('follow, nofollow');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('nofollow', $result['conflicts'][0]['resolution']);
    }

    public function testConflictDetectionNosnippetMaxSnippet(): void
    {
        $result = $this->validator->validate('nosnippet, max-snippet:150');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('nosnippet', $result['conflicts'][0]['resolution']);
    }

    public function testConflictDetectionAllNone(): void
    {
        $result = $this->validator->validate('all, none');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['conflicts']);
        $this->assertStringContainsString('none', $result['conflicts'][0]['resolution']);
    }

    public function testNoConflictsWhenNonePresent(): void
    {
        $result = $this->validator->validate('index, follow');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['conflicts']);
    }

    public function testRedundancyShorthandAllIndex(): void
    {
        $result = $this->validator->validate('all, index');

        $this->assertNotEmpty($result['redundancies']);
        $this->assertSame('shorthand', $result['redundancies'][0]['type']);
        $this->assertStringContainsString('index', $result['redundancies'][0]['message']);
    }

    public function testRedundancyShorthandAllFollow(): void
    {
        $result = $this->validator->validate('all, follow');

        $this->assertNotEmpty($result['redundancies']);
        $this->assertSame('shorthand', $result['redundancies'][0]['type']);
        $this->assertStringContainsString('follow', $result['redundancies'][0]['message']);
    }

    public function testRedundancyShorthandNoneNoindex(): void
    {
        $result = $this->validator->validate('none, noindex');

        $this->assertNotEmpty($result['redundancies']);
        $this->assertSame('shorthand', $result['redundancies'][0]['type']);
        $this->assertStringContainsString('noindex', $result['redundancies'][0]['message']);
    }

    public function testRedundancyDuplicateNosnippetMaxSnippet(): void
    {
        $result = $this->validator->validate('nosnippet, max-snippet:150');

        $redundancies = array_filter(
            $result['redundancies'],
            fn ($r) => $r['type'] === 'duplicate'
        );
        $this->assertNotEmpty($redundancies);
    }

    public function testDeprecatedNoodp(): void
    {
        $result = $this->validator->validate('noodp');

        $deprecatedIssues = array_filter(
            $result['issues'],
            fn ($i) => $i['type'] === 'deprecated'
        );
        $this->assertNotEmpty($deprecatedIssues);
        $deprecatedIssue = array_values($deprecatedIssues)[0];
        $this->assertSame('low', $deprecatedIssue['severity']);
        $this->assertSame('noodp', $deprecatedIssue['directive']);
    }

    public function testDeprecatedNoydir(): void
    {
        $result = $this->validator->validate('noydir');

        $deprecatedIssues = array_filter(
            $result['issues'],
            fn ($i) => $i['type'] === 'deprecated'
        );
        $this->assertNotEmpty($deprecatedIssues);
        $this->assertSame('noydir', array_values($deprecatedIssues)[0]['directive']);
    }

    public function testFullSpecDetectionTrue(): void
    {
        $result = $this->validator->validate('index, max-snippet:150, max-image-preview:large, max-video-preview:-1');

        $this->assertTrue($result['is_full_spec']);
    }

    public function testFullSpecDetectionFalseMissingDirective(): void
    {
        $result = $this->validator->validate('index, max-snippet:150, max-image-preview:large');

        $this->assertFalse($result['is_full_spec']);
    }

    public function testFullSpecDetectionWithAll(): void
    {
        $result = $this->validator->validate('all, max-snippet:150, max-image-preview:large, max-video-preview:-1');

        $this->assertTrue($result['is_full_spec']);
    }

    public function testSourcePassthrough(): void
    {
        $result = $this->validator->validate('index', 'header');

        $this->assertSame('header', $result['source']);
    }

    public function testUserAgentPassthrough(): void
    {
        $result = $this->validator->validate('index', 'header', 'googlebot');

        $this->assertSame('googlebot', $result['user_agent']);
    }

    public function testDeduplication(): void
    {
        $result = $this->validator->validate('index, index, follow, follow');

        $this->assertCount(2, $result['directives']);
        $this->assertSame('index', $result['directives'][0]['name']);
        $this->assertSame('follow', $result['directives'][1]['name']);
    }

    // ── validateUserAgent() tests ─────────────────────────────────────

    public function testValidateKnownUserAgents(): void
    {
        $knownAgents = [
            'googlebot', 'bingbot', 'slurp', 'duckduckbot',
            'baiduspider', 'yandex', '*',
            'googlebot-news', 'googlebot-image', 'googlebot-video',
        ];

        foreach ($knownAgents as $agent) {
            $result = $this->validator->validateUserAgent($agent);
            $this->assertTrue(
                $result['valid'],
                "User agent '$agent' should be valid"
            );
            $this->assertEmpty($result['issues']);
        }
    }

    public function testValidateUnknownUserAgent(): void
    {
        $result = $this->validator->validateUserAgent('unknownbot');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['issues']);
        $this->assertSame('unknown_user_agent', $result['issues'][0]['type']);
    }
}
