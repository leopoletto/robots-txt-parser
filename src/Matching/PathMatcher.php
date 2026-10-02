<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Matching;

/**
 * Matches a URL path against a robots.txt path pattern.
 *
 * Supports the two operators defined by RFC 9309 §2.2.3: `*` for any run of
 * characters and `$` for an end-of-URL anchor. Everything else in the pattern
 * is literal.
 */
final class PathMatcher
{
    /** @var array<string, string> Compiled regexes, keyed by pattern. */
    private static array $compiled = [];

    public static function matches(string $pattern, string $path): bool
    {
        // An empty Allow/Disallow value matches every path.
        if ($pattern === '') {
            return true;
        }

        return preg_match(self::compile($pattern), $path) === 1;
    }

    /**
     * Translate a robots.txt pattern into a PCRE. Compilation is memoised
     * because a single allowance check runs every rule in a group against the
     * same path, and callers routinely check many paths against one document.
     */
    private static function compile(string $pattern): string
    {
        if (isset(self::$compiled[$pattern])) {
            return self::$compiled[$pattern];
        }

        // Only a single trailing "$" is an anchor; any earlier one is literal.
        $endAnchor = str_ends_with($pattern, '$');
        $literal = $endAnchor ? substr($pattern, 0, -1) : $pattern;

        // preg_quote escapes "*" too, so unescape just that one operator.
        $regex = str_replace('\*', '.*', preg_quote($literal, '#'));

        return self::$compiled[$pattern] = '#^' . $regex . ($endAnchor ? '$' : '') . '#';
    }

    /**
     * Normalise a URL or path into the path-plus-query form rules match against.
     */
    public static function normalize(string $target): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1) {
            $path = parse_url($target, PHP_URL_PATH);
            $query = parse_url($target, PHP_URL_QUERY);
            $target = ($path === null || $path === false ? '/' : $path)
                . ($query === null || $query === false ? '' : '?' . $query);
        }

        if ($target === '') {
            return '/';
        }

        return $target[0] === '/' ? $target : '/' . $target;
    }
}
