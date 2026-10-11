<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * What a finding is about, so a report can be read by subject as well as by
 * how much attention each finding deserves.
 */
enum Category: string
{
    /** Which crawlers can reach which parts of the site, and whether the rules say what they mean. */
    case CrawlerAccess = 'crawler_access';

    /** What the file gives away to the people who read it for that purpose. */
    case Security = 'security';

    /** Whether crawlers can read the file as written. */
    case Syntax = 'syntax';

    /** How crawlers find the site's pages: its sitemaps. */
    case Discovery = 'discovery';

    /** Whether pages may be indexed, as told by headers and meta tags rather than crawl rules. */
    case Indexing = 'indexing';

    /** What the site says may be done with its content once fetched. */
    case UsagePreference = 'usage_preference';
}
