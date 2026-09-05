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
 * Detects the two extremes: a file that closes the whole site to everyone, and
 * one that restricts nothing at all.
 *
 * "Disallow: /" under "User-agent: *" is the single most expensive line a
 * robots.txt can contain, and the most common way it appears in production is
 * by being copied from a staging environment.
 */
final class BlanketRuleCheck implements AuditCheck
{
    public function run(Response $response): array
    {
        $document = $response->document();

        if ($document->groups() === []) {
            return [new Finding(
                id: 'blanket-empty',
                title: 'No crawl rules are declared',
                status: Status::Notice,
                summary: 'The file declares no User-agent groups, so every crawler may fetch every URL.',
                impact: 'Nothing is restricted. That is a valid and common configuration, but it also '
                    . 'means no crawl budget is being steered away from low-value pages.',
                fix: 'If some sections should not be crawled — faceted search, internal endpoints, '
                    . 'duplicate parameter URLs — declare them with Disallow rules.',
            )];
        }

        $blanket = $this->blanketBlock($document->directives(DirectiveType::Disallow));

        if ($blanket !== null) {
            return [new Finding(
                id: 'blanket-block',
                title: 'The whole site is closed to all crawlers',
                status: Status::Critical,
                summary: '"Disallow: /" applies to "User-agent: *", so no crawler may fetch any URL.',
                impact: 'Nothing on this domain can be indexed. If this is a production site, it is '
                    . 'invisible in search. This line is most often left behind after copying a '
                    . 'staging configuration.',
                fix: 'If the site should be indexed, remove "Disallow: /" from the "User-agent: *" '
                    . 'group. Note that robots.txt does not remove already-indexed URLs — it stops '
                    . 'them being re-crawled. Use a "noindex" meta tag on pages that must be dropped.',
                evidence: [new Evidence('Disallow: /', $blanket->line, 'Applies to User-agent: *')],
            )];
        }

        if ($this->hasNoRestrictions($document->directives(DirectiveType::Disallow))) {
            return [new Finding(
                id: 'blanket-open',
                title: 'Every URL is open to every crawler',
                status: Status::Notice,
                summary: 'The file declares user agents but no effective Disallow rule.',
                impact: 'Every URL is crawlable, including any that exist only for internal use. '
                    . 'Crawl budget is spent evenly rather than on pages worth ranking.',
                fix: 'Consider disallowing sections with no search value — internal search results, '
                    . 'faceted or parameterised URLs, cart and account pages.',
            )];
        }

        return [];
    }

    /**
     * @param list<\Leopoletto\RobotsTxtParser\Record\Directive> $disallows
     */
    private function blanketBlock(array $disallows): ?\Leopoletto\RobotsTxtParser\Record\Directive
    {
        foreach ($disallows as $directive) {
            if ($directive->value === '/' && in_array('*', $directive->userAgents(), true)) {
                return $directive;
            }
        }

        return null;
    }

    /**
     * @param list<\Leopoletto\RobotsTxtParser\Record\Directive> $disallows
     */
    private function hasNoRestrictions(array $disallows): bool
    {
        foreach ($disallows as $directive) {
            // An empty Disallow forbids nothing, so it does not count.
            if ($directive->value !== '') {
                return false;
            }
        }

        return true;
    }
}
