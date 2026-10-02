<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;

/**
 * Turns one tokenized line into zero or more records.
 *
 * Parsers are consulted in registration order; the first that supports a token
 * handles it. Adding a directive to the package means adding a parser here,
 * not editing a conditional chain.
 */
interface LineParser
{
    public function supports(Token $token): bool;

    /**
     * @return list<Record>
     */
    public function parse(Token $token, ParseContext $context): array;
}
