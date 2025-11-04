<?php

namespace Leopoletto\RobotsTxtParser\Records;

class HeaderDirective
{
    /**
     * @param array<string> $directives
     */
    public function __construct(
        public readonly array $directives
    ) {}

    /**
     * Parse X-Robots-Tag HTTP headers
     * 
     * @param array<string> $xRobotsTags
     * @return ?array<string>
     */
    public static function parseXRobotsTagHeaders(array $xRobotsTags): ?array
    {
        $directives = [];

        foreach ($xRobotsTags as $tag) {
            // Split by comma if multiple directives in one header
            $parts = array_map('trim', explode(',', $tag));

            foreach ($parts as $index => $part) {
                // Check if it has user agent prefix (e.g., "googlebot: noindex")
                if (strpos($part, ':') !== false) {
                    $directiveParts = explode(':', $part, 2);
                    if (count($directiveParts) === 2) {
                        $directives["X-Robots-Tag"][$directiveParts[0]] = trim($directiveParts[1]);
                    }
                } else {
                    $directives["X-Robots-Tag"][$index] = $part;
                }
            }
        }

        return array_unique($directives);
    }
}

