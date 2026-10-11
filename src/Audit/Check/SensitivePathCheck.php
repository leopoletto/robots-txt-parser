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
 * Flags paths that a robots.txt should not be naming.
 *
 * robots.txt is world-readable and one of the first files any scanner fetches.
 * Disallowing /admin does not hide it — it publishes a list of the places worth
 * attacking, to exactly the audience that would not have honoured the rule.
 *
 * Only paths whose listing is itself the leak are flagged. An address a site's
 * own pages already call, such as /api or /graphql, or one every install of a
 * product shares, such as /wp-admin, tells a reader nothing new, and Disallow
 * is the right way to keep crawlers off it.
 */
final class SensitivePathCheck implements AuditCheck
{
    /**
     * Path fragments that suggest something not meant for the public, grouped
     * by what a reader would learn from seeing them listed.
     */
    private const PATTERNS = [
        'administration' => ['/admin', '/administrator', '/backend', '/cpanel', '/phpmyadmin', '/adminer'],
        'internal environments' => ['/staging', '/stage', '/dev', '/test', '/uat', '/sandbox', '/preview', '/beta'],
        'infrastructure' => ['/internal', '/private', '/debug', '/config', '/.env', '/.git', '/server-status'],
        'data and backups' => ['/backup', '/backups', '/dump', '/export', '/db', '/sql', '/logs'],
    ];

    public function run(Response $response): array
    {
        $matches = [];

        foreach ($response->document()->directives(DirectiveType::Disallow) as $directive) {
            $category = $this->categorise($directive->value);
            if ($category !== null) {
                $matches[$category][] = $directive;
            }
        }

        if ($matches === []) {
            return [];
        }

        $evidence = [];
        foreach ($matches as $category => $directives) {
            foreach (array_slice($directives, 0, 8) as $directive) {
                $evidence[] = new Evidence(
                    text: "Disallow: {$directive->value}",
                    line: $directive->line,
                    detail: ucfirst($category),
                );
            }
        }

        $categories = implode(', ', array_keys($matches));
        $count = array_sum(array_map('count', $matches));

        return [new Finding(
            id: 'sensitive-paths',
            title: sprintf(
                '%d rule%s name%s paths worth keeping quiet',
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 's' : '',
            ),
            status: Status::Warning,
            summary: "Disallow rules disclose paths relating to {$categories}.",
            impact: 'robots.txt is public and is among the first files an attacker or scanner reads. '
                . 'Listing these paths does not protect them — it advertises them to the readers '
                . 'least likely to honour the rule, while well-behaved crawlers were never the risk.',
            fix: 'Remove these lines and put the locations behind authentication, which is what '
                . 'protects them. Keep them out of search with a "noindex" sent as an X-Robots-Tag '
                . 'header, or as a meta tag on HTML pages. A crawler has to fetch a URL to see its '
                . 'noindex, so that only works once the path is no longer Disallowed. A path that '
                . 'is not secret and only needs to be left uncrawled is a fair use of Disallow.',
            evidence: $evidence,
        )];
    }

    private function categorise(string $path): ?string
    {
        if ($path === '' || $path === '/') {
            return null;
        }

        $normalised = strtolower(rtrim($path, '*$/'));

        foreach (self::PATTERNS as $category => $fragments) {
            foreach ($fragments as $fragment) {
                // Match the path segment, so "/adminxyz" does not read as "/admin".
                if ($normalised === $fragment || str_starts_with($normalised, $fragment . '/')) {
                    return $category;
                }
            }
        }

        return null;
    }
}
