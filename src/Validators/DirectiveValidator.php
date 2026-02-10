<?php

namespace Leopoletto\RobotsTxtParser\Validators;

class DirectiveValidator
{
    // All simple directives (no parameters)
    private const SIMPLE_DIRECTIVES = [
        'index', 'noindex',
        'follow', 'nofollow',
        'nosnippet',
        'noimageindex',
        'noarchive', 'archive',
        'notranslate', 'translate',
        'all', 'none',
        'nositelinkssearchbox',
        'noodp', 'noydir', // Deprecated but valid
    ];

    // Directives that take parameters
    private const PARAMETRIC_DIRECTIVES = [
        'max-snippet',
        'max-image-preview',
        'max-video-preview',
    ];

    // Valid values for parametric directives
    private const VALID_VALUES = [
        'max-snippet' => ['pattern' => '/^(-1|0|[1-9]\d*)$/', 'type' => 'numeric'],
        'max-image-preview' => ['values' => ['none', 'standard', 'large']],
        'max-video-preview' => ['pattern' => '/^(-1|0|[1-9]\d*)$/', 'type' => 'numeric'],
    ];

    // Conflicting directive pairs
    private const CONFLICTS = [
        ['index', 'noindex'],
        ['follow', 'nofollow'],
        ['nosnippet', 'max-snippet'],
        ['all', 'none'],
    ];

    // Shorthand expansions
    private const SHORTHANDS = [
        'all' => ['index', 'follow'],
        'none' => ['noindex', 'nofollow'],
    ];

    // Valid user agents for X-Robots-Tag
    private const VALID_USER_AGENTS = [
        'googlebot',
        'googlebot-news',
        'googlebot-image',
        'googlebot-video',
        'bingbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandex',
        '*',
    ];

    /**
     * Parse and validate directives from a string
     *
     * @param string $content Raw directive content (e.g., "index, follow, max-snippet:-1")
     * @param string $source Source type ('meta' or 'header')
     * @param string|null $userAgent User agent (for X-Robots-Tag)
     * @return array Validation result
     */
    public function validate(string $content, string $source = 'meta', ?string $userAgent = null): array
    {
        $directives = $this->parseDirectives($content);
        $normalized = $this->normalizeDirectives($directives);
        $issues = $this->findIssues($normalized);
        $conflicts = $this->findConflicts($normalized);
        $redundancies = $this->findRedundancies($normalized);

        return [
            'raw' => $content,
            'source' => $source,
            'user_agent' => $userAgent,
            'directives' => $normalized,
            'valid' => empty($conflicts),
            'issues' => $issues,
            'conflicts' => $conflicts,
            'redundancies' => $redundancies,
            'is_full_spec' => $this->isFullSpec($normalized),
        ];
    }

    /**
     * Split and parse individual directives
     */
    private function parseDirectives(string $content): array
    {
        // Split by comma, handle spaces
        $parts = preg_split('/\s*,\s*/', trim($content));
        $parsed = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Check if parametric (contains colon)
            if (strpos($part, ':') !== false) {
                [$name, $value] = explode(':', $part, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                $parsed[] = [
                    'name' => $name,
                    'value' => $value,
                    'type' => 'parametric',
                    'valid' => $this->validateParametricDirective($name, $value),
                ];
            } else {
                $name = strtolower(trim($part));

                $parsed[] = [
                    'name' => $name,
                    'value' => null,
                    'type' => 'simple',
                    'valid' => in_array($name, self::SIMPLE_DIRECTIVES),
                ];
            }
        }

        return $parsed;
    }

    /**
     * Validate a parametric directive
     */
    private function validateParametricDirective(string $name, string $value): bool
    {
        if (! isset(self::VALID_VALUES[$name])) {
            return false;
        }

        $validation = self::VALID_VALUES[$name];

        if (isset($validation['pattern'])) {
            return preg_match($validation['pattern'], $value) === 1;
        }

        if (isset($validation['values'])) {
            return in_array(strtolower($value), $validation['values']);
        }

        return false;
    }

