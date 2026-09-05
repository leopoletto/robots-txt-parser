<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbe;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Record\Sitemap;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Checks that sitemaps are declared, well-formed, reachable, and not blocked by
 * the very file that points at them.
 *
 * A declared sitemap that 404s is worse than no sitemap: it looks correct in
 * review and silently delivers nothing.
 */
final class SitemapCheck implements AuditCheck
{
    public function __construct(private readonly ?SitemapProbe $probe = null)
    {
    }

    public function run(Response $response): array
    {
        $document = $response->document();
        $sitemaps = $document->sitemaps();

        if ($sitemaps === []) {
            return [new Finding(
                id: 'sitemap-missing',
                title: 'No sitemap is declared',
                status: Status::Warning,
                summary: 'The file contains no "Sitemap:" line.',
                impact: 'Crawlers have to discover every URL by following links. Pages that are deep, '
                    . 'newly published, or poorly linked internally take longer to be found, and some '
                    . 'may never be. A sitemap is the most direct way to hand a crawler the full list.',
                fix: 'Publish an XML sitemap and declare it with an absolute URL, e.g. '
                    . '"Sitemap: https://example.com/sitemap.xml". The line is global — it belongs '
                    . 'outside any User-agent group and applies to every crawler.',
            )];
        }

        $findings = [];

        $malformed = array_values(array_filter($sitemaps, static fn (Sitemap $s): bool => ! $s->valid));
        if ($malformed !== []) {
            $findings[] = new Finding(
                id: 'sitemap-malformed',
                title: sprintf('%d sitemap reference%s is not an absolute URL', count($malformed), count($malformed) === 1 ? '' : 's'),
                status: Status::Warning,
                summary: 'A "Sitemap:" line must give a full URL including the scheme and host.',
                impact: 'Crawlers ignore a relative or malformed sitemap reference, so these sitemaps '
                    . 'are never fetched — the same outcome as not declaring them.',
                fix: 'Rewrite each as an absolute URL, e.g. "https://example.com/sitemap.xml".',
                evidence: array_map(
                    static fn (Sitemap $s): Evidence => new Evidence($s->url, $s->line),
                    $malformed,
                ),
            );
        }

        $blocked = $this->blockedByOwnRules($response, $sitemaps);
        if ($blocked !== []) {
            $findings[] = new Finding(
                id: 'sitemap-blocked',
                title: 'A declared sitemap is disallowed by this same file',
                status: Status::Critical,
                summary: 'The robots.txt points crawlers at a sitemap URL its own rules forbid them to fetch.',
                impact: 'The sitemap cannot be read, so none of the URLs it lists are submitted. '
                    . 'The declaration gives a false impression that discovery is handled.',
                fix: 'Add an Allow rule for the sitemap path, or narrow the Disallow rule that covers it.',
                evidence: $blocked,
            );
        }

        // Live validation only runs when a probe was supplied.
        if ($this->probe !== null) {
            $findings = [...$findings, ...$this->probeFindings($sitemaps)];
        }

        if ($findings === []) {
            $findings[] = new Finding(
                id: 'sitemap-declared',
                title: sprintf('%d sitemap%s declared', count($sitemaps), count($sitemaps) === 1 ? '' : 's'),
                status: Status::Pass,
                summary: 'Every declared sitemap is an absolute URL and is crawlable.',
                impact: 'Crawlers are handed the URL list directly rather than discovering it by following links.',
                evidence: array_map(
                    static fn (Sitemap $s): Evidence => new Evidence($s->url, $s->line),
                    array_slice($sitemaps, 0, 10),
                ),
            );
        }

        return $findings;
    }

    /**
     * @param list<Sitemap> $sitemaps
     * @return list<Evidence>
     */
    private function blockedByOwnRules(Response $response, array $sitemaps): array
    {
        $blocked = [];

        foreach ($sitemaps as $sitemap) {
            if (! $sitemap->valid) {
                continue;
            }

            $decision = $response->document()->decide('Googlebot', $sitemap->url);
            if ($decision->allowed) {
                continue;
            }

            $blocked[] = new Evidence(
                text: $sitemap->url,
                line: $sitemap->line,
                detail: $decision->rule !== null
                    ? "Blocked by \"Disallow: {$decision->rule->value}\" on line {$decision->rule->line}"
                    : null,
            );
        }

        return $blocked;
    }

    /**
     * @param list<Sitemap> $sitemaps
     * @return list<Finding>
     */
    private function probeFindings(array $sitemaps): array
    {
        $unreachable = [];
        $notXml = [];

        foreach ($sitemaps as $sitemap) {
            if (! $sitemap->valid) {
                continue;
            }

            $result = $this->probe?->probe($sitemap->url);
            if ($result === null) {
                continue;
            }

            if (! $result->reachable) {
                $unreachable[] = new Evidence(
                    text: $sitemap->url,
                    line: $sitemap->line,
                    detail: $result->describe(),
                );

                continue;
            }

            if (! $result->looksLikeXml) {
                $notXml[] = new Evidence(
                    text: $sitemap->url,
                    line: $sitemap->line,
                    detail: $result->describe(),
                );
            }
        }

        $findings = [];

        if ($unreachable !== []) {
            $findings[] = new Finding(
                id: 'sitemap-unreachable',
                title: sprintf('%d declared sitemap%s could not be fetched', count($unreachable), count($unreachable) === 1 ? '' : 's'),
                status: Status::Critical,
                summary: 'The URL is declared in robots.txt but does not return a successful response.',
                impact: 'Crawlers find nothing at the address they were pointed to, so no URLs are '
                    . 'submitted. This is worse than declaring no sitemap, because the declaration '
                    . 'suggests discovery is covered when it is not.',
                fix: 'Publish the sitemap at the declared URL, or correct the URL to where it lives.',
                evidence: $unreachable,
            );
        }

        if ($notXml !== []) {
            $findings[] = new Finding(
                id: 'sitemap-not-xml',
                title: sprintf('%d declared sitemap%s does not return XML', count($notXml), count($notXml) === 1 ? '' : 's'),
                status: Status::Warning,
                summary: 'The URL responds, but the body does not open as an XML urlset or sitemapindex.',
                impact: 'A sitemap that is not valid XML is discarded. A common cause is the URL '
                    . 'resolving to an HTML error page or a redirect to the home page.',
                fix: 'Confirm the URL serves XML beginning with <urlset> or <sitemapindex>, and that '
                    . 'it is served as application/xml or text/xml.',
                evidence: $notXml,
            );
        }

        return $findings;
    }
}
