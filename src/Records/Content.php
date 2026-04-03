<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class Content implements RobotsLineInterface
{

    public function __construct(
        public readonly int $line,
        public readonly string $content
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function content(): string
    {
        return $this->content;
    }

}
