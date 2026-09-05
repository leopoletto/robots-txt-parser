<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Extraction;

use Leopoletto\RobotsTxtParser\Record\HeaderDirective;
use Leopoletto\RobotsTxtParser\Validation\DirectiveValidator;

/**
 * Turns raw `X-Robots-Tag` header values into records.
 *
 * A header may target a crawler by prefixing it: "googlebot: noindex, nofollow".
 * Distinguishing that prefix from a parametric directive ("max-snippet:-1")
 * is the only subtlety here.
 */
final class HeaderExtractor
{
    public function __construct(private readonly DirectiveValidator $validator = new DirectiveValidator())
    {
    }

    /**
     * @param list<string> $values
     * @return list<HeaderDirective>
     */
    public function extract(array $values, string $origin = 'robots.txt'): array
    {
        $records = [];

        foreach ($values as $value) {
            [$userAgent, $content] = $this->split(trim($value));

            $validation = $this->validator->validate($content);
            $agentCheck = $this->validator->validateUserAgent($userAgent);

            $records[] = new HeaderDirective(
                userAgent: $userAgent,
                userAgentKnown: $agentCheck['known'],
                raw: $value,
                validation: $validation->withIssues($agentCheck['issues']),
                origin: $origin,
            );
        }

        return $records;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $value): array
    {
        // A leading "name:" is a user-agent target only when the name is not
        // itself a directive — "max-snippet:-1" must not be read as a target.
        if (preg_match('/^([a-z][a-z0-9._*-]*)\s*:\s*(.+)$/i', $value, $matches) !== 1) {
            return ['*', $value];
        }

        $candidate = strtolower($matches[1]);

        // A prefix that names a directive is a directive, not a target.
        if ($this->validator->isKnownDirective($candidate)) {
            return ['*', $value];
        }

        return [$candidate, trim($matches[2])];
    }
}
