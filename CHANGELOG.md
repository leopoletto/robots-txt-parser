# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

## v3.0.2 - 2026-09-06

### Fixed

- `BlanketRuleCheck` read `Disallow: /` under `User-agent: *` in isolation and
  reported *"The whole site is closed to all crawlers ... so no crawler may
  fetch any URL"* as `Critical`. Under RFC 9309 a group naming a crawler
  replaces the wildcard group rather than adding to it, so that statement is
  false for any file that blocks by default and then names the crawlers it
  wants — an increasingly common shape. On linkedin.com, which names 76
  crawlers, the audit asserted that nothing could be fetched while the same
  report listed five of six search engines as allowed.

  The check now reads the wildcard rule together with whatever overrides it.
  With no named group and no `Allow` exception the finding is unchanged. With
  either, it becomes `blanket-allowlist` at `Notice` — *"The file blocks
  everything by default"* — naming how many groups and path exceptions are
  exempt, and explaining that a named group must be added to let a new crawler
  in, because an `Allow` in the wildcard group will not reach it.

## v3.0.1 - 2026-09-05

Documentation only. No code changes, so upgrading from 3.0.0 is optional.

### Fixed

- The README shipped in the 3.0.0 archive was the one written before the audit
  existed: no mention of `Audit\Auditor`, and an issues table that both omitted
  types and wrongly described non-standard fields as accepted in silence. It was
  updated on `main` shortly after 3.0.0 was tagged, so the tag missed it.

Now documented: auditing (findings, statuses, the nine checks, crawler groups by
purpose, sitemap probing, the category breakdown, writing a check), the crawler
list and how to regenerate it from Cloudflare Radar, and a corrected issues table
that separates defects in the document from notes about the request itself.

## v3.0.0 - 2026-09-05

A full rewrite. The package is now modular, framework-free and statically analysed, and the three
parse entry points finally behave identically.

### Breaking changes

**Dropped `illuminate/support`.** The library no longer pulls in a framework. Collections are gone
from the public API; every query returns a plain typed `list<>`.

**`RobotsCollection` is replaced by `Parsing\Document`.** Query methods return typed records rather
than nested arrays, and nothing carries state between calls. The `displayUserAgent()` flag — mutable
state on a returned collection that silently disabled itself after one call — is gone. Directives
now know their own group, so `$directive->userAgents()` replaces it.

**Records are typed.** `Records\*` moved to `Record\*`; `SyntaxError` became `Issue` with a
severity and a type; `RobotsDirective` became `Directive` with a `DirectiveType` enum instead of a
string. `Records\Content` was unused and has been removed.

**Validation results are objects.** `ValidationResult`, `ParsedDirective` and `ValidationIssue`
replace the nested arrays returned by `DirectiveValidator`, and `Validators\` moved to
`Validation\`. `Helpers\RobotsMerger` became `Model\EffectiveRules`.

**Renamed parser methods.** `configureUserAgent()` → `withBotSignature()`, `setUserAgent()` →
`withUserAgent()`, `loadContent()` → `keepContent()`. The raw document is read from
`Response::content()` rather than a public property.

**The size limit is now 500 KB, not 500 MB.** 500 MB was never meaningful: Google ignores anything
past 500 KB. Oversized content is truncated and reported as a `truncated` issue instead of throwing.

**`Response::statusCode()` now reports the robots.txt status only.** It was previously overwritten
by the page response. The page's status is `pageStatusCode()`.

### Fixed

- **`parseText()` no longer lowercases and deduplicates lines.** It did, which meant pasted content
  reported different rules and different line numbers than the same file uploaded — and silently
  dropped a repeated `Disallow:` belonging to a second user-agent group. Agent lookup is
  case-insensitive on its own, so the lowercasing bought nothing.
- **Ports are preserved when locating robots.txt.** `http://localhost:8000/x` resolved to
  `http://localhost/robots.txt`.
- **A trailing `$` is stripped once, not repeatedly.** `rtrim($pattern, '$')` removed every trailing
  anchor, and a `$` in the middle of a pattern is now correctly literal.
- **Equal-specificity conflicts resolve to the least restrictive rule**, per RFC 9309, rather than to
  whichever line came first.
