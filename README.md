# Robots.txt Parser

[![Tests](https://github.com/leopoletto/robots-txt-parser/actions/workflows/run-tests-phpunit.yml/badge.svg)](https://github.com/leopoletto/robots-txt-parser/actions/workflows/run-tests-phpunit.yml)
[![Latest Version](https://img.shields.io/packagist/v/leopoletto/robots-txt-parser.svg)](https://packagist.org/packages/leopoletto/robots-txt-parser)
[![License](https://img.shields.io/packagist/l/leopoletto/robots-txt-parser.svg)](LICENSE.md)

A PHP library for parsing and auditing `robots.txt` — from a URL, a file, or a string — including
the robots `<meta>` tags and `X-Robots-Tag` headers that govern indexing alongside it.

Built for analysis rather than crawling. It reports what a document *says*, *why* a given rule
applies to a given URL, and — through the audit — what any of it means for a site's visibility.

```php
use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$response = (new RobotsTxtParser())
    ->withBotSignature('MyBot', '1.0', 'https://example.com/bot')
    ->parseUrl('https://example.com/products/widget');

$response->isAllowed('GPTBot', '/products/widget');   // false
$response->records()->userAgents();                   // list<UserAgent>
$response->records()->groups();                       // list<Group>
```

Then ask what it means:

```php
use Leopoletto\RobotsTxtParser\Audit\Auditor;

foreach ((new Auditor())->audit($response)->actionable() as $finding) {
    $finding->title;    // "3 of 3 user-triggered AI fetches are blocked"
    $finding->impact;   // why that costs something
    $finding->fix;      // what to do about it
}
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

## Auditing

Parsing answers *what does this file say*. The audit answers *what does that mean for this site,
and what should change* — which is the question people actually arrive with.

```php
use Leopoletto\RobotsTxtParser\Audit\Auditor;

$report = (new Auditor())->audit($response);

$report->worst();       // Status::Critical
$report->counts();      // ['critical' => 2, 'warning' => 6, 'notice' => 3, 'pass' => 2]
$report->actionable();  // everything that is not a pass, worst first
$report->find('blanket-block');
$report->toArray();     // JSON-ready
```

Every finding answers three questions in order:

```php
$finding->title;      // "1 of 6 search engines are blocked"
$finding->summary;    // what is the case
$finding->impact;     // why it matters for visibility
$finding->fix;        // what to do about it, or null when nothing is wrong
$finding->evidence;   // the lines it points at
$finding->status;     // Status::Critical
```

### Status

A robots.txt is a policy document, not code: most of what looks wrong may be deliberate. So findings
state what is true and only claim something is broken when it cannot be anything else.

| Status | Meaning |
| --- | --- |
| `Critical` | Actively preventing indexing, or a rule no crawler can honour |
| `Warning` | Likely costing visibility or leaking information |
| `Notice` | Worth knowing; probably deliberate |
| `Pass` | Nothing to do |

### What it checks

| Check | Looks for |
| --- | --- |
| `CrawlerAccessCheck` | Whether notable crawlers can reach the site, grouped by what blocking them costs |
| `BlanketRuleCheck` | `Disallow: /` for everyone, or a file that restricts nothing |
| `IndexingDirectiveCheck` | Page `noindex` reconciled against robots.txt |
| `SitemapCheck` | Declared, absolute, reachable, and not blocked by this same file |
| `SyntaxCheck` | Lines crawlers will skip |
| `DeprecatedDirectiveCheck` | `Crawl-delay`, `Noindex:` — directives that no longer do what their author expects |
| `SensitivePathCheck` | `/admin`, `/api`, `/staging` and similar, disclosed to a public file |
| `PrecedenceCheck` | Contradictions, shadowed rules, duplicates |
| `FileSizeCheck` | The 500 KB ceiling crawlers enforce |

The single most expensive misunderstanding in the subject gets its own check: `Disallow` and
`noindex` are not the same instruction, and combining them produces the opposite of what is
intended. A crawler forbidden to fetch a page never reads its `noindex`, so the URL stays eligible
for search — it just gets listed without a description.

### Crawler groups

Which crawlers are worth reporting on, and how they are grouped, follows
[Cloudflare Radar](https://radar.cloudflare.com/bots), which measures observed crawler traffic
rather than estimating importance. The useful idea taken from it is **purpose**, because the same
mechanism has very different consequences:

| Group | Blocking them costs |
| --- | --- |
| Search engines | Removal from search. Reported as `Critical`. |
| AI answer engines | Citation traffic in AI-generated answers |
| User-triggered AI fetches | A person asked for the page and gets an error |
| Social link previews | Shared links render as bare URLs |
| AI training crawlers | Nothing but the content. Reported as `Notice`, never a fault. |

That last row matters: blocking training crawlers is the most common configuration on the web and
an entirely legitimate editorial choice, so the audit reports it without calling it a problem.

### Validating sitemaps

A declared sitemap that 404s is worse than none — it looks handled. Pass a probe to fetch each one
and check it responds and opens as a `urlset` or `sitemapindex`:

```php
use GuzzleHttp\Client;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbe;

$auditor = new Auditor(probe: new SitemapProbe(
    new Client(['http_errors' => false]),
    'Mozilla/5.0 (compatible; MyBot/1.0; https://example.com/bot)',
));
```

Without a probe the sitemap checks still run; they just do not make requests.

### Posture at a glance

Alongside the findings, the report groups every user agent the file *names* by what that crawler is
for. On a file that declares dozens of agents, reading them one by one says nothing — grouped, the
policy states itself:

```php
foreach ($report->breakdown->categories as $tally) {
    printf("%-24s %s\n", $tally->category, $tally->describe());
}

$report->breakdown->fullyBlocked();   // categories where nothing is allowed
$report->breakdown->fullyAllowed();
```

```
Search Engine Crawler    all 17 allowed
AI Data Scraper          all 9 blocked
AI Assistant             all 5 blocked
Undocumented AI Agent    all 3 blocked
```

### Adding a check

Checks are independent and additive, so a new question never means editing the report:

```php
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Response;

final class HostCheck implements AuditCheck
{
    /** @return list<\Leopoletto\RobotsTxtParser\Audit\Finding> */
    public function run(Response $response): array
    {
        return [];
    }
}

$auditor = new Auditor([new HostCheck(), /* … */]);
```

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

Nothing is dropped in silence. Every line is either parsed into a record or reported as an issue,
so a reader can account for all of them.

**Defects in the document**

| Type | Severity | Meaning |
| --- | --- | --- |
| `malformed_line` | high | A line with no `:` separator |
| `orphan_directive` | high | A rule declared before any `User-agent:` |
| `unknown_directive` | medium | A field no crawler acts on, usually a typo |
| `ineffective_directive` | medium | A field crawlers recognise but no longer honour, such as `Noindex:` |
| `invalid_path` | medium | An `Allow`/`Disallow` value not starting with `/` |
| `invalid_value` | medium | A non-numeric `Crawl-delay` |
| `empty_sitemap` | medium | A `Sitemap:` line with no URL |
| `nonstandard_directive` | low | A published extension outside the standard — see below |

**About the request, not the file**

| Type | Meaning |
| --- | --- |
| `fetch_failed` | The robots.txt could not be retrieved |
| `too_many_redirects` | The redirect chain exceeded the limit |
| `empty_response` | The response carried no body |
| `truncated` | The document exceeded the size limit |
| `page_disallowed` | The page was not fetched because robots.txt forbade it |

Note the split. A `page_disallowed` note says nothing about whether the file is correct, so counting
it as an error would report a fault against a valid document.

Non-standard but widely published fields — `Host`, `Clean-param`, `Request-rate`, `Visit-time` and
Cloudflare's `Content-Signal` — are recorded at the lowest severity rather than faulted. They are
real, and worth knowing about, but they are not mistakes.

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

User agents are enriched from a bundled dataset of ~1,800 known crawlers, sharded by leading letter
so a lookup reads one file of ~19 KB rather than decoding the whole 500 KB set.

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

Data comes from [knownagents.com](https://knownagents.com/agents). The roster is read from that
site's sitemap rather than its paginated listing, so one request replaces thirty-six and nothing is
missed to pagination shifting mid-crawl. Detail pages are fetched only for agents missing locally,
so a routine sync costs one request per genuinely new crawler.

It also dogfoods the library: the sync reads knownagents.com's own robots.txt *through this parser*
and stops if it is not permitted, honours any declared `Crawl-delay`, and paces its requests.

```bash
composer agents:sync -- --dry-run          # report what would change
composer agents:sync -- --limit=25         # cap the fetches while trying it out
composer agents:sync                       # fetch new agents
composer agents:build                      # rebuild the shards
```

## The crawler list

Separate from the agent dataset, and much shorter: the crawlers the **audit** reports on, in
`src/data/crawlers.json`, with the prose explaining what blocking each group costs.

Selection and grouping follow [Cloudflare Radar](https://radar.cloudflare.com/bots). Traffic share
moves month to month, so no ranking is stored — only membership and purpose, which are stable.

Radar publishes no JSON for its directory, so regenerating is two steps. Paste
`bin/radar-scrape.js` into the browser console on
[radar.cloudflare.com/bots/directory](https://radar.cloudflare.com/bots/directory); it pages through
the grid, extracts each card and downloads `radar-crawlers.json`. Then:

```bash
composer crawlers:import -- --input=radar-crawlers.json --dry-run
composer crawlers:import -- --input=radar-crawlers.json
```

The importer maps Radar's purpose labels onto the report's groups and **reports anything it cannot
place** rather than filing it somewhere plausible — a crawler in the wrong group would produce
confidently wrong advice.

## Extending the parser

Each directive is handled by its own `LineParser`, consulted in order:

```php
use Leopoletto\RobotsTxtParser\Contract\LineParser;
use Leopoletto\RobotsTxtParser\Parsing\ParseContext;
use Leopoletto\RobotsTxtParser\Parsing\Token;

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

`wizardcompass/robots-txt-parser` is retired in favour of this package. Its 2.0.0 release is a
compatibility layer that delegates here, so existing installs keep working while they migrate.

## Credits

- [Leonardo Poletto](https://github.com/leopoletto)
- Crawler descriptions from [Known Agents](https://knownagents.com)
- Crawler selection and purpose classification from [Cloudflare Radar](https://radar.cloudflare.com/bots)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
