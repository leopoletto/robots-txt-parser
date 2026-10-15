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
    /**
     * What became of the request. Given explicitly by blocked() and skipped();
     * otherwise worked out from the other fields, so results built or stored
     * before outcomes existed get the one their fields imply.
     */
    public SitemapOutcome $outcome;

    /**
     * @param string|null $reason Why a sitemap was not fetched, for a blocked or skipped one.
     */
    public function __construct(
        public string $url,
        public ?int $statusCode,
        public bool $reachable,
        public bool $looksLikeXml,
        public ?string $contentType = null,
        public ?string $error = null,
        ?SitemapOutcome $outcome = null,
        public ?string $reason = null,
    ) {
        $this->outcome = $outcome ?? self::outcomeOf($statusCode, $error);
    }

    /**
     * A sitemap robots.txt forbids our bot to fetch, so it was not requested.
     */
    public static function blocked(string $url, string $reason): self
    {
        return new self($url, null, false, false, outcome: SitemapOutcome::Blocked, reason: $reason);
    }

    /**
     * A sitemap left unfetched: by a limit, or for want of a decision about
     * the origin it lives on.
     */
    public static function skipped(string $url, string $reason): self
    {
        return new self($url, null, false, false, outcome: SitemapOutcome::Skipped, reason: $reason);
    }

    /**
     * Whether the sitemap was never actually seen, so nothing can be said of it.
     */
    public function isNotChecked(): bool
    {
        return $this->outcome->isNotChecked();
    }

    /**
     * @return array{url: string, statusCode: int|null, reachable: bool, looksLikeXml: bool, contentType: string|null, error: string|null, outcome: string, reason: string|null}
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
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data The output of toArray(). One stored without an
     *                                   outcome gets the one its fields imply.
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
            outcome: is_string($data['outcome'] ?? null) ? SitemapOutcome::tryFrom($data['outcome']) : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }

    public function describe(): string
    {
        if ($this->outcome === SitemapOutcome::Blocked || $this->outcome === SitemapOutcome::Skipped) {
            return 'Not fetched: ' . ($this->reason ?? 'no reason recorded');
        }

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

    /**
     * The outcome a result's fields imply. A result with no status and no
     * error is the stand-in for a sitemap deliberately left unfetched.
     */
    private static function outcomeOf(?int $statusCode, ?string $error): SitemapOutcome
    {
        return match (true) {
            $error !== null => stripos($error, 'timed out') !== false ? SitemapOutcome::Timeout : SitemapOutcome::Error,
            $statusCode === null => SitemapOutcome::Skipped,
            $statusCode >= 200 && $statusCode < 300 => SitemapOutcome::Observed,
            default => SitemapOutcome::HttpError,
        };
    }
}
