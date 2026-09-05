<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Model;

/**
 * The robots.txt directives this parser recognises inside a user-agent group.
 */
enum DirectiveType: string
{
    case Allow = 'allow';
    case Disallow = 'disallow';
    case CrawlDelay = 'crawl-delay';

    public static function tryFromField(string $field): ?self
    {
        return self::tryFrom(strtolower(trim($field)));
    }

    /**
     * Whether the directive's value is a URL path pattern rather than a scalar.
     */
    public function isPathRule(): bool
    {
        return $this === self::Allow || $this === self::Disallow;
    }
}
