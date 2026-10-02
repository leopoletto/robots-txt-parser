<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * How one category of crawler is treated by a file.
 */
final readonly class CategoryTally
{
    /**
     * @param list<string> $allowed
     * @param list<string> $blocked
     */
    public function __construct(
        public string $category,
        public array $allowed,
        public array $blocked,
    ) {
    }

    public function total(): int
    {
        return count($this->allowed) + count($this->blocked);
    }

    public function allowedCount(): int
    {
        return count($this->allowed);
    }

    public function blockedCount(): int
    {
        return count($this->blocked);
    }

    public function isFullyBlocked(): bool
    {
        return $this->allowed === [] && $this->blocked !== [];
    }

    public function isFullyAllowed(): bool
    {
        return $this->blocked === [] && $this->allowed !== [];
    }

    /**
     * A one-line reading of how this category is treated.
     */
    public function describe(): string
    {
        if ($this->isFullyBlocked()) {
            return sprintf('all %d blocked', $this->blockedCount());
        }

        if ($this->isFullyAllowed()) {
            return sprintf('all %d allowed', $this->allowedCount());
        }

        return sprintf('%d allowed, %d blocked', $this->allowedCount(), $this->blockedCount());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'allowed' => $this->allowed,
            'blocked' => $this->blocked,
            'allowedCount' => $this->allowedCount(),
            'blockedCount' => $this->blockedCount(),
            'total' => $this->total(),
            'summary' => $this->describe(),
        ];
    }
}
