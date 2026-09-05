<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

/**
 * The identity this package presents when making requests.
 *
 * Fetching a site's robots.txt anonymously is rude and often blocked, so a URL
 * parse requires one of these. The product token — the bot's own name — is
 * also what the parser checks its own access against before requesting a page.
 */
final readonly class BotSignature
{
    private function __construct(
        public string $value,
        public string $productToken,
    ) {
    }

    /**
     * Build the conventional form: Mozilla/5.0 (compatible; Bot/1.0; https://…)
     */
    public static function of(string $bot, string $version, string $url): self
    {
        return new self("Mozilla/5.0 (compatible; {$bot}/{$version}; {$url})", $bot);
    }

    /**
     * Use a user-agent string verbatim, recovering the product token from it
     * where the string follows the conventional form.
     */
    public static function raw(string $userAgent): self
    {
        return new self($userAgent, self::productTokenIn($userAgent) ?? $userAgent);
    }

    private static function productTokenIn(string $userAgent): ?string
    {
        if (preg_match('/compatible;\s*([^;\/)]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
