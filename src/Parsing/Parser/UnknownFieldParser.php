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
        'host' => 'a Yandex extension naming the preferred mirror',
        'clean-param' => 'a Yandex extension listing ignorable query parameters',
        'request-rate' => 'a legacy rate limit, honoured by few crawlers',
        'visit-time' => 'a legacy crawl window, honoured by few crawlers',
        'content-signal' => "Cloudflare's AI usage policy signal",
    ];

    /**
     * Fields a crawler recognises the shape of but no longer acts on.
     *
     * These are worse than a typo: the line looks deliberate and is read by
     * humans as protection, while doing nothing at all. They are recorded so
     * an audit can say so, rather than dropped like an unknown field.
     */
    private const INEFFECTIVE_FIELDS = [
        'noindex' => 'Google removed support for "Noindex" in robots.txt in September 2019; '
            . 'no crawler honours it',
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

        if (isset(self::INEFFECTIVE_FIELDS[$token->field])) {
            return [new Issue(
                $token->number,
                ucfirst($token->field) . ' has no effect: ' . self::INEFFECTIVE_FIELDS[$token->field],
                Severity::Medium,
                'ineffective_directive',
            )];
        }

        if (isset(self::TOLERATED_FIELDS[$token->field])) {
            // Recorded rather than dropped: a reader should be able to account
            // for every line, and "no crawler I target reads this" is worth
            // knowing even when it is harmless.
            return [new Issue(
                $token->number,
                ucfirst($token->field) . ' is outside the standard — '
                    . self::TOLERATED_FIELDS[$token->field],
                Severity::Low,
                'nonstandard_directive',
            )];
        }

        return [new Issue(
            $token->number,
            "Unknown directive '{$token->field}' — crawlers will ignore this line",
            Severity::Medium,
            'unknown_directive',
        )];
    }
}
