<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The outcome of one HTTP request, successful or not.
 *
 * A failed fetch is a value rather than an exception: a site with no
 * robots.txt, or one that times out, is a finding to report, not a crash.
 */
final readonly class FetchResult
{
    /**
     * @param list<string> $redirects
     */
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public ?int $statusCode,
        public array $redirects = [],
        public ?ResponseInterface $response = null,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $url, string $reason): self
    {
        return new self($url, $url, null, [], null, $reason);
    }

    public function succeeded(): bool
    {
        return $this->response !== null && $this->error === null;
    }

    /**
     * Whether the response carries content worth parsing.
     */
    public function isOk(): bool
    {
        return $this->succeeded() && $this->statusCode !== null
            && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @return list<string>
     */
    public function header(string $name): array
    {
        // PSR-7 returns array<string>; callers here rely on a list.
        return array_values($this->response?->getHeader($name) ?? []);
    }

    public function body(): ?StreamInterface
    {
        return $this->response?->getBody();
    }

    public function exceededRedirectLimit(int $max): bool
    {
        return count($this->redirects) >= $max;
    }
}