    /**
     * Normalize directives (lowercase, deduplicate)
     */
    private function normalizeDirectives(array $directives): array
    {
        $normalized = [];
        $seen = [];

        foreach ($directives as $directive) {
            $key = $directive['name'];
            if ($directive['type'] === 'parametric') {
                $key .= ':' . $directive['value'];
            }

            // Skip duplicates
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $directive;
        }

        return $normalized;
    }

    /**
     * Find issues (invalid directives, deprecated, etc.)
     */
    private function findIssues(array $directives): array
    {
        $issues = [];

        foreach ($directives as $directive) {
            if (! $directive['valid']) {
                $issues[] = [
                    'type' => 'invalid_directive',
                    'severity' => 'high',
                    'directive' => $directive['name'],
                    'message' => "Invalid directive: '{$directive['name']}'",
                ];
            }

            // Check for deprecated directives
            if (in_array($directive['name'], ['noodp', 'noydir'])) {
                $issues[] = [
                    'type' => 'deprecated',
                    'severity' => 'low',
                    'directive' => $directive['name'],
                    'message' => "'{$directive['name']}' is deprecated (DMOZ/Yahoo Directory shut down)",
                ];
            }
        }

        return $issues;
    }

    /**
     * Find conflicting directives
     */
    private function findConflicts(array $directives): array
    {
        $conflicts = [];
        $names = array_column($directives, 'name');

        foreach (self::CONFLICTS as $conflictPair) {
            $found = array_intersect($conflictPair, $names);

            if (count($found) > 1) {
                $conflicts[] = [
                    'directives' => array_values($found),
                    'severity' => 'high',
                    'message' => 'Conflicting directives: ' . implode(' and ', $found),
                    'resolution' => 'Most restrictive wins (' . $this->getMostRestrictive($found) . ')',
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Find redundant directives
     */
    private function findRedundancies(array $directives): array
    {
        $redundancies = [];
        $names = array_column($directives, 'name');

        // Check for shorthand + explicit
        foreach (self::SHORTHANDS as $short => $expanded) {
            if (in_array($short, $names)) {
                foreach ($expanded as $exp) {
                    if (in_array($exp, $names)) {
                        $redundancies[] = [
                            'type' => 'shorthand',
                            'severity' => 'low',
                            'message' => "'$short' already includes '$exp'",
                        ];
                    }
                }
            }
        }

        // Check for nosnippet + max-snippet
        if (in_array('nosnippet', $names)) {
            foreach ($directives as $d) {
                if ($d['name'] === 'max-snippet') {
                    $redundancies[] = [
                        'type' => 'duplicate',
                        'severity' => 'medium',
                        'message' => "'nosnippet' conflicts with 'max-snippet'",
                    ];
                }
            }
        }

        return $redundancies;
    }

    /**
     * Check if directives represent full specification
     */
    private function isFullSpec(array $directives): bool
    {
        $names = array_column($directives, 'name');

        $hasIndex = in_array('index', $names) || in_array('all', $names);
        $hasMaxSnippet = in_array('max-snippet', $names);
        $hasMaxImage = in_array('max-image-preview', $names);
        $hasMaxVideo = in_array('max-video-preview', $names);

        return $hasIndex && $hasMaxSnippet && $hasMaxImage && $hasMaxVideo;
    }

    /**
     * Determine which directive is most restrictive
     */
    private function getMostRestrictive(array $directives): string
    {
        $restrictive = ['noindex', 'nofollow', 'nosnippet', 'none'];

        foreach ($restrictive as $r) {
            if (in_array($r, $directives)) {
                return $r;
            }
        }

        return $directives[0] ?? '';
    }

    /**
     * Validate user agent (for X-Robots-Tag)
     */
    public function validateUserAgent(string $userAgent): array
    {
        $userAgent = strtolower(trim($userAgent));
        $valid = in_array($userAgent, self::VALID_USER_AGENTS);

        $issues = [];
        if (! $valid && $userAgent !== '*') {
            $issues[] = [
                'type' => 'unknown_user_agent',
                'severity' => 'low',
                'message' => "Unknown user agent: '$userAgent'",
                'note' => 'May still work, but not in the standard list',
            ];
        }

        return [
            'user_agent' => $userAgent,
            'valid' => $valid,
            'issues' => $issues,
        ];
    }
}
