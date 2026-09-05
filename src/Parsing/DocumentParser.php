<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

use Leopoletto\RobotsTxtParser\Contract\AgentRepository;
use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Contract\Source;
use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Parsing\Parser\CommentParser;
use Leopoletto\RobotsTxtParser\Parsing\Parser\DirectiveParser;
use Leopoletto\RobotsTxtParser\Parsing\Parser\SitemapParser;
use Leopoletto\RobotsTxtParser\Parsing\Parser\UnknownFieldParser;
use Leopoletto\RobotsTxtParser\Parsing\Parser\UserAgentParser;
use Leopoletto\RobotsTxtParser\Record\Issue;

/**
 * Reads a Source and produces the records and groups it contains.
 *
 * This is the single parsing loop in the package. Every entry point — URL,
 * file, string — reaches it through a Source, so all three behave identically:
 * same line numbering, same grouping, same issue reporting.
 */
final class DocumentParser
{
    /** @var list<LineParser> */
    private readonly array $parsers;

    /**
     * @param list<LineParser>|null $parsers Override the default parser chain.
     *                                       The catch-all must come last.
     */
    public function __construct(
        private readonly AgentRepository $agents,
        ?array $parsers = null,
    ) {
        $this->parsers = $parsers ?? [
            new CommentParser(),
            new SitemapParser(),
            new UserAgentParser(),
            new DirectiveParser(),
            new UnknownFieldParser(),
        ];
    }

    public function parse(Source $source, ?ContentBuffer $buffer = null): Document
    {
        $context = new ParseContext($this->agents);
        $records = [];

        foreach ($source->lines() as $number => $raw) {
            $buffer?->append($raw);

            $token = Tokenizer::tokenize($number, $raw);
            if ($token->isBlank() && $token->comment === null) {
                continue;
            }

            foreach ($this->parsers as $parser) {
                if (! $parser->supports($token)) {
                    continue;
                }

                foreach ($parser->parse($token, $context) as $record) {
                    $records[] = $record;
                }

                break;
            }
        }

        if ($source->truncated()) {
            $records[] = new Issue(
                0,
                'Content was truncated at the configured size limit; rules beyond that point were not read',
                Severity::High,
                'truncated',
            );
        }

        return new Document($records, $context->groups(), $source->bytesRead(), $source->truncated());
    }

    /**
     * Append records produced outside the document body — X-Robots-Tag headers
     * and HTML meta tags — to an already-parsed document.
     *
     * @param list<Record> $records
     */
    public function withExtraRecords(Document $document, array $records): Document
    {
        return $document->withRecords($records);
    }
}
