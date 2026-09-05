<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser;

use Leopoletto\RobotsTxtParser\Matching\Decision;
use Leopoletto\RobotsTxtParser\Parsing\Document;

/**
 * The result of a parse: the document, plus the HTTP context it arrived in.
 *
 * The HTTP fields are null for file and text parses, which have no network
 * story to tell.
 */
final readonly class Response
{
    /**
     * @param array<string> $redirects
     */
    public function __construct(
        private Document $document,
        private ?string $content = null,
        private ?string $requestedUrl = null,
        private ?string $robotsUrl = null,
        private ?string $finalUrl = null,
        private array $redirects = [],
        private ?int $statusCode = null,
        private ?int $pageStatusCode = null,
        private ?string $error = null,
        private ?Decision $pageDecision = null,
    ) {
    }

    public function document(): Document
    {
        return $this->document;
    }

    /**
     * Alias kept for readability at call sites that think in records.
     */
    public function records(): Document
    {
        return $this->document;
    }

    /**
     * The raw document, when the parser was asked to keep it.
     */
    public function content(): ?string
    {
        return $this->content;
    }

    /**
     * Bytes of robots.txt actually read.
     */
    public function size(): int
    {
        return $this->document->size();
    }

    /**
     * Whether the document was cut short at the size limit.
     */
    public function truncated(): bool
    {
        return $this->document->truncated();
    }

    public function isValid(): bool
    {
        return $this->error === null && $this->document->issues() === [];
    }

    /**
     * The URL the caller asked about — the page, not the origin.
     */
    public function requestedUrl(): ?string
    {
        return $this->requestedUrl;
    }

    /**
     * The robots.txt URL derived from the requested URL's origin.
     */
    public function robotsUrl(): ?string
    {
        return $this->robotsUrl;
    }

    /**
     * Where the robots.txt request actually landed after redirects.
     */
    public function finalUrl(): ?string
    {
        return $this->finalUrl;
    }

    /**
     * @return array<string>
     */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /**
     * HTTP status of the robots.txt response.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * HTTP status of the requested page, when one was inspected.
     */
    public function pageStatusCode(): ?int
    {
        return $this->pageStatusCode;
    }

    /**
     * Why the fetch failed, when it did.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * How the fetched robots.txt ruled on the requested URL for our own bot.
     *
     * Null for file and text parses, and for a URL that was already a
     * robots.txt. When this says disallowed, no page request was made and
     * there will be no meta tags or page headers in the document — we obey the
     * rules we are reporting on.
     */
    public function pageDecision(): ?Decision
    {
        return $this->pageDecision;
    }

    /**
     * Whether the requested page was actually fetched for meta tags and
     * headers. False when robots.txt disallowed it, or the request failed.
     */
    public function pageInspected(): bool
    {
        return $this->pageStatusCode !== null;
    }

    public function isAllowed(string $userAgent, string $path): bool
    {
        return $this->document->isAllowed($userAgent, $path);
    }

    public function decide(string $userAgent, string $path): Decision
    {
        return $this->document->decide($userAgent, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document->toArray() + [
            'requestedUrl' => $this->requestedUrl,
            'robotsUrl' => $this->robotsUrl,
            'finalUrl' => $this->finalUrl,
            'redirects' => $this->redirects,
            'statusCode' => $this->statusCode,
            'pageStatusCode' => $this->pageStatusCode,
            'pageInspected' => $this->pageInspected(),
            'pageDecision' => $this->pageDecision?->toArray(),
            'error' => $this->error,
        ];
    }
}
