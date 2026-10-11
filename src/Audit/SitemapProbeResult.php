<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * What one request for a declared sitemap found.
 *
 * It can be written out and read back, so the facts of a probe outlive the
 * request and can be handed to a later audit of the same file.
 */
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

    /**
     * @return array{url: string, statusCode: int|null, reachable: bool, looksLikeXml: bool, contentType: string|null, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'statusCode' => $this->statusCode,
            'reachable' => $this->reachable,
            'looksLikeXml' => $this->looksLikeXml,
            'contentType' => $this->contentType,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $data The output of toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            statusCode: isset($data['statusCode']) ? (int) $data['statusCode'] : null,
            reachable: (bool) ($data['reachable'] ?? false),
            looksLikeXml: (bool) ($data['looksLikeXml'] ?? false),
            contentType: isset($data['contentType']) ? (string) $data['contentType'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
        );
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
