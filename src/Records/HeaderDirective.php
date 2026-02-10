<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Validators\DirectiveValidator;

class HeaderDirective
{
    /**
     * @param array $directives Parsed and validated directives
     */
    public function __construct(
        public readonly array $directives
    ) {
    }

    /**
     * Parse X-Robots-Tag HTTP headers with validation
     *
     * @param array<string> $xRobotsTags
     * @return array
     */
    public static function parseXRobotsTagHeaders(array $xRobotsTags): array
    {
        $validator = new DirectiveValidator();
        $results = [];

        foreach ($xRobotsTags as $tag) {
            // Check for user agent prefix (e.g., "googlebot: noindex, nofollow")
            if (preg_match('/^([a-z\-*]+)\s*:\s*(.+)$/i', $tag, $matches)) {
                $userAgent = strtolower(trim($matches[1]));
                $content = trim($matches[2]);
            } else {
                $userAgent = '*'; // All bots
                $content = trim($tag);
            }

            // Validate directives
            $validated = $validator->validate($content, 'header', $userAgent);

            // Validate user agent
            $userAgentValidation = $validator->validateUserAgent($userAgent);

            $results[] = [
                'user_agent' => $userAgent,
                'user_agent_valid' => $userAgentValidation['valid'],
                'raw' => $tag,
                'directives' => $validated['directives'],
                'valid' => $validated['valid'],
                'issues' => array_merge($validated['issues'], $userAgentValidation['issues']),
                'conflicts' => $validated['conflicts'],
                'redundancies' => $validated['redundancies'],
                'is_full_spec' => $validated['is_full_spec'],
            ];
        }

        return $results;
    }
}
