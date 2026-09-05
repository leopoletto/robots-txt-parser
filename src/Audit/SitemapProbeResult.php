<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

final readonly class SitemapProbeResult
{
    public function __construct(
        public string $url,
        public ?int $statusCode,
        public bool $reachable,
        public bool $looksLikeXml,
        public ?string $contentType = null,
        public ?string $error = null,
    ) {
    }

    public function describe(): string
    {
        if ($this->error !== null) {
            return "Request failed: {$this->error}";
        }

        if ($this->statusCode === null) {
            return 'No response';
        }

        if (! $this->reachable) {
            return "Responded HTTP {$this->statusCode}";
        }

        if (! $this->looksLikeXml) {
            return sprintf(
                'HTTP %d, but the body does not open as XML%s',
                $this->statusCode,
                $this->contentType !== null ? " (Content-Type: {$this->contentType})" : '',
            );
        }

        return "HTTP {$this->statusCode}, valid sitemap root element";
    }
}
