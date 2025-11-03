<?php

namespace Leopoletto\RobotsTxtParser\Records;

class UserAgent
{
    public function __construct(
        public readonly int $line,
        public readonly string $userAgent
    ) {}
}

