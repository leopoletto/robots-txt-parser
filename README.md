# Robots.txt Parser

[![Tests](https://github.com/leopoletto/robots-txt-parser/actions/workflows/run-tests-phpunit.yml/badge.svg)](https://github.com/leopoletto/robots-txt-parser/actions/workflows/run-tests-phpunit.yml)
[![Latest Version](https://img.shields.io/packagist/v/leopoletto/robots-txt-parser.svg)](https://packagist.org/packages/leopoletto/robots-txt-parser)
[![License](https://img.shields.io/packagist/l/leopoletto/robots-txt-parser.svg)](LICENSE.md)

A PHP library for parsing and analysing `robots.txt` — from a URL, a file, or a string — including
the robots `<meta>` tags and `X-Robots-Tag` headers that govern indexing alongside it.

Built for analysis rather than crawling: it reports what a document *says*, what is *wrong* with it,
and *why* a given rule applies to a given URL.

```php
use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$response = (new RobotsTxtParser())
    ->withBotSignature('MyBot', '1.0', 'https://example.com/bot')
    ->parseUrl('https://example.com/products/widget');

$response->isAllowed('GPTBot', '/products/widget');   // false
$response->records()->userAgents();                    // list<UserAgent>
$response->records()->issues();                        // list<Issue>
```

## Installation

```bash
composer require leopoletto/robots-txt-parser
```

Requires PHP 8.2+. The only runtime dependencies are Guzzle and PSR-7 — no framework.

## Parsing

The three entry points behave identically. The same document produces the same records, the same
line numbers and the same issues whether it was fetched, uploaded or pasted.

```php
$parser = new RobotsTxtParser();

$parser->parseText($contents);
$parser->parseFile('/path/to/robots.txt');

$parser->withBotSignature('MyBot', '1.0', 'https://example.com/bot')
       ->parseUrl('https://example.com/some/page');
```

### Identifying your bot

`parseUrl()` makes real HTTP requests, so it requires an identity:

```php
$parser->withBotSignature('MyBot', '1.0', 'https://example.com/bot');
// Mozilla/5.0 (compatible; MyBot/1.0; https://example.com/bot)

$parser->withUserAgent('MyBot/1.0 (+https://example.com/bot)');
```

The product token (`MyBot`) is also what the parser checks its *own* access against — see
[Fetching behaviour](#fetching-behaviour).

### Keeping the original document

```php
$response = $parser->keepContent()->parseText($contents);
$response->content(); // the document, with line endings normalised to \n
```

## Reading a document

`$response->records()` returns a `Document`. Every method on it is pure — nothing carries state
between calls.

```php
$document = $response->records();

$document->userAgents();        // list<UserAgent>
$document->groups();            // list<Group>
$document->sitemaps();          // list<Sitemap>
$document->comments();          // list<Comment>
$document->issues();            // list<Issue>
$document->headerDirectives();  // list<HeaderDirective>
$document->metaDirectives();    // list<MetaDirective>

$document->allowed();           // every Allow rule
$document->disallowed('GPTBot');// Disallow rules governing GPTBot
$document->crawlDelay('GPTBot');

$document->toArray();           // JSON-ready summary of everything
```

Every record exposes `line()` and `toArray()`, so rendering a document is uniform.

### Groups

A group is one or more consecutive `User-agent:` lines plus the directives that follow, per
[RFC 9309 §2.2.1](https://www.rfc-editor.org/rfc/rfc9309.html#section-2.2.1).

```
User-agent: GPTBot
User-agent: ChatGPT-User
Disallow: /admin
```

Both agents share the one rule, and the parser models that directly:

```php
$group = $document->groupFor('ChatGPT-User');
$group->tokens();            // ['GPTBot', 'ChatGPT-User']
$group->directives();        // the rules governing both
```

Comments and sitemaps between `User-agent:` lines do not break the run. A directive does.

## Checking access

```php
$document->isAllowed('GPTBot', '/admin/reports');   // false

$decision = $document->decide('GPTBot', '/admin/reports');
$decision->allowed;     // false
$decision->rule->line;  // 4 — the rule that decided it
$decision->rule->value; // '/admin'
$decision->byDefault(); // false; true when no rule matched
```

Resolution follows [RFC 9309 §2.2.2](https://www.rfc-editor.org/rfc/rfc9309.html#section-2.2.2):

- Groups naming the agent apply; **all** of them, if the document declares it more than once.
- Only when no group names the agent does the `*` group apply.
- The longest matching pattern wins.
- At equal length the least restrictive rule wins — `Allow` beats `Disallow`.
- `Disallow:` with an empty value forbids nothing.
- Matching is case-insensitive on the agent, case-sensitive on the path.

Patterns support `*` (any run of characters) and a trailing `$` (end anchor). Everything else is
literal, including a `$` that is not the final character.

### Explaining a rule

```php
$explanation = $document->disallowed('*')[0]->explanation();

$explanation->specificity;  // 6
$explanation->wildcards;    // 0
$explanation->endAnchor;    // false
$explanation->pathToMatch;  // 'Prefix match: any URL whose path starts with "/admin"…'
```

Explanations are built on first access, so a document with thousands of rules costs nothing unless
something reads them. The structural fields are there for callers who want to write their own
wording or translate it.

## Issues

Malformed and ineffective lines are reported rather than silently dropped — a misspelled
`Dissalow:` is ignored by every real crawler, which is exactly the mistake worth surfacing.

```php
foreach ($document->issues() as $issue) {
    $issue->line;             // 12
    $issue->type;             // 'unknown_directive'
    $issue->severity;         // Severity::Medium
    $issue->message;
}
```

| Type | Meaning |
| --- | --- |
| `unknown_directive` | A field no crawler will act on, usually a typo |
| `orphan_directive` | A rule declared before any `User-agent:` |
| `malformed_line` | A line with no `:` separator |
| `invalid_path` | An `Allow`/`Disallow` value not starting with `/` |
| `invalid_value` | A non-numeric `Crawl-delay` |
| `truncated` | The document exceeded the size limit |
| `page_disallowed` | The page was not fetched because robots.txt forbade it |
| `fetch_failed` | The robots.txt could not be retrieved |
| `too_many_redirects` | The redirect chain exceeded the limit |

Non-standard but widely published fields (`Host`, `Clean-param`, `Request-rate`, `Visit-time`) are
accepted without complaint.

## Meta tags and X-Robots-Tag

`parseUrl()` also collects the indexing directives that live outside robots.txt.

```php
foreach ($document->metaDirectives() as $meta) {
    $meta->name;                    // 'robots'
    $meta->validation->has('noindex');
    $meta->validation->conflicts;   // e.g. index + noindex
}

foreach ($document->headerDirectives() as $header) {
    $header->userAgent;   // '*' or 'googlebot'
    $header->origin;      // 'robots.txt' or 'page'
}
```

Both sources combine into one answer, resolving toward the more restrictive reading:

```php
$rules = $document->effectiveRules();
$rules->indexable();   // false if anything said noindex
$rules->followable();
$rules->toArray();     // full effective_rules + source counts
```

## Fetching behaviour

Two rules govern `parseUrl()`, both deliberate:

**The robots.txt comes from the origin; the meta tags come from your URL.** robots.txt is
per-origin, so it is always fetched from `scheme://host[:port]/robots.txt` — the port included.
Meta tags and `X-Robots-Tag` are per-page, so those are read from the exact URL you passed, never
the home page.

**If robots.txt disallows your URL, the page is not fetched.** A tool that reports on robots.txt has
no business ignoring one. When this happens you get a `page_disallowed` issue and:

```php
$response->pageInspected();          // false
$response->pageDecision()->allowed;  // false
$response->pageDecision()->rule;     // the rule that blocked it
```

### HTTP metadata

```php
$response->robotsUrl();      // https://example.com/robots.txt
$response->requestedUrl();   // https://example.com/products/widget
$response->finalUrl();       // after redirects
$response->redirects();
$response->statusCode();     // the robots.txt response
$response->pageStatusCode(); // the page response, if fetched
$response->error();
$response->size();           // bytes of robots.txt read
$response->truncated();
```

A failed fetch is a value, not an exception: a site with no robots.txt is a finding to report.

### Limits

Defaults follow what real crawlers do, not what is technically possible. Google reads at most 500 KB
of a robots.txt, so content past that is ignored and flagged rather than parsed into rules nothing
will honour.

```php
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;

$parser = new RobotsTxtParser(new HttpConfiguration(
    maxBytes: 500 * 1024,
    maxHtmlBytes: 1024 * 1024,
    maxRedirects: 5,
    robotsTimeout: 10,
    pageTimeout: 10,
));
```

## The agent dataset

User agents are enriched from a bundled dataset of ~1,600 known crawlers, sharded by leading letter
so a lookup reads one file of ~17 KB rather than decoding the whole 470 KB set.

```php
$userAgent = $document->userAgents()[0];
$userAgent->token;                // 'GPTBot' — exactly as declared
$userAgent->agent?->category;     // 'AI Data Scraper'
$userAgent->agent?->description;
```

Skip the dataset entirely when you do not need it:

```php
use Leopoletto\RobotsTxtParser\Agents\NullAgentRepository;

new RobotsTxtParser(agents: new NullAgentRepository());
```

### Refreshing it

Data comes from [knownagents.com](https://knownagents.com/agents). The sync reads that site's own
robots.txt through this parser and stops if it is not permitted, paces its requests, and fetches
only agents missing locally.

```bash
composer agents:sync -- --dry-run   # report what would change
composer agents:sync                # fetch new agents
composer agents:build               # rebuild the shards
```

## Extending the parser

Each directive is handled by its own `LineParser`, consulted in order:

```php
use Leopoletto\RobotsTxtParser\Contract\LineParser;

final class HostParser implements LineParser
{
    public function supports(Token $token): bool
    {
        return $token->fieldIs('host');
    }

    public function parse(Token $token, ParseContext $context): array
    {
        return [new HostRecord($token->number, $token->value)];
    }
}

$parser = new DocumentParser($agents, [
    new CommentParser(),
    new HostParser(),
    // …the catch-all must come last
    new UnknownFieldParser(),
]);
```

Input sources are equally pluggable — implement `Contract\Source` to parse from anywhere.

## Development

```bash
composer test        # phpunit
composer phpstan     # level 8
composer format      # php-cs-fixer
composer quality     # all three
```

## Upgrading from 2.x

Version 3 is a rewrite with a different public API. See [CHANGELOG.md](CHANGELOG.md) for the full
migration guide.

## Credits

- [Leonardo Poletto](https://github.com/leopoletto)
- Agent data from [Known Agents](https://knownagents.com)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
