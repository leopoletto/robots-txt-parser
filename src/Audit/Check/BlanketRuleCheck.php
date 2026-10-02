<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\Group;
use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Detects the two extremes: a file that closes the whole site to everyone, and
 * one that restricts nothing at all.
 *
 * "Disallow: /" under "User-agent: *" only closes the site when nothing
 * overrides it. Under RFC 9309 a group naming a crawler replaces the wildcard
 * group outright rather than adding to it, so a file that blocks everything by
 * default and then names the crawlers it wants is an allowlist, not a closed
 * site — a deliberate and increasingly common shape. Reading the wildcard rule
 * on its own reports the opposite of what the file does.
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
            return [$this->defaultDeny($document, $blanket)];
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
     * A wildcard "Disallow: /", read together with whatever overrides it.
     */
    private function defaultDeny(Document $document, Directive $blanket): Finding
    {
        $named = $this->namedGroups($document);
        $exceptions = $this->wildcardAllows($document);

        if ($named === [] && $exceptions === []) {
            return new Finding(
                id: 'blanket-block',
                title: 'The whole site is closed to all crawlers',
                status: Status::Critical,
                summary: '"Disallow: /" applies to "User-agent: *", and nothing overrides it, so no '
                    . 'crawler may fetch any URL.',
                impact: 'Nothing on this domain can be indexed. If this is a production site, it is '
                    . 'invisible in search. This line is most often left behind after copying a '
                    . 'staging configuration.',
                fix: 'If the site should be indexed, remove "Disallow: /" from the "User-agent: *" '
                    . 'group. Note that robots.txt does not remove already-indexed URLs — it stops '
                    . 'them being re-crawled. Use a "noindex" meta tag on pages that must be dropped.',
                evidence: [new Evidence('Disallow: /', $blanket->line, 'Applies to User-agent: *')],
            );
        }

        return new Finding(
            id: 'blanket-allowlist',
            title: 'The file blocks everything by default',
            status: Status::Notice,
            summary: $this->overrideSummary($named, $exceptions),
            impact: 'Any crawler this file does not name is refused every URL. A crawler that is '
                . 'named ignores the wildcard group entirely and follows only its own rules, so the '
                . 'default block does not apply to it.',
            fix: 'To let a new crawler in, give it its own group — adding an Allow to the '
                . '"User-agent: *" group will not reach it, because a named group replaces the '
                . 'wildcard rather than extending it.',
            evidence: array_merge(
                [new Evidence('Disallow: /', $blanket->line, 'Applies to every crawler not named elsewhere')],
                array_map(
                    static fn (Directive $allow): Evidence => new Evidence(
                        "Allow: {$allow->value}",
                        $allow->line,
                        'Opens this path to crawlers not named elsewhere',
                    ),
                    array_slice($exceptions, 0, 5),
                ),
            ),
        );
    }

    /**
     * @param list<Group>     $named
     * @param list<Directive> $exceptions
     */
    private function overrideSummary(array $named, array $exceptions): string
    {
        $parts = [];

        if ($named !== []) {
            $parts[] = sprintf(
                '%d group%s name%s crawlers that are exempt from it',
                count($named),
                count($named) === 1 ? '' : 's',
                count($named) === 1 ? 's' : '',
            );
        }

        if ($exceptions !== []) {
            $parts[] = sprintf(
                '%d Allow rule%s open%s specific paths',
                count($exceptions),
                count($exceptions) === 1 ? '' : 's',
                count($exceptions) === 1 ? 's' : '',
            );
        }

        return '"Disallow: /" applies to "User-agent: *", but ' . implode(' and ', $parts) . '.';
    }

    /**
     * Groups naming a specific crawler, which replace the wildcard group for it.
     *
     * @return list<Group>
     */
    private function namedGroups(Document $document): array
    {
        return array_values(array_filter(
            $document->groups(),
            static fn (Group $group): bool => ! $group->isWildcard(),
        ));
    }

    /**
     * Allow rules inside the wildcard group, which carve paths out of the block.
     *
     * @return list<Directive>
     */
    private function wildcardAllows(Document $document): array
    {
        $allows = [];

        foreach ($document->groups() as $group) {
            if (! $group->isWildcard()) {
                continue;
            }

            foreach ($group->directives(DirectiveType::Allow) as $allow) {
                if ($allow->value !== '') {
                    $allows[] = $allow;
                }
            }
        }

        return $allows;
    }

    /**
     * @param list<Directive> $disallows
     */
    private function blanketBlock(array $disallows): ?Directive
    {
        foreach ($disallows as $directive) {
            if ($directive->value === '/' && in_array('*', $directive->userAgents(), true)) {
                return $directive;
            }
        }

        return null;
    }

    /**
     * @param list<Directive> $disallows
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
