<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing\Parser;

use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Record\Issue;

/**
 * Records `Allow:`, `Disallow:` and `Crawl-delay:` lines against the group
 * currently being built.
 */
final class DirectiveParser implements LineParser
{
    public function supports(Token $token): bool
    {
        return $token->field !== null && DirectiveType::tryFromField($token->field) !== null;
    }

    public function parse(Token $token, ParseContext $context): array
    {
        $type = DirectiveType::tryFromField((string) $token->field);
        if ($type === null) {
            return [];
        }

        $group = $context->openGroup();
        if ($group === null) {
            return [new Issue(
                $token->number,
                "'{$token->field}' appears before any User-agent line and applies to nothing",
                Severity::High,
                'orphan_directive',
            )];
        }

        $records = [];

        if ($type === DirectiveType::CrawlDelay && ! is_numeric($token->value)) {
            $records[] = new Issue(
                $token->number,
                "Crawl-delay value '{$token->value}' is not a number",
                Severity::Medium,
                'invalid_value',
            );
        }

        if ($type->isPathRule() && $token->value !== '' && ! str_starts_with($token->value, '/')) {
            $records[] = new Issue(
                $token->number,
                "Path '{$token->value}' should start with '/'",
                Severity::Medium,
                'invalid_path',
            );
        }

        $directive = new Directive($token->number, $type, $token->value, $group);
        $group->addDirective($directive);

        $records[] = $directive;

        return $records;
    }
}
