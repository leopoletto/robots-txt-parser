<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Reconciles robots.txt with the indexing directives on the page itself.
 *
 * This catches the most expensive misunderstanding in the whole subject:
 * Disallow and noindex are not the same instruction, and combining them
 * produces the opposite of what is intended. A crawler that is forbidden to
 * fetch a page never reads its noindex, so the URL stays eligible for search.
 */
final class IndexingDirectiveCheck implements AuditCheck
{
    public function run(Response $response): array
    {
        // Only a URL parse has a page to reconcile against.
        if ($response->requestedUrl() === null) {
            return [];
        }

        $document = $response->document();
        $decision = $response->pageDecision();

        if ($decision !== null && ! $decision->allowed) {
            return [new Finding(
                id: 'indexing-not-inspected',
                title: 'Page directives could not be checked',
                status: Status::Notice,
                summary: 'robots.txt disallows this URL, so the page was not requested and its meta '
                    . 'robots tags and X-Robots-Tag headers were not read.',
                impact: 'A disallowed URL can still appear in search results if other sites link to '
                    . 'it — the crawler never fetches the page, so it never sees a noindex, and '
                    . 'lists the URL without a description instead.',
                fix: 'To keep a page out of search entirely, allow crawling and serve "noindex" via a '
                    . 'meta tag or the X-Robots-Tag header. Disallow controls crawling; noindex '
                    . 'controls indexing, and only one of them removes a URL from results.',
                evidence: $decision->rule !== null
                    ? [new Evidence("Disallow: {$decision->rule->value}", $decision->rule->line)]
                    : [],
            )];
        }

        $meta = $document->metaDirectives();
        $headers = $document->headerDirectives();

        if ($meta === [] && $headers === []) {
            return [new Finding(
                id: 'indexing-none',
                title: 'No indexing directives on the requested page',
                status: Status::Pass,
                summary: 'The page sends no robots meta tag and no X-Robots-Tag header.',
                impact: 'Indexing is governed entirely by robots.txt and by whether the page is '
                    . 'linked to. Nothing is suppressing this page.',
            )];
        }

        $rules = $document->effectiveRules();
        $evidence = [];

        foreach ($meta as $tag) {
            $evidence[] = new Evidence("<meta name=\"{$tag->name}\" content=\"{$tag->raw}\">", null, 'Page HTML');
        }
        foreach ($headers as $header) {
            $evidence[] = new Evidence("X-Robots-Tag: {$header->raw}", null, ucfirst($header->origin) . ' response');
        }

        if (! $rules->indexable()) {
            return [new Finding(
                id: 'indexing-noindex',
                title: 'The requested page is set to noindex',
                status: Status::Warning,
                summary: 'A meta robots tag or X-Robots-Tag header instructs crawlers not to index this URL.',
                impact: 'The page will be dropped from search results. That is the correct mechanism '
                    . 'for removing a page — worth confirming it is intended for this URL.',
                fix: 'If the page should rank, remove the noindex directive. Keep the URL crawlable '
                    . 'either way, since a crawler must fetch the page to see the change.',
                evidence: $evidence,
            )];
        }

        $conflicts = [];
        foreach ([...$meta, ...$headers] as $record) {
            foreach ($record->validation->conflicts as $conflict) {
                $conflicts[] = new Evidence($record->raw, null, $conflict->message);
            }
        }

        if ($conflicts !== []) {
            return [new Finding(
                id: 'indexing-conflict',
                title: 'Indexing directives contradict each other',
                status: Status::Warning,
                summary: 'The page declares directives that cannot both apply.',
                impact: 'Crawlers resolve a contradiction by taking the most restrictive reading, '
                    . 'which is usually not what the author intended when they added the '
                    . 'permissive one.',
                fix: 'Remove the directive that is not wanted so the intended behaviour is explicit.',
                evidence: $conflicts,
            )];
        }

        return [new Finding(
            id: 'indexing-ok',
            title: 'Page indexing directives are consistent',
            status: Status::Pass,
            summary: sprintf(
                '%d meta tag%s and %d X-Robots-Tag header%s, with no contradictions.',
                count($meta),
                count($meta) === 1 ? '' : 's',
                count($headers),
                count($headers) === 1 ? '' : 's',
            ),
            impact: 'The page is crawlable and indexable, and its directives agree.',
            evidence: $evidence,
        )];
    }
}
