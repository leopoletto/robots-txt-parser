<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

use Leopoletto\RobotsTxtParser\Http\PageProbeResult;

/**
 * Answers what indexing directives a page sends: its robots meta tags and its
 * X-Robots-Tag headers.
 *
 * The parser only needs the answer, not the request that produced it, so an
 * implementation may fetch the page or replay what an earlier fetch found. A
 * stored robots.txt parsed again for the same URL then carries the page
 * directives it had when it was fetched.
 *
 * Whether the page may be requested at all is not this contract's concern: the
 * parser decides that from the robots.txt each time, and only asks when the
 * rules allow it.
 */
interface PageProber
{
    public function probe(string $url): PageProbeResult;
}
