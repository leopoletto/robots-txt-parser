<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing\Parser;

use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;
use Leopoletto\RobotsTxtParser\Record\Comment;

/**
 * Records whole-line comments. Trailing comments on a directive line are left
 * to the directive's own parser, which keeps them attached to their rule.
 */
final class CommentParser implements LineParser
{
    public function supports(Token $token): bool
    {
        return $token->comment !== null && $token->isBlank();
    }

    public function parse(Token $token, ParseContext $context): array
    {
        if ($token->comment === '') {
            return [];
        }

        return [new Comment($token->number, (string) $token->comment)];
    }
}
