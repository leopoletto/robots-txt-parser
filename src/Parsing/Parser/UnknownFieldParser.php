<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing\Parser;

use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;
use Leopoletto\RobotsTxtParser\Record\Issue;

/**
 * Catch-all for lines no other parser claimed.
 *
 * Registered last, so it only ever sees leftovers. Non-standard but
 * widely-published fields are tolerated quietly; anything else is reported,
 * since a misspelled `Dissalow:` is silently ignored by real crawlers and is
 * exactly the mistake this package exists to surface.
 */
final class UnknownFieldParser implements LineParser
{
    /**
     * Fields outside RFC 9309 that are nonetheless understood by at least one
     * major crawler, and so are not worth flagging.
     */
    private const TOLERATED_FIELDS = [
        'host',           // Yandex: preferred mirror
        'clean-param',    // Yandex: ignorable query parameters
        'request-rate',   // legacy rate limiting
        'visit-time',     // legacy crawl window
        'noindex',        // historic Google extension
        'content-signal', // Cloudflare: ai-train / search / ai-input policy
    ];

    public function supports(Token $token): bool
    {
        return ! $token->isBlank();
    }

    public function parse(Token $token, ParseContext $context): array
    {
        if ($token->field === null) {
            return [new Issue(
                $token->number,
                "Line is missing a ':' separator: '{$token->value}'",
                Severity::High,
                'malformed_line',
            )];
        }

        if (in_array($token->field, self::TOLERATED_FIELDS, true)) {
            return [];
        }

        return [new Issue(
            $token->number,
            "Unknown directive '{$token->field}' — crawlers will ignore this line",
            Severity::Medium,
            'unknown_directive',
        )];
    }
}
