<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

/**
 * A sitemap prober that can be told which origin the robots.txt came from.
 *
 * A robots.txt speaks only for its own origin, so a sitemap it declares on
 * another origin is governed by that origin's robots.txt. A prober scoped to
 * an origin can tell those apart and decline to fetch them without a decision
 * about the other origin. The audit scopes any prober that implements this.
 */
interface OriginScopedSitemapProber extends SitemapProber
{
    /**
     * This prober, for sitemaps declared by the robots.txt at the given URL.
     */
    public function forOrigin(string $robotsUrl): self;
}
