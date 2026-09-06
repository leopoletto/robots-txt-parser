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

        foreach ($this->entries() as $crawler) {
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
     * Who runs a crawler, which is what lets one operator's agents be compared
     * against each other.
     */
    public function operator(string $agent): string
    {
        foreach ($this->entries() as $crawler) {
            if (($crawler['agent'] ?? null) === $agent) {
                return (string) ($crawler['operator'] ?? '');
            }
        }

        return '';
    }

    /**
     * Every crawler in the list, as raw rows.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $crawlers = $this->data()['crawlers'] ?? [];

        return is_array($crawlers) ? array_values(array_filter($crawlers, is_array(...))) : [];
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
     * What blocking this group achieves, phrased as the intent it would serve.
     */
    public function intent(string $group): string
    {
        return (string) ($this->group($group)['intent'] ?? 'keep these crawlers off the site');
    }

    /**
     * What blocking this group costs, stated without judging the choice.
     */
    public function consequence(string $group): string
    {
        return (string) ($this->group($group)['consequence'] ?? 'These crawlers cannot reach the site.');
    }

    /**
     * How to let this group back in, for a reader who did not intend the block.
     */
    public function remedy(string $group): string
    {
        return (string) ($this->group($group)['remedy'] ?? 'Remove the matching Disallow rule.');
    }

    public function upside(string $group): string
    {
        return (string) ($this->group($group)['upside'] ?? 'These crawlers can reach the site.');
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
