<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

/**
 * One meaningful item recovered from a robots.txt document or its HTTP context.
 */
interface Record
{
    /**
     * 1-based line in the source document, or 0 for records that originate
     * outside the document body (HTTP headers, HTML meta tags).
     */
    public function line(): int;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
