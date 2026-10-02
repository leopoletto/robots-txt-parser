<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

/**
 * One source line, split into the parts a parser cares about.
 */
final readonly class Token
{
    public function __construct(
        public int $number,
        public string $raw,
        public ?string $field,
        public string $value,
        public ?string $comment,
    ) {
    }

    /**
     * A line carrying no directive — blank, or nothing but a comment.
     */
    public function isBlank(): bool
    {
        return $this->field === null && $this->value === '';
    }

    public function fieldIs(string $name): bool
    {
        return $this->field === strtolower($name);
    }
}
