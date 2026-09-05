<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

use JsonException;

/**
 * The crawlers the audit reports on, and what blocking each group costs.
 *
 * Selection and purpose classification come from Cloudflare Radar, which
 * measures observed crawler traffic rather than estimating importance. The list
 * lives in data/crawlers.json so it can be regenerated from Radar without a
 * code change; see bin/import-crawlers.php.
 *
 * Purpose is the load-bearing distinction, and the reason the list is grouped
 * rather than flat: blocking a training crawler costs a content donation,
 * blocking a user-triggered fetcher blocks a person who asked for the page, and
 * blocking a search engine removes the site from search. Same mechanism, three
 * very different consequences.
 *
 * @see https://radar.cloudflare.com/bots
 */
final class CrawlerDirectory
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/../data/crawlers.json';
    }

    /**
     * Crawler names and descriptions for one group, keyed by agent token.
     *
     * @return array<string, string>
     */
    public function crawlers(string $group): array
    {
        $crawlers = [];

        foreach ($this->data()['crawlers'] ?? [] as $crawler) {
            if (($crawler['group'] ?? null) !== $group) {
                continue;
            }

            $agent = (string) ($crawler['agent'] ?? '');
            if ($agent !== '') {
                $crawlers[$agent] = (string) ($crawler['description'] ?? $agent);
            }
        }

        return $crawlers;
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = $this->data()['groups'] ?? [];

        // Group keys come from JSON, where a numeric-looking key decodes to an
        // int; the rest of the API treats them as strings.
        return array_map(strval(...), array_keys(is_array($groups) ? $groups : []));
    }

    public function label(string $group): string
    {
        return (string) ($this->group($group)['label'] ?? $group);
    }

    /**
     * The label as it reads mid-sentence: "all 4 checked AI answer engines".
     *
     * Only the leading word is lowered, and only when it is not an acronym, so
     * "AI answer engines" keeps its capitals while "Search engines" does not.
     */
    public function inlineLabel(string $group): string
    {
        $label = $this->label($group);
        $words = explode(' ', $label, 2);

        if ($words[0] !== strtoupper($words[0])) {
            $words[0] = lcfirst($words[0]);
        }

        return implode(' ', $words);
    }

    /**
     * Whether blocking this group is a policy choice rather than a defect.
     */
    public function isDiscretionary(string $group): bool
    {
        return (bool) ($this->group($group)['discretionary'] ?? false);
    }

    public function severity(string $group): Status
    {
        return Status::tryFrom((string) ($this->group($group)['severity'] ?? 'warning')) ?? Status::Warning;
    }

    public function upside(string $group): string
    {
        return (string) ($this->group($group)['upside'] ?? 'These crawlers can reach the site.');
    }

    public function downside(string $group): string
    {
        return (string) ($this->group($group)['downside'] ?? 'These crawlers cannot reach the site.');
    }

    /**
     * Where this list came from, for attribution in the report.
     *
     * @return array<string, string>
     */
    public function source(): array
    {
        $source = $this->data()['source'] ?? [];

        return is_array($source) ? array_map(strval(...), $source) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $group): array
    {
        $groups = $this->data()['groups'] ?? [];
        $found = is_array($groups) ? ($groups[$group] ?? []) : [];

        return is_array($found) ? $found : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        if (self::$cache !== null && isset(self::$cache[$this->path])) {
            return self::$cache[$this->path];
        }

        $decoded = [];

        if (is_file($this->path)) {
            $raw = file_get_contents($this->path);
            if ($raw !== false) {
                try {
                    $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $decoded = is_array($parsed) ? $parsed : [];
                } catch (JsonException) {
                    // A malformed list degrades the audit to reporting nothing
                    // about crawler access, rather than failing the parse.
                    $decoded = [];
                }
            }
        }

        self::$cache[$this->path] = $decoded;

        return $decoded;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
