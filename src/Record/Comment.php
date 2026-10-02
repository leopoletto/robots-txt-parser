<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;

final readonly class Comment implements Record
{
    public function __construct(
        public int $line,
        public string $text,
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
            'comment' => $this->text,
        ];
    }
}
