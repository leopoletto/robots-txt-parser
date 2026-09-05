<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * A line of the document a finding points at, so a fix has somewhere to go.
 */
final readonly class Evidence
{
    public function __construct(
        public string $text,
        public ?int $line = null,
        public ?string $detail = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'line' => $this->line,
            'detail' => $this->detail,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
