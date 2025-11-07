<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;

class SyntaxError implements RobotsLineInterface
{
    public function __construct(
        public readonly int $line,
        public readonly string $message
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }
}
