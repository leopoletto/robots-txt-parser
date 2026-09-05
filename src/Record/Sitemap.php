<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;

final readonly class Sitemap implements Record
{
    public function __construct(
        public int $line,
        public string $url,
        public bool $valid,
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
            'url' => $this->url,
            'valid' => $this->valid,
        ];
    }
}
