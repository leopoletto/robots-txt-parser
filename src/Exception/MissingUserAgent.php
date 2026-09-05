<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Exception;

/**
 * A network parse was attempted without identifying the requesting bot.
 */
final class MissingUserAgent extends RobotsTxtParserException
{
    public static function make(): self
    {
        return new self(
            'No request user-agent configured. Call withUserAgent() or withBotSignature() before parsing a URL.'
        );
    }
}
