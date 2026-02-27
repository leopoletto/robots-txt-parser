<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class RobotsDirective implements RobotsLineInterface
{
    public readonly array $info;

    public function __construct(
        public readonly int $line,
        public readonly UserAgent $userAgent,
        public readonly string $directive,
        public readonly string $path
    ) {
        $this->info = $this->computeInfo();
    }

    /**
     * Build the info array explaining how this directive's path matching works.
     *
     * Applies only to "allow" and "disallow" directives. Covers four key concepts:
     *
     * - **Path to Match**: Paths use prefix matching by default. A directive like
     *   `Disallow: /article` blocks `/article`, `/article/`, and `/article/123/comments`.
     *   Paths must start with `/`; an empty path is treated as matching all URLs.
     *
     * - **End Anchor** (`$`): When a path ends with `$`, the URL must end exactly at
     *   the preceding characters. `Allow: /site-explorer/$` allows `/site-explorer/`
     *   but does NOT allow `/site-explorer/something`. Without `$`, prefix matching
     *   would also match `/site-explorer/something`.
     *
     * - **Wildcards** (`*`): The `*` character matches any sequence of characters
     *   (including none). `Disallow: /v4*` matches `/v4`, `/v4/page`, and `/v4test`.
     *   Multiple wildcards are supported: `Disallow: /blog/*?s=*` matches
     *   `/blog/article?s=test` and `/blog/anything?s=anything`.
     *
     * - **Specificity** (longer path takes precedence): When multiple rules match a URL,
     *   the rule with the longer path string wins. `Allow: /path/specific` (14 chars)
     *   takes precedence over `Disallow: /path` (5 chars). If two rules share the same
     *   path length, the one appearing first in the file wins.
     */
    private function computeInfo(): array
    {
        if (! in_array($this->directive, ['allow', 'disallow'])) {
            return [];
        }

        $hasEndAnchor = str_ends_with($this->path, '$');
        $cleanPath = $hasEndAnchor ? substr($this->path, 0, -1) : $this->path;
        $hasWildcard = str_contains($cleanPath, '*');
        $specificity = strlen($this->path);

        return [
            'path_to_match' => $this->describePathMatch($cleanPath, $hasEndAnchor, $hasWildcard),
            'end_anchor' => $hasEndAnchor ? $this->describeEndAnchor($cleanPath) : null,
            'wildcards' => $hasWildcard ? $this->describeWildcards($cleanPath) : null,
            'specificity' => [
                'value' => $specificity,
                'description' => 'When multiple rules match a URL, the more specific rule (longer path) takes '
                    . "precedence. This rule has specificity {$specificity}. "
                    . 'If two rules have equal specificity, the one appearing first in the file wins.',
            ],
        ];
    }

    private function describePathMatch(string $cleanPath, bool $hasEndAnchor, bool $hasWildcard): string
    {
        if ($cleanPath === '') {
            return 'An empty path matches all URLs.';
        }

        if (! $hasWildcard && ! $hasEndAnchor) {
            return "Prefix match: any URL whose path starts with \"{$cleanPath}\" is matched. "
                . "For example, \"{$cleanPath}\", \"{$cleanPath}/subpage\", and \"{$cleanPath}extra\" are all matched.";
        }

        if (! $hasWildcard && $hasEndAnchor) {
            return "Exact match (with end anchor \$): only URLs ending exactly at \"{$cleanPath}\" match. "
                . "For example, \"{$cleanPath}\" is matched, but \"{$cleanPath}extra\" is NOT.";
        }

        if ($hasWildcard && ! $hasEndAnchor) {
            $example = str_replace('*', 'anything', $cleanPath);

            return "Wildcard match: the \"*\" in \"{$cleanPath}\" matches any sequence of characters. "
                . "For example, \"{$example}\" would match.";
        }

        return "Wildcard match with end anchor: \"*\" in \"{$cleanPath}\" matches any sequence of characters, "
            . 'and the URL must end at the point indicated by "$".';
    }

    private function describeEndAnchor(string $cleanPath): string
    {
        return "The \"\$\" at the end of the pattern anchors the match: only URLs ending exactly at "
            . "\"{$cleanPath}\" are matched. For example, \"{$cleanPath}extra\" would NOT match. "
            . "Without \"\$\", \"{$cleanPath}\" would also match \"{$cleanPath}extra\" (prefix match).";
    }

    private function describeWildcards(string $cleanPath): string
    {
        $count = substr_count($cleanPath, '*');
        $wildcardWord = $count === 1 ? 'wildcard' : 'wildcards';

        return "This pattern contains {$count} {$wildcardWord}. Each \"*\" matches any sequence of characters "
            . '(including none). For example, "/v4*" matches "/v4", "/v4/page", and "/v4test". '
            . 'Multiple wildcards are also supported: "/blog/*?s=*" matches "/blog/article?s=test".';
    }

    public function line(): int
    {
        return $this->line;
    }

    /**
     * Check if line is a directive
     */
    public static function isDirective(string $line): bool
    {
        $trimmed = strtolower(trim($line));

        return str_starts_with($trimmed, 'allow:')
            || str_starts_with($trimmed, 'disallow:')
            || str_starts_with($trimmed, 'crawl-delay:');
    }

    /**
     * Parse directive line
     */
    public static function parse(string $line, int $lineNumber, UserAgent $currentUserAgent): ?static
    {
        $trimmed = trim($line);
        $lowerTrimmed = strtolower($trimmed);

        if (str_starts_with($lowerTrimmed, 'allow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';

            return new static($lineNumber, $currentUserAgent, 'allow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'disallow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';

            return new static($lineNumber, $currentUserAgent, 'disallow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'crawl-delay:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';

            return new static($lineNumber, $currentUserAgent, 'crawl-delay', $path);
        }

        return null;
    }
}
