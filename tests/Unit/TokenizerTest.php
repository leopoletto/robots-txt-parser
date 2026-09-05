<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Parsing\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase
{
    #[Test]
    public function it_splits_a_field_from_its_value(): void
    {
        $token = Tokenizer::tokenize(1, 'Disallow: /admin');

        $this->assertSame('disallow', $token->field);
        $this->assertSame('/admin', $token->value);
        $this->assertNull($token->comment);
    }

    #[Test]
    public function it_lowercases_the_field_but_preserves_the_value_casing(): void
    {
        $token = Tokenizer::tokenize(1, 'USER-AGENT: ChatGPT-User');

        $this->assertSame('user-agent', $token->field);
        $this->assertSame('ChatGPT-User', $token->value);
    }

    #[Test]
    public function it_strips_a_trailing_comment_from_a_directive_line(): void
    {
        $token = Tokenizer::tokenize(4, 'Disallow: /admin   # keep bots out');

        $this->assertSame('disallow', $token->field);
        $this->assertSame('/admin', $token->value);
        $this->assertSame('keep bots out', $token->comment);
    }

    #[Test]
    public function it_treats_a_whole_line_comment_as_blank(): void
    {
        $token = Tokenizer::tokenize(2, '# just a note');

        $this->assertTrue($token->isBlank());
        $this->assertSame('just a note', $token->comment);
    }

    #[Test]
    public function it_strips_a_byte_order_mark_from_the_first_line(): void
    {
        $token = Tokenizer::tokenize(1, "\u{FEFF}User-agent: *");

        $this->assertSame('user-agent', $token->field);
        $this->assertSame('*', $token->value);
    }

    #[Test]
    public function it_keeps_a_value_containing_a_colon_intact(): void
    {
        $token = Tokenizer::tokenize(1, 'Sitemap: https://example.com/sitemap.xml');

        $this->assertSame('sitemap', $token->field);
        $this->assertSame('https://example.com/sitemap.xml', $token->value);
    }

    #[Test]
    public function it_reports_a_line_with_no_separator(): void
    {
        $token = Tokenizer::tokenize(1, 'Disallow /admin');

        $this->assertNull($token->field);
        $this->assertSame('Disallow /admin', $token->value);
    }

    #[Test]
    #[DataProvider('blankLines')]
    public function it_recognises_blank_lines(string $line): void
    {
        $this->assertTrue(Tokenizer::tokenize(1, $line)->isBlank());
    }

    /** @return iterable<string, array{string}> */
    public static function blankLines(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
    }
}
