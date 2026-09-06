<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * One audited fact about a robots.txt, phrased for someone who has to act on it.
 *
 * The prose fields answer, in order: what is the case, what it would mean if
 * this were deliberate, what it costs either way, and what to change if it was
 * not. The middle question is the one a linter usually skips, and the reason
 * most robots.txt advice reads as scolding: a file that blocks every AI crawler
 * is not broken, and a report that cannot say so is not much use.
 */
final readonly class Finding
{
    /**
     * @param list<Evidence>       $evidence
     * @param list<CrawlerVerdict> $crawlers
     */
    public function __construct(
        public string $id,
        public string $title,
        public Status $status,
        public string $summary,
        public string $impact,
        public ?string $fix = null,
        public array $evidence = [],
        /** What this configuration achieves, if it was the intent. */
        public ?string $intent = null,
        /** Per-crawler detail, for findings about crawler access. */
        public array $crawlers = [],
    ) {
    }

    public function isActionable(): bool
    {
        return $this->status->isActionable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'intent' => $this->intent,
            'impact' => $this->impact,
            'fix' => $this->fix,
            'evidence' => array_map(static fn (Evidence $e): array => $e->toArray(), $this->evidence),
            'crawlers' => array_map(static fn (CrawlerVerdict $c): array => $c->toArray(), $this->crawlers),
        ];
    }
}
