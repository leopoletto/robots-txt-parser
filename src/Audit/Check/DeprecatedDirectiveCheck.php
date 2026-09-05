<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Reports directives that no longer do what their author expects.
 *
 * These are the quiet failures: the line is syntactically fine, nothing reports
 * an error, and the intended effect simply never happens.
 */
final class DeprecatedDirectiveCheck implements AuditCheck
{
    public function run(Response $response): array
    {
        $document = $response->document();
        $findings = [];

        $crawlDelays = $document->directives(DirectiveType::CrawlDelay);
        if ($crawlDelays !== []) {
            $findings[] = new Finding(
                id: 'deprecated-crawl-delay',
                title: sprintf('Crawl-delay is declared %d time%s and Google ignores it', count($crawlDelays), count($crawlDelays) === 1 ? '' : 's'),
                status: Status::Notice,
                summary: 'Crawl-delay was never part of the standard. Google has never supported it; '
                    . 'Bing and Yandex do.',
                impact: 'Any rate limit intended for Googlebot is not being applied. If the goal was '
                    . 'to reduce load from Google specifically, it is not working.',
                fix: 'Set the crawl rate for Google in Search Console instead. Keep the directive if '
                    . 'the limit is meant for Bing or Yandex, which do honour it.',
                evidence: array_map(
                    static fn ($d): Evidence => new Evidence("Crawl-delay: {$d->value}", $d->line),
                    array_slice($crawlDelays, 0, 5),
                ),
            );
        }

        // "Noindex:" in robots.txt was an undocumented Google behaviour, removed
        // in September 2019. It is still copied between files years later.
        $noindex = array_values(array_filter(
            $document->issues(),
            static fn ($issue): bool => $issue->type === 'ineffective_directive'
                && str_contains(strtolower($issue->message), 'noindex'),
        ));

        if ($noindex !== []) {
            $findings[] = new Finding(
                id: 'deprecated-noindex',
                title: 'A "Noindex:" directive appears in robots.txt',
                status: Status::Warning,
                summary: 'Google removed support for Noindex in robots.txt in September 2019. '
                    . 'No crawler honours it.',
                impact: 'Pages this line was meant to keep out of search are being indexed normally. '
                    . 'The line reads as protection while providing none.',
                fix: 'Serve "X-Robots-Tag: noindex" as an HTTP header, or add '
                    . '<meta name="robots" content="noindex"> to the page. The page must stay '
                    . 'crawlable for either to be seen.',
                evidence: array_map(
                    static fn ($i): Evidence => new Evidence($i->message, $i->line),
                    array_slice($noindex, 0, 5),
                ),
            );
        }

        return $findings;
    }
}
