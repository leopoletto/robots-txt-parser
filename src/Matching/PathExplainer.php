<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Matching;

/**
 * Describes, in plain language, how a robots.txt path pattern matches URLs.
 *
 * This is presentation, not parsing: it exists so an analysis UI can explain a
 * rule to a human. Directive calls it lazily, so a document with thousands of
 * rules pays nothing unless something actually reads the explanations.
 *
 * The four concepts it covers:
 *
 * - **Prefix matching** — the default. `Disallow: /article` blocks `/article`,
 *   `/article/`, and `/article/123/comments`.
 * - **End anchor (`$`)** — `Allow: /site-explorer/$` allows `/site-explorer/`
 *   but not `/site-explorer/something`.
 * - **Wildcards (`*`)** — match any run of characters, including none.
 *   `Disallow: /v4*` matches `/v4`, `/v4/page` and `/v4test`.
 * - **Specificity** — the longer pattern wins; at equal length the least
 *   restrictive rule wins, so `Allow` beats `Disallow`.
 */
final class PathExplainer
{
    public static function explain(string $pattern): PathExplanation
    {
        $endAnchor = str_ends_with($pattern, '$');
        $literal = $endAnchor ? substr($pattern, 0, -1) : $pattern;
        $wildcards = substr_count($literal, '*');
        $specificity = strlen($pattern);
        $matchesAll = $literal === '';

        return new PathExplanation(
            pattern: $pattern,
            literal: $literal,
            specificity: $specificity,
            wildcards: $wildcards,
            endAnchor: $endAnchor,
            matchesAll: $matchesAll,
            pathToMatch: self::describeMatch($literal, $endAnchor, $wildcards > 0),
            endAnchorNote: $endAnchor ? self::describeEndAnchor($literal) : null,
            wildcardNote: $wildcards > 0 ? self::describeWildcards($wildcards) : null,
            specificityNote: self::describeSpecificity($specificity),
        );
    }

    private static function describeMatch(string $literal, bool $endAnchor, bool $hasWildcard): string
    {
        if ($literal === '') {
            return 'An empty path matches all URLs.';
        }

        if ($hasWildcard && $endAnchor) {
            return "Wildcard match with end anchor: \"*\" in \"{$literal}\" matches any sequence of characters, "
                . 'and the URL must end at the point indicated by "$".';
        }

        if ($hasWildcard) {
            $example = str_replace('*', 'anything', $literal);

            return "Wildcard match: the \"*\" in \"{$literal}\" matches any sequence of characters. "
                . "For example, \"{$example}\" would match.";
        }

        if ($endAnchor) {
            return "Exact match (with end anchor \$): only URLs ending exactly at \"{$literal}\" match. "
                . "For example, \"{$literal}\" is matched, but \"{$literal}extra\" is NOT.";
        }

        return "Prefix match: any URL whose path starts with \"{$literal}\" is matched. "
            . "For example, \"{$literal}\", \"{$literal}/subpage\", and \"{$literal}extra\" are all matched.";
    }

    private static function describeEndAnchor(string $literal): string
    {
        return 'The "$" at the end of the pattern anchors the match: only URLs ending exactly at '
            . "\"{$literal}\" are matched. For example, \"{$literal}extra\" would NOT match. "
            . "Without \"\$\", \"{$literal}\" would also match \"{$literal}extra\" (prefix match).";
    }

    private static function describeWildcards(int $count): string
    {
        $word = $count === 1 ? 'wildcard' : 'wildcards';

        return "This pattern contains {$count} {$word}. Each \"*\" matches any sequence of characters "
            . '(including none). For example, "/v4*" matches "/v4", "/v4/page", and "/v4test". '
            . 'Multiple wildcards are also supported: "/blog/*?s=*" matches "/blog/article?s=test".';
    }

    private static function describeSpecificity(int $specificity): string
    {
        return 'When multiple rules match a URL, the more specific rule (longer path) takes precedence. '
            . "This rule has specificity {$specificity}. "
            . 'If two rules are equally specific, the least restrictive one wins — an Allow beats a Disallow.';
    }
}
