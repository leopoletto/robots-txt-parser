<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Validation;

use Leopoletto\RobotsTxtParser\Model\Severity;

final readonly class ValidationIssue
{
    public function __construct(
        public string $type,
        public Severity $severity,
        public string $message,
        public ?string $directive = null,
        public ?string $note = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'directive' => $this->directive,
            'note' => $this->note,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
