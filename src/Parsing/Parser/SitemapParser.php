<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing\Parser;

use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;
use Leopoletto\RobotsTxtParser\Record\Sitemap;

/**
 * Records `Sitemap:` lines. These are document-global — they belong to no
 * user-agent group (RFC 9309 §2.2.4).
 */
final class SitemapParser implements LineParser
{
    public function supports(Token $token): bool
    {
        return $token->fieldIs('sitemap');
    }

    public function parse(Token $token, ParseContext $context): array
    {
        if ($token->value === '') {
            return [];
        }

        return [new Sitemap($token->number, $token->value, self::isUsable($token->value))];
    }

    /**
     * A sitemap reference has to be an absolute HTTP(S) URL. The `.xml`
     * extension is conventional rather than required, so a sitemap index or an
     * extensionless endpoint still counts as usable.
     */
    private static function isUsable(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
