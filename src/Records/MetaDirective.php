<?php

namespace Leopoletto\RobotsTxtParser\Records;

class MetaDirective
{
    /**
     * @param array<string> $directives
     */
    public function __construct(
        public readonly array $directives
    ) {}

    /**
     * Parse robots meta tags from HTML
     * Supports both attribute orders and self-closing tags
     * 
     * @return array<string>
     */
    public static function parseMetaTags(string $html): array
    {
        $directives = [];

        // Match <meta name="robots|googlebot|googlebot-news" content="..."> or <meta content="..." name="...">
        // Handles both attribute orders and self-closing tags
        $pattern = '/<meta\s+(?:name=["\'](robots|googlebot|googlebot-news)["\']\s+content=["\']([^"\']+)["\']|content=["\']([^"\']+)["\']\s+name=["\'](robots|googlebot|googlebot-news)["\'])\s*\/?>/i';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Check which attribute order was matched
                if (!empty($match[2])) {
                    // name first, content second
                    $content = trim($match[2]);
                } elseif (!empty($match[3])) {
                    // content first, name second
                    $content = trim($match[3]);
                } else {
                    continue;
                }

                // Split by comma if multiple directives
                $parts = array_map('trim', explode(',', $content));
                $directives = array_merge($directives, $parts);
            }
        }

        return array_unique($directives);
    }
}

