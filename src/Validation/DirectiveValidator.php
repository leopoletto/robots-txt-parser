<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Validation;

use Leopoletto\RobotsTxtParser\Model\Severity;

/**
 * Validates the directive lists carried by robots meta tags and X-Robots-Tag
 * headers, e.g. "index, follow, max-snippet:-1".
 *
 * These share one grammar, so one validator serves both.
 */
final class DirectiveValidator
{
    /** Directives that take no parameter. */
    private const SIMPLE_DIRECTIVES = [
        'index', 'noindex',
        'follow', 'nofollow',
        'nosnippet',
        'noimageindex',
        'noarchive', 'archive',
        'notranslate', 'translate',
        'all', 'none',
        'nositelinkssearchbox',
        'indexifembedded',
        'noodp', 'noydir',
    ];

    /** Directives retired by the engines that once honoured them. */
    private const DEPRECATED_DIRECTIVES = ['noodp', 'noydir'];

    /** Accepted values for the directives that take a parameter. */
    private const PARAMETRIC_DIRECTIVES = [
        'max-snippet' => ['pattern' => '/^(-1|0|[1-9]\d*)$/'],
        'max-image-preview' => ['values' => ['none', 'standard', 'large']],
        'max-video-preview' => ['pattern' => '/^(-1|0|[1-9]\d*)$/'],
        'unavailable_after' => ['date' => true],
    ];

    /** Directive pairs that cannot both take effect. */
    private const CONFLICTS = [
        ['index', 'noindex'],
        ['follow', 'nofollow'],
        ['nosnippet', 'max-snippet'],
        ['all', 'none'],
        ['archive', 'noarchive'],
        ['translate', 'notranslate'],
    ];

    /** Directives that already imply others. */
    private const SHORTHANDS = [
        'all' => ['index', 'follow'],
        'none' => ['noindex', 'nofollow'],
    ];

    /** Crawlers that read a targeted X-Robots-Tag. */
    private const KNOWN_USER_AGENTS = [
        'googlebot', 'googlebot-news', 'googlebot-image', 'googlebot-video',
        'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandex', '*',
    ];

    /** Ordered most- to least-restrictive, for conflict resolution advice. */
    private const RESTRICTIVENESS = ['none', 'noindex', 'nofollow', 'nosnippet', 'noarchive', 'notranslate'];

    /**
     * Whether a name is a directive this vocabulary defines, parametric or not.
     * Lets callers tell "googlebot:" (a target) from "max-snippet:" (a directive).
     */
    public function isKnownDirective(string $name): bool
    {
        $name = strtolower(trim($name));

        return in_array($name, self::SIMPLE_DIRECTIVES, true)
            || array_key_exists($name, self::PARAMETRIC_DIRECTIVES);
    }

    public function validate(string $content): ValidationResult
    {
        $directives = $this->parse($content);

        return new ValidationResult(
            raw: $content,
            directives: $directives,
            issues: $this->findIssues($directives),
            conflicts: $this->findConflicts($directives),
            redundancies: $this->findRedundancies($directives),
            isFullSpec: $this->isFullSpec($directives),
        );
    }

    /**
     * Whether a targeted X-Robots-Tag names a crawler known to honour it.
     *
     * @return array{known: bool, issues: list<ValidationIssue>}
     */
    public function validateUserAgent(string $userAgent): array
    {
        $normalized = strtolower(trim($userAgent));
        $known = in_array($normalized, self::KNOWN_USER_AGENTS, true);

        return [
            'known' => $known,
            'issues' => $known ? [] : [new ValidationIssue(
                type: 'unknown_user_agent',
                severity: Severity::Low,
                message: "Unknown user agent: '{$normalized}'",
                note: 'May still work, but is not in the documented list',
            )],
        ];
    }

