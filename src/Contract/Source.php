<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

/**
 * A stream of robots.txt lines, whatever the origin.
 *
 * Implementations yield every line — blank ones included — keyed by its
 * 1-based number, so reported line numbers always match the original document.
 */
interface Source
{
    /**
     * @return iterable<int, string>
     */
    public function lines(): iterable;

    /**
     * Bytes consumed. Only meaningful once lines() has been fully iterated.
     */
    public function bytesRead(): int;

    /**
     * Whether the source stopped short because it hit the configured byte limit.
     */
    public function truncated(): bool;
}
