<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Validation\DirectiveValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectiveValidatorTest extends TestCase
{
    private DirectiveValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DirectiveValidator();
    }

    #[Test]
    public function it_parses_a_simple_list(): void
    {
        $result = $this->validator->validate('index, follow');

        $this->assertSame(['index', 'follow'], $result->names());
        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->issues);
    }

    #[Test]
    public function it_parses_a_parametric_directive(): void
    {
        $result = $this->validator->validate('max-snippet:-1');

        $this->assertSame('max-snippet', $result->directives[0]->name);
        $this->assertSame('-1', $result->directives[0]->value);
        $this->assertTrue($result->directives[0]->valid);
    }

    #[Test]
    #[DataProvider('parametricValues')]
    public function it_validates_parametric_values(string $input, bool $valid): void
    {
        $this->assertSame($valid, $this->validator->validate($input)->directives[0]->valid);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function parametricValues(): iterable
    {
        yield 'max-snippet -1' => ['max-snippet:-1', true];
        yield 'max-snippet 0' => ['max-snippet:0', true];
        yield 'max-snippet 50' => ['max-snippet:50', true];
        yield 'max-snippet text' => ['max-snippet:lots', false];
        yield 'max-image-preview large' => ['max-image-preview:large', true];
        yield 'max-image-preview huge' => ['max-image-preview:huge', false];
        yield 'max-video-preview 30' => ['max-video-preview:30', true];
        yield 'unavailable_after date' => ['unavailable_after:2026-01-01', true];
    }

    #[Test]
    public function it_flags_an_unknown_directive(): void
    {
        $result = $this->validator->validate('nonsense');

        $this->assertFalse($result->directives[0]->valid);
        $this->assertSame('invalid_directive', $result->issues[0]->type);
        $this->assertSame(Severity::High, $result->issues[0]->severity);
    }

    #[Test]
    public function it_flags_deprecated_directives(): void
    {
        $result = $this->validator->validate('noodp');

        $types = array_map(static fn ($i): string => $i->type, $result->issues);
        $this->assertContains('deprecated', $types);
    }

    #[Test]
    public function it_detects_a_conflict(): void
    {
        $result = $this->validator->validate('index, noindex');

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->conflicts);
        $this->assertStringContainsString('noindex', (string) $result->conflicts[0]->note);
    }

    #[Test]
    public function it_detects_a_redundant_shorthand(): void
    {
        $result = $this->validator->validate('all, index');

        $this->assertCount(1, $result->redundancies);
        $this->assertSame('shorthand', $result->redundancies[0]->type);
    }

    #[Test]
    public function it_deduplicates_repeated_directives(): void
    {
        $result = $this->validator->validate('noindex, noindex, nofollow');

        $this->assertSame(['noindex', 'nofollow'], $result->names());
    }

    #[Test]
    public function it_recognises_a_full_specification(): void
    {
        $result = $this->validator->validate(
            'index, max-snippet:-1, max-image-preview:large, max-video-preview:-1'
        );

        $this->assertTrue($result->isFullSpec);
    }

    #[Test]
    public function it_handles_irregular_spacing_and_casing(): void
    {
        $result = $this->validator->validate('  NoIndex ,   NOFOLLOW  ');

        $this->assertSame(['noindex', 'nofollow'], $result->names());
    }

    #[Test]
    public function an_empty_value_yields_no_directives(): void
    {
        $result = $this->validator->validate('');

        $this->assertSame([], $result->directives);
        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function it_recognises_known_directive_names(): void
    {
        $this->assertTrue($this->validator->isKnownDirective('noindex'));
        $this->assertTrue($this->validator->isKnownDirective('max-snippet'));
        $this->assertFalse($this->validator->isKnownDirective('googlebot'));
    }
}
