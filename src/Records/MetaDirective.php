<?php

namespace Leopoletto\RobotsTxtParser\Records;

class MetaDirective
{
    /**
     * @param array<string> $directives
     */
    public function __construct(
        public readonly array $directives
    ) {}
}