- **Repeated groups for one agent are merged.** A document declaring `User-agent: Googlebot` twice
  previously had its second set of rules ignored.
- Inline comments (`Disallow: /admin # note`) are stripped from directive values.
- A UTF-8 BOM on line 1 no longer corrupts the first field.
- Meta tags are parsed attribute-wise, so unquoted, single-quoted and reordered attributes all work.
- `X-Robots-Tag: max-snippet:-1` is no longer misread as targeting a crawler named `max-snippet`.

### Added

- **Issues for lines that were previously dropped in silence** — misspelled directives, rules before
  any `User-agent:`, non-numeric crawl delays, paths missing a leading `/`.
- **`Decision`**: `decide()` returns the outcome *and* the rule responsible, so a UI can point at it.
- **The page is no longer fetched when robots.txt disallows it.** `pageDecision()` and
  `pageInspected()` report what happened, and a `page_disallowed` issue explains it.
- **Sharded agent dataset.** ~1,600 agents split by leading letter; a lookup reads ~17 KB instead of
  decoding 470 KB twice. The eager/lazy "priority agents" split is gone, as is `data/compress.php`.
- **`bin/sync-agents.php`** refreshes the dataset from knownagents.com via its sitemap, obeying that
  site's robots.txt through this parser, pacing requests, and fetching only what is missing. It also
  captures each agent's operator and whether it is expected to honour robots.txt.
- **Pluggable parsing.** `Contract\LineParser` and `Contract\Source` make new directives and new
  input types additive rather than edits to a conditional chain.
- `HttpConfiguration` for limits and timeouts; `NullAgentRepository` to skip dataset I/O entirely.
- PHPStan level 8, clean.

### Migration

```php
// 2.x
$parser->configureUserAgent('MyBot', '1.0', 'https://example.com');
$response = $parser->loadContent(true)->parseUrl($url);
$rules = $response->records()->disallowed('GPTBot')->toArray();
$content = $parser->content;

// 3.0
$parser->withBotSignature('MyBot', '1.0', 'https://example.com');
$response = $parser->keepContent()->parseUrl($url);
$rules = $response->records()->disallowed('GPTBot'); // list<Directive>
$content = $response->content();
```

Record arrays keep their previous shape via `toArray()`, so serialised output needs little change:

```php
$rules = array_map(fn ($d) => $d->toArray(), $response->records()->disallowed('GPTBot'));
```

## v2.5.0 - 2026-04-05

Update version to 2.5.0 and refactor RobotsTxtParser for improved meta tag handling

- Updated the package version in composer.json to 2.5.0.
- Refactored the RobotsTxtParser to streamline the process of fetching X-Robots-Tag headers and meta tags, enhancing the logic for handling requests and responses.
- Improved memory management during HTML content processing.

## v2.4.0 - 2026-04-04

Update version to 2.4.0 and enhance Response handling in RobotsTxtParser

- Updated the package version in composer.json to 2.4.0.
- Modified the Response class to include additional properties: finalUrl, redirects, and statusCode.
- Updated the RobotsTxtParser to populate these new properties based on the HTTP response received during parsing.

## v2.3.0 - 2026-04-03

Update version to 2.3.0 and enhance RobotsTxtParser with content loading capabilities

- Updated the package version in composer.json to 2.3.0.
- Introduced a new feature in RobotsTxtParser to optionally load and store the raw content of robots.txt files during parsing.
- Added tests to verify the functionality of the new content loading feature, ensuring it behaves correctly across different parsing methods.

## v2.1.2 - 2026-03-31

Refactor RobotsTxtParser to clarify page size handling in comments

## V2.1.1 - 2026-03-31

### Enhance RobotsDirective to include detailed info array for allow/disallow directives

- Added an `info` property to the `RobotsDirective` class, which computes and stores details about path matching, end anchors, wildcards, and specificity.
- Updated `RobotsCollection` to include the new `info` property in directive outputs.
- Introduced comprehensive tests to validate the structure and content of the `info` array for directives.

## v2.1.0 - 2026-02-27

Enhance RobotsDirective to include detailed info array for allow/disallow directives

