<?php

namespace Leopoletto\RobotsTxtParser;

use Leopoletto\RobotsTxtParser\Collection\RobotsCollection;

class Response
{
    /**
     * @param RobotsCollection $records
     * @param int $size
     * @param string|null $finalUrl
     * @param array<string> $redirects
     * @param int|null $statusCode
     */
    public function __construct(
        private readonly RobotsCollection $records,
        private readonly int $size,
        private readonly ?string $finalUrl = null,
        private readonly array $redirects = [],
        private readonly ?int $statusCode = null,
    ) {
    }

    /**
     * Get the size of the parsed content in bytes
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Get all records as a RobotsCollection
     *
     * @return RobotsCollection
     */
    public function records(): RobotsCollection
    {
        return $this->records;
    }

    public function isValid(): bool
    {
        return $this->records->count() > 0;
    }

    /**
     * The final URL after all redirects (robots.txt URL)
     */
    public function finalUrl(): ?string
    {
        return $this->finalUrl;
    }

    /**
     * Redirect chain followed to reach the robots.txt
     *
     * @return array<string>
     */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /**
     * HTTP status code of the robots.txt response
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
