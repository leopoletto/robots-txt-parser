<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Model\Severity;

/**
 * Something wrong with the document: a syntax error, a truncation, a directive
 * that cannot take effect.
 */
final readonly class Issue implements Record
{
    public function __construct(
        public int $line,
        public string $message,
        public Severity $severity = Severity::High,
        public string $type = 'syntax_error',
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'type' => $this->type,
        ];
    }
}
