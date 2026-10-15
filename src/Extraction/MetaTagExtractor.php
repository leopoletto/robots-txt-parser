<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Extraction;

use Leopoletto\RobotsTxtParser\Record\MetaDirective;
use Leopoletto\RobotsTxtParser\Validation\DirectiveValidator;

/**
 * Finds robots meta tags in an HTML document.
 *
 * Attribute order and quoting vary in the wild, so the tag is located first and
 * its attributes read individually rather than matched as one fixed sequence.
 */
final class MetaTagExtractor
{
    /** Meta tag names that carry indexing directives. */
    private const ROBOTS_TAG_NAMES = [
        'robots',
        'googlebot',
        'googlebot-news',
        'bingbot',
        'slurp',
        'yandex',
        'msnbot',
        'ai',
        'aibot',
    ];

    public function __construct(private readonly DirectiveValidator $validator = new DirectiveValidator())
    {
    }

    /**
     * @return list<MetaDirective>
     */
    public function extract(string $html): array
    {
        $html = self::withoutDeadMarkup($html);

        // Directives live in <head>; stopping at </head> avoids scanning a body
        // that may be megabytes of markup.
        $head = stripos($html, '</head>');
        if ($head !== false) {
            $html = substr($html, 0, $head);
        }

        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags) === false) {
            return [];
        }

        $records = [];

        foreach ($tags[0] as $tag) {
            $name = strtolower((string) $this->attribute($tag, 'name'));
            if (! in_array($name, self::ROBOTS_TAG_NAMES, true)) {
                continue;
            }

            $content = $this->attribute($tag, 'content');
            if ($content === null) {
                continue;
            }

            $records[] = new MetaDirective(
                name: $name,
                raw: $content,
                validation: $this->validator->validate($content),
            );
        }

        return $records;
    }

    /**
     * Whether the HTML stops partway through a <meta> tag, as when a read is
     * cut short: a tag split there can be neither read nor ruled out.
     */
    public function endsInsideTag(string $html): bool
    {
        return preg_match('/<meta\b[^>]*$/i', self::withoutDeadMarkup($html)) === 1;
    }

    /**
     * Remove what a browser never applies as a tag: comments, and the content
     * of <script> and <noscript>. A commented-out noindex is not a noindex.
     * One left open at the end of the buffer runs to the end.
     */
    private static function withoutDeadMarkup(string $html): string
    {
        return (string) preg_replace(
            [
                '/<!--.*?(?:-->|$)/s',
                '/<script\b[^>]*>.*?(?:<\/script\s*>|$)/is',
                '/<noscript\b[^>]*>.*?(?:<\/noscript\s*>|$)/is',
            ],
            '',
            $html,
        );
    }

    private function attribute(string $tag, string $attribute): ?string
    {
        $pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';

        if (preg_match($pattern, $tag, $matches) !== 1) {
            return null;
        }

        // Groups 2, 3 and 4 are the double-quoted, single-quoted and bare
        // forms; exactly one of them participated in the match.
        foreach ([2, 3, 4] as $group) {
            if (isset($matches[$group]) && $matches[$group] !== '') {
                return trim(html_entity_decode($matches[$group], ENT_QUOTES | ENT_HTML5));
            }
        }

        return '';
    }
}