- Added an `info` property to the `RobotsDirective` class, which computes and stores details about path matching, end anchors, wildcards, and specificity.
- Updated `RobotsCollection` to include the new `info` property in directive outputs.
- Introduced comprehensive tests to validate the structure and content of the `info` array for directives.

## V2.0.2 - 2026-02-16

Enhance directive parsing and validation in RobotsTxtParser and Records

- Updated RobotsTxtParser to push individual meta directives for improved handling.
- Refactored RobotsCollection to use type hints and improved instantiation for clarity.
- Added validation logic in HeaderDirective and MetaDirective for parsing X-Robots-Tag headers and meta tags, respectively.
- Enhanced the return structure of parse methods to include validation results and issues for better error handling.
- Update package version to 2.0.2 and add optional link property to UserAgent class

## v2.0.1 - 2026-02-10

**Full Changelog**: https://github.com/leopoletto/robots-txt-parser/compare/v2.0.0...v2.0.1

## v2.0.0 - 2026-02-10

### V2.0.0

#### Add tests for DirectiveValidator, RobotsMerger, HeaderDirective, and MetaDirective

Commit: 388d283a7402ee1bda8ac16dfe831f2a5d01e2ae
Cover validation, conflict/redundancy detection, parametric directives, user agent parsing, merge behavior, and integration with meta/header parsing.

#### Update README with directive validation, merging, and enriched output docs

Commit: a5ecfdad0257bf0e4004223d8b6af2eb22122350

#### Add directive validation, merging, and integrate into HeaderDirective and MetaDirective

Commit: 04ae317acd6e0ed14fb6a812bc957ee8d9154852

- Add DirectiveValidator with conflict, redundancy, deprecation, and full spec detection
- Add RobotsMerger to combine meta and header directives with most-restrictive-wins
- Update HeaderDirective to parse with validation and user agent support
- Update MetaDirective to parse with validation and Bingbot support
- Fix RobotsCollection constructor chaining and return statement

## v1.1.0 - 2025-12-08

### New Features

#### Enhance RobotsTxtParser and RobotsCollection for user agent path access checking

Commit: ca3b4c994ce22d6043788f5e886d9c5a9a6788de

- Updated `README.md` to clarify the library's capabilities, including methods for checking user agent access to specific paths.
- Implemented `uaAllowed()` and `isAllowed()` methods in RobotsCollection to determine if a user agent can access a given path, with support for wildcard patterns and case-insensitive matching.
- Added comprehensive tests for `uaAllowed()` functionality, covering various scenarios including specific user agents, wildcard fallbacks, and path normalization.
- Improved handling of user agent descriptions and categories for recognized bots.

#### Refactor UserAgent class to improve user agent parsing and maintain original casing

Commit: 5dc4ad3c91e38ca9da50a2e1c383abd69fd8ea5a

- Updated the UserAgent class to use the parsed original declared name for the userAgent property.
- Enhanced null checks for invalid user agents.
- Improved code clarity by renaming variables for better understanding.

#### Refactor UserAgent class to preserve original casing and enhance parsing logic

Commit: 2e5b189e9959cef76485cee59edf45ca2607dd33

- Updated UserAgent class to use originalDeclaredAgentName for userAgent property to maintain casing.
- Added checks to return null for invalid or empty user agents.
- Enhanced tests to verify user agent casing consistency across different parsing methods and handle invalid/malformed user agents.

#### Update version to 1.1.0 in composer.json and enhance RobotsTxtParser for improved user agent recognition

Commit: 6dc2e7b2a7ead6a4ea3acdac76782a713222dd92

- Bumped version in composer.json from `1.0.2` to `1.1.0`.
- Added `isValid` method to Response class to check for non-empty records.
- Enhanced RobotsTxtParser to load user agent data from agents.json, including description and category fields for recognized bots.
- Updated parseLine method to handle unmodified user agent lines.
- Improved UserAgent class to include original declared name, description, and category.
- Added comprehensive tests for bot recognition and handling of the missing agents file.

## v1.0.2 - 2025-11-07

Fixing Release Version

**Full Changelog**: https://github.com/leopoletto/robots-txt-parser/compare/v1.0.1...v1.0.2
