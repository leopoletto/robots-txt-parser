<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Matching\PathExplainer;
use Leopoletto\RobotsTxtParser\Matching\PathExplanation;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\Group;

/**
 * An `Allow:`, `Disallow:` or `Crawl-delay:` line, bound to the group it was
 * declared in.
 */
final class Directive implements Record
{
    private ?PathExplanation $explanation = null;

    public function __construct(
        public readonly int $line,
        public readonly DirectiveType $type,
        public readonly string $value,
        private readonly Group $group,
    ) {
    }

    public function line(): int
    {
        return $this->line;
    }

    public function group(): Group
    {
        return $this->group;
    }

    /**
     * User-agent tokens this directive applies to.
     *
     * @return list<string>
     */
    public function userAgents(): array
    {
        return $this->group->tokens();
    }

    /**
     * How specific this rule is. Longer patterns win over shorter ones when
     * both match a URL (RFC 9309 §2.2.2).
     */
    public function specificity(): int
    {
        return strlen($this->value);
    }

    public function delay(): ?float
    {
        return $this->type === DirectiveType::CrawlDelay && is_numeric($this->value)
            ? (float) $this->value
            : null;
    }

    /**
     * Plain-language account of how this pattern matches, built on first
     * request. Most callers never ask, so it is never built up front.
     */
    public function explanation(): ?PathExplanation
    {
        if (! $this->type->isPathRule()) {
            return null;
        }

        return $this->explanation ??= PathExplainer::explain($this->value);
    }

    public function toArray(): array
    {
        $data = [
            'line' => $this->line,
            'directive' => $this->type->value,
        ];

        if ($this->type === DirectiveType::CrawlDelay) {
            $data['delay'] = $this->delay();

            return $data;
        }

        $data['path'] = $this->value;
        $data['info'] = $this->explanation()?->toArray() ?? [];

        return $data;
    }
}
