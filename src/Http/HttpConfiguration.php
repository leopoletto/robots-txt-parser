<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

/**
 * Network and size limits for a URL parse.
 *
 * Defaults follow what real crawlers do rather than what is technically
 * possible: Google reads at most 500 KB of a robots.txt and follows at most
 * five redirects, so an analysis tool that behaves differently would report
 * rules no crawler will ever apply.
 */
final readonly class HttpConfiguration
{
    /** Google's documented robots.txt size limit. Content past it is ignored. */
    public const DEFAULT_MAX_BYTES = 500 * 1024;

    /** Meta tags live in <head>; a megabyte is generous for reaching it. */
    public const DEFAULT_MAX_HTML_BYTES = 1024 * 1024;

    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxHtmlBytes = self::DEFAULT_MAX_HTML_BYTES,
        public int $maxRedirects = 5,
        public int $robotsTimeout = 10,
        public int $pageTimeout = 10,
        public bool $verify = true,
    ) {
    }

    public function withMaxBytes(int $bytes): self
    {
        return new self(
            $bytes,
            $this->maxHtmlBytes,
            $this->maxRedirects,
            $this->robotsTimeout,
            $this->pageTimeout,
            $this->verify,
        );
    }
}