    /**
     * Split a comma-separated list into directives, deduplicating as it goes.
     *
     * @return list<ParsedDirective>
     */
    private function parse(string $content): array
    {
        $parts = preg_split('/\s*,\s*/', trim($content)) ?: [];
        $directives = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $colon = strpos($part, ':');

            if ($colon === false) {
                $name = strtolower($part);
                $directive = new ParsedDirective($name, null, in_array($name, self::SIMPLE_DIRECTIVES, true));
            } else {
                $name = strtolower(trim(substr($part, 0, $colon)));
                $value = trim(substr($part, $colon + 1));
                $directive = new ParsedDirective($name, $value, $this->isValidValue($name, $value));
            }

            $key = $directive->name . ':' . ($directive->value ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $directives[] = $directive;
        }

        return $directives;
    }

    private function isValidValue(string $name, string $value): bool
    {
        $rule = self::PARAMETRIC_DIRECTIVES[$name] ?? null;
        if ($rule === null) {
            return false;
        }

        if (isset($rule['pattern'])) {
            return preg_match($rule['pattern'], $value) === 1;
        }

        if (isset($rule['values'])) {
            return in_array(strtolower($value), $rule['values'], true);
        }

        // unavailable_after takes an RFC 850 / ISO 8601 date.
        return strtotime($value) !== false;
    }

    /**
     * @param list<ParsedDirective> $directives
     * @return list<ValidationIssue>
     */
    private function findIssues(array $directives): array
    {
        $issues = [];

        foreach ($directives as $directive) {
            if (! $directive->valid) {
                $issues[] = new ValidationIssue(
                    type: 'invalid_directive',
                    severity: Severity::High,
                    message: "Invalid directive: '{$directive->name}'",
                    directive: $directive->name,
                );
            }

            if (in_array($directive->name, self::DEPRECATED_DIRECTIVES, true)) {
                $issues[] = new ValidationIssue(
                    type: 'deprecated',
                    severity: Severity::Low,
                    message: "'{$directive->name}' is deprecated (DMOZ/Yahoo Directory shut down)",
                    directive: $directive->name,
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<ParsedDirective> $directives
     * @return list<ValidationIssue>
     */
    private function findConflicts(array $directives): array
    {
        $names = array_map(static fn (ParsedDirective $d): string => $d->name, $directives);
        $conflicts = [];

        foreach (self::CONFLICTS as $pair) {
            $found = array_values(array_intersect($pair, $names));
            if (count($found) < 2) {
                continue;
            }

            $conflicts[] = new ValidationIssue(
                type: 'conflict',
                severity: Severity::High,
                message: 'Conflicting directives: ' . implode(' and ', $found),
                note: 'Most restrictive wins (' . $this->mostRestrictive($found) . ')',
            );
        }

        return $conflicts;
    }

    /**
     * @param list<ParsedDirective> $directives
     * @return list<ValidationIssue>
     */
    private function findRedundancies(array $directives): array
    {
        $names = array_map(static fn (ParsedDirective $d): string => $d->name, $directives);
        $redundancies = [];

        foreach (self::SHORTHANDS as $shorthand => $implied) {
            if (! in_array($shorthand, $names, true)) {
                continue;
            }

            foreach ($implied as $name) {
                if (in_array($name, $names, true)) {
                    $redundancies[] = new ValidationIssue(
                        type: 'shorthand',
                        severity: Severity::Low,
                        message: "'{$shorthand}' already includes '{$name}'",
                    );
                }
            }
        }

        return $redundancies;
    }

    /**
     * Whether the list pins every preview control Google understands, which is
     * the configuration publishers are usually aiming for.
     *
     * @param list<ParsedDirective> $directives
     */
    private function isFullSpec(array $directives): bool
    {
        $names = array_map(static fn (ParsedDirective $d): string => $d->name, $directives);

        return (in_array('index', $names, true) || in_array('all', $names, true))
            && in_array('max-snippet', $names, true)
            && in_array('max-image-preview', $names, true)
            && in_array('max-video-preview', $names, true);
    }

    /**
     * @param list<string> $directives
     */
    private function mostRestrictive(array $directives): string
    {
        foreach (self::RESTRICTIVENESS as $name) {
            if (in_array($name, $directives, true)) {
                return $name;
            }
        }

        return $directives[0] ?? '';
    }
}
