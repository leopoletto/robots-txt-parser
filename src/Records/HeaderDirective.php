<?php

namespace Leopoletto\RobotsTxtParser\Records;

class HeaderDirective
{
    /**
     * @param array<string> $directives
     */
    public function __construct(
        public readonly array $directives
    ) {}
}

