<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\CrawlerDirectory;
use Leopoletto\RobotsTxtParser\Audit\CrawlerVerdict;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Finds rules that cannot match any coherent intent, by comparing one
 * operator's crawlers against each other.
 *
 * Blocking a crawler is a policy choice, so it is never evidence of a mistake
 * on its own. Blocking *one* of an operator's crawlers while allowing another
 * is different, because the two carry opposite consequences and only one
 * ordering makes sense.
 *
 * OpenAI's GPTBot collects training data; its ChatGPT-User fetches a page
 * because a person asked ChatGPT to open it. A site that blocks GPTBot and
 * allows ChatGPT-User has stated a coherent position: no bulk collection,
 * but people may still visit. The reverse — ChatGPT-User blocked, GPTBot
 * allowed — donates the content in bulk while refusing the human reader. No
 * one chooses that. It happens when a rule aimed at "the AI crawlers" catches
 * a user agent nobody looked up, which is exactly the kind of error a report
 * should catch, and the only kind it can prove.
 */
final class CrawlerCoherenceCheck implements AuditCheck
{
    /** Groups that collect content, against which a user fetch is compared. */
    private const COLLECTING_GROUPS = ['ai_training', 'ai_search'];

    public function __construct(private readonly CrawlerDirectory $directory = new CrawlerDirectory())
    {
    }

    public function run(Response $response): array
    {
        $document = $response->document();
        $conflicts = [];

        foreach ($this->directory->crawlers('ai_user') as $agent => $purpose) {
            $decision = $document->decide($agent, '/');
            if ($decision->allowed) {
                continue;
            }

            $operator = $this->directory->operator($agent);
            $collecting = $this->allowedCollectors($response, $operator);

            if ($collecting === []) {
                continue;
            }

            $conflicts[] = [
                'blocked' => new CrawlerVerdict(
                    agent: $agent,
                    operator: $operator,
                    purpose: $purpose,
                    allowed: false,
                    rule: $decision->rule !== null
                        ? "{$decision->rule->type->label()}: {$decision->rule->value}"
                        : null,
                    line: $decision->rule?->line,
                ),
                'allowed' => $collecting,
            ];
        }

        if ($conflicts === []) {
            return [];
        }

        return [$this->finding($conflicts)];
    }

    /**
     * The operator's content-collecting crawlers that this file lets through.
     *
     * @return list<CrawlerVerdict>
     */
    private function allowedCollectors(Response $response, string $operator): array
    {
        if ($operator === '') {
            return [];
        }

        $document = $response->document();
        $allowed = [];

        foreach (self::COLLECTING_GROUPS as $group) {
            foreach ($this->directory->crawlers($group) as $agent => $purpose) {
                if ($this->directory->operator($agent) !== $operator) {
                    continue;
                }

                $decision = $document->decide($agent, '/');
                if (! $decision->allowed) {
                    continue;
                }

                $allowed[] = new CrawlerVerdict(
                    agent: $agent,
                    operator: $operator,
                    purpose: $purpose,
                    allowed: true,
                    rule: $decision->rule !== null
                        ? "{$decision->rule->type->label()}: {$decision->rule->value}"
                        : null,
                    line: $decision->rule?->line,
                );
            }
        }

        return $allowed;
    }

    /**
     * @param list<array{blocked: CrawlerVerdict, allowed: list<CrawlerVerdict>}> $conflicts
     */
    private function finding(array $conflicts): Finding
    {
        $pairs = [];
        $crawlers = [];

        foreach ($conflicts as $conflict) {
            $names = array_map(static fn (CrawlerVerdict $c): string => $c->agent, $conflict['allowed']);
            $pairs[] = "{$conflict['blocked']->agent} is blocked while " . implode(' and ', $names) . ' may crawl';

            $crawlers[] = $conflict['blocked'];
            foreach ($conflict['allowed'] as $verdict) {
                $crawlers[] = $verdict;
            }
        }

        $count = count($conflicts);

        return new Finding(
            id: 'crawler-coherence',
            title: sprintf(
                '%d user-triggered fetch%s %s blocked while the same operator may still collect content',
                $count,
                $count === 1 ? '' : 'es',
                $count === 1 ? 'is' : 'are',
            ),
            status: Status::Warning,
            summary: implode('; ', $pairs) . '.',
            impact: 'This is the opposite of what either intent would produce. Blocking bulk '
                . 'collection while allowing people through is a common position; allowing bulk '
                . 'collection while turning away someone who asked for the page is not one anyone '
                . 'sets out to take. It usually means a rule aimed at AI crawlers named the wrong '
                . 'user agent.',
            fix: 'Decide which way round it should be. To keep people out but content in, no change '
                . 'is needed — but that is rarely the intent. To block collection instead, disallow '
                . 'the collecting agents named here and allow the user-triggered ones.',
            intent: 'If your intention is to block AI entirely, this configuration does not achieve '
                . 'it: the content is still being collected.',
            crawlers: $crawlers,
        );
    }
}
