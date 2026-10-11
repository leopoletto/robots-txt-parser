<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

use Leopoletto\RobotsTxtParser\Audit\SitemapProbeResult;

/**
 * Answers whether a declared sitemap is really there.
 *
 * The audit only needs the answer, not the request that produced it, so an
 * implementation may fetch the sitemap, record what another one found, or
 * replay results recorded earlier: an audit run again over a stored file
 * then reaches the verdict it would have reached when the file was fetched.
 */
interface SitemapProber
{
    public function probe(string $url): SitemapProbeResult;
}
