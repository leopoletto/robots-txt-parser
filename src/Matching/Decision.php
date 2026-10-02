<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Matching;

use Leopoletto\RobotsTxtParser\Record\Directive;

/**
 * The outcome of an allowance check, and the rule that decided it.
 */
final readonly class Decision
{
    public function __construct(
        public bool $allowed,
        public ?Directive $rule,
        public string $path,
    ) {
    }

    /**
     * True when no rule matched and the default permission applied.
     */
    public function byDefault(): bool
    {
        return $this->rule === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'allowed' => $this->allowed,
            'byDefault' => $this->byDefault(),
            'rule' => $this->rule?->toArray(),
        ];
    }
}
