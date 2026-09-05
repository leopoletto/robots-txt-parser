<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * One audited fact about a robots.txt, phrased for someone who has to act on it.
 *
 * The three prose fields answer the three questions in order: what is the case,
 * why it matters for visibility, and what to do about it.
 */
final readonly class Finding
{
    /**
     * @param list<Evidence> $evidence
     */
    public function __construct(
        public string $id,
        public string $title,
        public Status $status,
        public string $summary,
        public string $impact,
        public ?string $fix = null,
        public array $evidence = [],
    ) {
    }

    public function isActionable(): bool
    {
        return $this->status !== Status::Pass;
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
            'impact' => $this->impact,
            'fix' => $this->fix,
            'evidence' => array_map(static fn (Evidence $e): array => $e->toArray(), $this->evidence),
        ];
    }
}
