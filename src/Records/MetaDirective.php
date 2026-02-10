<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Leopoletto\RobotsTxtParser\Validators\DirectiveValidator;

class MetaDirective
{
    /**
     * @param array $directives Parsed and validated directives
     */
    public function __construct(
        public readonly array $directives
    ) {
    }

    /**
     * Parse robots meta tags from HTML with validation
     *
     * @return array
     */
    public static function parseMetaTags(string $html): array
    {
        $validator = new DirectiveValidator();
        $results = [];

        // Match <meta name="robots|googlebot|googlebot-news" content="...">
        $pattern = '/<meta\s+(?:name=["\'](robots|googlebot|googlebot-news|bingbot)["\']\s+content=["\']([^"\']+)["\']|content=["\']([^"\']+)["\']\s+name=["\'](robots|googlebot|googlebot-news|bingbot)["\'])\s*\/?>/i';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Determine which attribute order was matched
                if (!empty($match[2])) {
                    // name first, content second
                    $name = strtolower(trim($match[1]));
                    $content = trim($match[2]);
                } elseif (!empty($match[3])) {
                    // content first, name second
                    $content = trim($match[3]);
                    $name = strtolower(trim($match[4]));
                } else {
                    continue;
                }

                // Validate directives
                $validated = $validator->validate($content, 'meta', $name);

                $results[] = [
                    'tag_name' => $name,
                    'raw' => $content,
                    'directives' => $validated['directives'],
                    'valid' => $validated['valid'],
                    'issues' => $validated['issues'],
                    'conflicts' => $validated['conflicts'],
                    'redundancies' => $validated['redundancies'],
                    'is_full_spec' => $validated['is_full_spec'],
                ];
            }
        }

        return $results;
    }
}
