<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

/**
 * Splits a robots.txt line into `field: value` plus any trailing comment.
 *
 * Comments run from an unquoted `#` to end of line and may follow a directive
 * on the same line (RFC 9309 §2.2), so they are stripped here rather than
 * leaking into every parser.
 */
final class Tokenizer
{
    public static function tokenize(int $number, string $raw): Token
    {
        $line = $raw;

        // Strip a UTF-8 BOM on the very first line; some editors add one and it
        // would otherwise corrupt the first field name.
        if ($number === 1 && str_starts_with($line, "\u{FEFF}")) {
            $line = substr($line, 3);
        }

        $comment = null;
        $hash = strpos($line, '#');
        if ($hash !== false) {
            $comment = trim(substr($line, $hash + 1));
            $line = substr($line, 0, $hash);
        }

        $line = trim($line);
        if ($line === '') {
            return new Token($number, $raw, null, '', $comment);
        }

        $colon = strpos($line, ':');
        if ($colon === false) {
            // No separator: keep the text as a value so callers can report it.
            return new Token($number, $raw, null, $line, $comment);
        }

        return new Token(
            number: $number,
            raw: $raw,
            field: strtolower(trim(substr($line, 0, $colon))),
            value: trim(substr($line, $colon + 1)),
            comment: $comment,
        );
    }
}
