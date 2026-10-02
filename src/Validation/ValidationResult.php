<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Validation;

/**
 * The outcome of validating one directive list — a meta tag's content
 * attribute, or one X-Robots-Tag header value.
 */
final readonly class ValidationResult
{
    /**
     * @param list<ParsedDirective> $directives
     * @param list<ValidationIssue> $issues
     * @param list<ValidationIssue> $conflicts
     * @param list<ValidationIssue> $redundancies
     */
    public function __construct(
        public string $raw,
        public array $directives,
        public array $issues,
        public array $conflicts,
        public array $redundancies,
        public bool $isFullSpec,
    ) {
    }

    /**
     * A copy carrying additional issues, for callers that validate more than
     * the directive list itself (an X-Robots-Tag's user-agent target, say).
     *
     * @param list<ValidationIssue> $issues
     */
    public function withIssues(array $issues): self
    {
        return new self(
            raw: $this->raw,
            directives: $this->directives,
            issues: [...$this->issues, ...$issues],
            conflicts: $this->conflicts,
            redundancies: $this->redundancies,
            isFullSpec: $this->isFullSpec,
        );
    }

    public function isValid(): bool
    {
        return $this->conflicts === [];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ParsedDirective $d): string => $d->name, $this->directives);
    }

    public function has(string $name): bool
    {
        return in_array(strtolower($name), $this->names(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'directives' => array_map(static fn (ParsedDirective $d): array => $d->toArray(), $this->directives),
            'valid' => $this->isValid(),
            'issues' => array_map(static fn (ValidationIssue $i): array => $i->toArray(), $this->issues),
            'conflicts' => array_map(static fn (ValidationIssue $i): array => $i->toArray(), $this->conflicts),
            'redundancies' => array_map(static fn (ValidationIssue $i): array => $i->toArray(), $this->redundancies),
            'is_full_spec' => $this->isFullSpec,
        ];
    }
}
