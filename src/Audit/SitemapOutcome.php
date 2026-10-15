<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * What became of one request for a declared sitemap.
 *
 * Only Observed and HttpError say anything about the sitemap itself: one is
 * an answer to judge, the other a real error status from the server. The rest
 * mean the sitemap was not seen, and a sitemap not seen is neither broken nor
 * fine.
 */
enum SitemapOutcome: string
{
    /** The server answered 2xx and the opening bytes were read. */
    case Observed = 'observed';

    /** The server answered with a 4xx or 5xx status. */
    case HttpError = 'http_error';

    /** robots.txt forbids our bot to fetch it, so it was not requested. */
    case Blocked = 'blocked';

    /** Left unfetched by a limit, or for want of a decision about its origin. */
    case Skipped = 'skipped';

    /** The request ran out of time. */
    case Timeout = 'timeout';

    /** The request failed without an answer: DNS, connection, TLS. */
    case Error = 'error';

    /**
     * Whether the sitemap was never actually seen.
     */
    public function isNotChecked(): bool
    {
        return match ($this) {
            self::Blocked, self::Skipped, self::Timeout, self::Error => true,
            self::Observed, self::HttpError => false,
        };
    }
}
