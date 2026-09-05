<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Agents;

/**
 * A known crawler, as described by the bundled agent dataset.
 */
final readonly class Agent
{
    public function __construct(
        public string $name,
        public ?string $category = null,
        public ?string $description = null,
        public ?string $path = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            category: isset($data['category']) ? (string) $data['category'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            path: isset($data['path']) ? (string) $data['path'] : null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'path' => $this->path,
        ];
    }
}
