<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Matching\PathMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PathMatcherTest extends TestCase
{
    #[Test]
    #[DataProvider('patterns')]
    public function it_matches_expected_paths(string $pattern, string $path, bool $expected): void
    {
        $this->assertSame($expected, PathMatcher::matches($pattern, $path), "{$pattern} vs {$path}");
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function patterns(): iterable
    {
        yield 'empty pattern matches everything' => ['', '/anything', true];

        yield 'prefix match' => ['/article', '/article', true];
        yield 'prefix match with subpath' => ['/article', '/article/123/comments', true];
        yield 'prefix match without separator' => ['/article', '/articles', true];
        yield 'prefix does not match different branch' => ['/article', '/blog', false];

        yield 'end anchor matches exactly' => ['/site-explorer/$', '/site-explorer/', true];
        yield 'end anchor rejects a suffix' => ['/site-explorer/$', '/site-explorer/x', false];

        yield 'wildcard matches nothing' => ['/v4*', '/v4', true];
        yield 'wildcard matches a subpath' => ['/v4*', '/v4/page', true];
        yield 'wildcard matches an extension' => ['/v4*', '/v4test', true];
        yield 'multiple wildcards' => ['/blog/*?s=*', '/blog/article?s=test', true];
        yield 'wildcard with end anchor' => ['/*.pdf$', '/docs/report.pdf', true];
        yield 'wildcard with end anchor rejects suffix' => ['/*.pdf$', '/docs/report.pdf.html', false];

        // Regression: a "$" that is not the final character is a literal.
        yield 'inner dollar is literal' => ['/price$value', '/price$value', true];
        yield 'inner dollar does not anchor' => ['/price$value', '/price$values', true];

        // Regression: rtrim() would have stripped both anchors and mis-parsed.
        yield 'double dollar strips only one' => ['/path$$', '/path$', true];

        yield 'regex metacharacters are literal' => ['/a.b+c', '/a.b+c', true];
        yield 'dot is not a wildcard' => ['/a.b', '/axb', false];
    }

    #[Test]
    #[DataProvider('normalizations')]
    public function it_normalizes_targets_to_paths(string $input, string $expected): void
    {
        $this->assertSame($expected, PathMatcher::normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizations(): iterable
    {
        yield 'bare path' => ['/admin', '/admin'];
        yield 'path without slash' => ['admin', '/admin'];
        yield 'empty becomes root' => ['', '/'];
        yield 'full url keeps path' => ['https://example.com/a/b', '/a/b'];
        yield 'full url keeps query' => ['https://example.com/search?q=hi', '/search?q=hi'];
        yield 'url root' => ['https://example.com', '/'];
    }
}
