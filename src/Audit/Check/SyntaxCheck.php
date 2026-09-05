<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Reports defects in the document itself.
 *
 * Only issues that describe the file are included. Notes about our own fetch —
 * a page we declined to request, a redirect chain — say nothing about whether
 * the robots.txt is correct, and counting them as errors would report a fault
 * against a file that has none.
 */
final class SyntaxCheck implements AuditCheck
{
    /** Issue types that describe our request rather than the document. */
    private const FETCH_ISSUES = ['page_disallowed', 'fetch_failed', 'empty_response', 'too_many_redirects'];

    /** Reported by their own dedicated checks, with better advice. */
    private const HANDLED_ELSEWHERE = ['truncated', 'ineffective_directive', 'nonstandard_directive'];

    public function run(Response $response): array
    {
        $issues = array_values(array_filter(
            $response->document()->issues(),
            static fn (Issue $issue): bool => ! in_array($issue->type, self::FETCH_ISSUES, true)
                && ! in_array($issue->type, self::HANDLED_ELSEWHERE, true),
        ));

        if ($issues === []) {
            return [new Finding(
                id: 'syntax-clean',
                title: 'No syntax problems',
                status: Status::Pass,
                summary: 'Every line parses as a directive crawlers understand.',
                impact: 'Nothing in the file is being silently skipped.',
            )];
        }

        $byType = [];
        foreach ($issues as $issue) {
            $byType[$issue->type][] = $issue;
        }

        return [new Finding(
            id: 'syntax-issues',
            title: sprintf('%d line%s will be ignored by crawlers', count($issues), count($issues) === 1 ? '' : 's'),
            status: Status::Warning,
            summary: 'Lines that do not parse as a known directive: ' . implode(', ', array_keys($byType)) . '.',
            impact: 'A crawler skips what it cannot parse, without reporting anything. A misspelled '
                . '"Dissalow" leaves a path fully crawlable while the file appears to restrict it.',
            fix: 'Correct the spelling of each field name, and make sure every line follows '
                . '"Field: value". Rules must also appear after a User-agent line to belong to a group.',
            evidence: array_map(
                static fn (Issue $i): Evidence => new Evidence($i->message, $i->line, $i->type),
                array_slice($issues, 0, 15),
            ),
        )];
    }
}
