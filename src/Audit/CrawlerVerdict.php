<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * What one file does to one crawler, and what that crawler is for.
 *
 * Findings about crawler access are read one crawler at a time — "may Googlebot
 * fetch this site, and what happens if it cannot" — so the report carries the
 * per-crawler answer rather than a list of names the reader has to look up.
 * Evidence stays what it is elsewhere in the audit: a line of the document.
 * This is a line of the document plus the context needed to judge it.
 */
final readonly class CrawlerVerdict
{
    public function __construct(
        public string $agent,
        public string $operator,
        /** What the crawler is for, in the site owner's terms. */
        public string $purpose,
        public bool $allowed,
        /** The rule that decided it, as it appears in the file. */
        public ?string $rule = null,
        public ?int $line = null,
    ) {
    }

    /**
     * The "Current policy" line: what the file does to this crawler today.
     */
    public function policy(): string
    {
        if ($this->rule === null) {
            return $this->allowed
                ? "No rule matches {$this->agent}, so it may crawl the site."
                : "{$this->agent} is blocked.";
        }

        return $this->allowed
            ? "\"{$this->rule}\" lets {$this->agent} crawl the site."
            : "\"{$this->rule}\" blocks {$this->agent}.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'agent' => $this->agent,
            'operator' => $this->operator,
            'purpose' => $this->purpose,
            'allowed' => $this->allowed,
            'policy' => $this->policy(),
            'rule' => $this->rule,
            'line' => $this->line,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
