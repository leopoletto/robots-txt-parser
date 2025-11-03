<?php

namespace Leopoletto\RobotsTxtParser\Records;

class Comment
{
    public function __construct(
        public readonly int $line,
        public readonly string $comment
    ) {}
}

