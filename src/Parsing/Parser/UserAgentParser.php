<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing\Parser;

use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\Record\UserAgent;

/**
 * Records `User-agent:` lines, enriching each with dataset metadata when the
 * declared token is a crawler we know about.
 */
final class UserAgentParser implements LineParser
{
    public function supports(Token $token): bool
    {
        return $token->fieldIs('user-agent') || $token->fieldIs('useragent');
    }

    public function parse(Token $token, ParseContext $context): array
    {
        if ($token->value === '') {
            return [new Issue($token->number, 'User-agent line declares no product token')];
        }

        // The declared casing is preserved: it is what the document says, and
        // an analysis UI should echo it back verbatim.
        $userAgent = new UserAgent(
            line: $token->number,
            token: $token->value,
            agent: $context->agents->find($token->value),
        );

        $context->declareUserAgent($userAgent);

        return [$userAgent];
    }
}
