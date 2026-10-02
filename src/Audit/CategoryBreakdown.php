<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Record\UserAgent;

/**
 * Summarises a file's posture by what its declared crawlers are *for*.
 *
 * The Radar-derived groups in CrawlerDirectory answer "can the crawlers that
 * matter reach this site". This answers a different question: of everything
 * this file bothers to name, what is it letting in and keeping out.
 *
 * The distinction matters for files that name a lot of agents. LinkedIn
 * declares 77; reading them one by one says nothing, but grouped by category
 * the policy states itself — every search engine allowed, every AI scraper
 * blocked.
 */
final class CategoryBreakdown
{
    /**
     * @param list<CategoryTally> $categories
     */
    private function __construct(
        public readonly array $categories,
        public readonly int $declared,
        public readonly int $recognised,
    ) {
    }

    public static function of(Document $document, string $path = '/'): self
    {
        /** @var array<string, array{allowed: list<string>, blocked: list<string>}> $tallies */
        $tallies = [];
        $seen = [];
        $recognised = 0;

        foreach ($document->userAgents() as $userAgent) {
            // A file may declare the same agent more than once; count it once.
            $key = strtolower($userAgent->token);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($userAgent->agent !== null) {
                $recognised++;
            }

            $category = self::categoryOf($userAgent);
            $tallies[$category] ??= ['allowed' => [], 'blocked' => []];

            $verdict = $document->isAllowed($userAgent->token, $path) ? 'allowed' : 'blocked';
            $tallies[$category][$verdict][] = $userAgent->token;
        }

        $categories = [];
        foreach ($tallies as $name => $tally) {
            $categories[] = new CategoryTally($name, $tally['allowed'], $tally['blocked']);
        }

        // Most-blocked first: that is where a file's policy is expressed.
        usort($categories, static function (CategoryTally $a, CategoryTally $b): int {
            return [$b->blockedCount(), $b->total()] <=> [$a->blockedCount(), $a->total()];
        });

        return new self($categories, count($seen), $recognised);
    }

    /**
     * Categories where every declared agent is blocked — the clearest statement
     * a file makes about what it does not want.
     *
     * @return list<CategoryTally>
     */
    public function fullyBlocked(): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (CategoryTally $t): bool => $t->isFullyBlocked(),
        ));
    }

    /**
     * @return list<CategoryTally>
     */
    public function fullyAllowed(): array
    {
        return array_values(array_filter(
            $this->categories,
            static fn (CategoryTally $t): bool => $t->isFullyAllowed(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->declared,
            'recognised' => $this->recognised,
            'categories' => array_map(static fn (CategoryTally $t): array => $t->toArray(), $this->categories),
        ];
    }

    private static function categoryOf(UserAgent $userAgent): string
    {
        if ($userAgent->isWildcard()) {
            return 'Every other crawler';
        }

        $category = $userAgent->agent?->category;

        return $category !== null && $category !== '' ? $category : 'Unrecognised';
    }
}
