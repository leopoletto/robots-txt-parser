<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Validation;

/**
 * One directive out of a robots meta tag or X-Robots-Tag header,
 * e.g. `noindex` or `max-snippet:-1`.
 */
final readonly class ParsedDirective
{
    public function __construct(
        public string $name,
        public ?string $value,
        public bool $valid,
    ) {
    }

    public function isParametric(): bool
    {
        return $this->value !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'type' => $this->isParametric() ? 'parametric' : 'simple',
            'valid' => $this->valid,
        ];
    }
}
