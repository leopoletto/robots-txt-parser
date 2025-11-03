<?php

namespace Leopoletto\RobotsTxtParser\Records;

class RobotsDirective
{
    public function __construct(
        public readonly int $line,
        public readonly string $directive,
        public readonly string $path
    ) {}
}

