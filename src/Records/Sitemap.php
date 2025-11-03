<?php

namespace Leopoletto\RobotsTxtParser\Records;

class Sitemap
{
    public function __construct(
        public readonly int $line,
        public readonly string $url,
        public readonly bool $valid
    ) {}
}

