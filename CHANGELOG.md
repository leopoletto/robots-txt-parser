# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

## Planned for 2.2

- **Directive config check**, replacing `DeprecatedDirectiveCheck`. That check's verdict on
  `Crawl-delay` is wrong as it stands: it says crawlers ignore the directive, and Bing
  honours it.
- **Unknown-directive config and scoring change.**

## v2.1.0 - 2026-10-15

- **Breaking for exhaustive `match`: `Status::NotChecked`.** A new case, `not_checked`, for a
  finding whose input was never observed. A consumer that matches every `Status` case without
  a default arm must add it. It weighs 0, is not actionable, is ignored by `Report::worst()`,
  and `Report::counts()` gains a `not_checked` key.
- **A page that was not read is not checked.** `PageProbeResult::observed()` is true only for a
  2xx whose body arrived whole. `IndexingDirectiveCheck` reports `indexing-not-observed`
  (`NotChecked`) for a 4xx, a 5xx, a timeout or a body that fails partway, where it used to pass
  with "No indexing directives on the requested page". `X-Robots-Tag` is no longer read from a
  non-2xx page response, and a mid-body failure is an error rather than the partial HTML.
  `PageProbeResult::$truncated` records a head cut off by `maxHtmlBytes`. Stored results without
  the new fields read back as observed only when they were a clean 2xx.
- **Bot challenges are not read as the page.** `ChallengeDetector` recognises a challenge or
  block page by markers specific to Cloudflare, Akamai, HUMAN and DataDome. Such a page sets
  `PageProbeResult::$challenge`, is not observed, and contributes no directives, so a
  challenge's own `noindex` is no longer reported as the site's.
- **Dead markup is not read for meta tags.** `MetaTagExtractor` ignores tags inside HTML comments,
  `<script>` and `<noscript>`, so a commented-out `noindex` no longer counts. A page whose read
  ends partway through a `<meta>` tag is recorded as truncated instead of losing the tag silently
  (`MetaTagExtractor::endsInsideTag()`).
- **A chain of exactly `maxRedirects` hops is followed.** `FetchResult::exceededRedirectLimit()`
  compared with `>=`, so a legitimate five-hop chain was flagged `too_many_redirects` and its body
  never read, while `error()` stayed null and the response passed as an empty file. It now
  compares with `>`. The `too_many_redirects` and `empty_response` responses set `error()`, like
  `fetch_failed`. An empty 200 body is still a file with no rules, not an error.
- **Truncation no longer creates rules.** When `maxBytes` cuts a file, the incomplete last line is
  discarded instead of parsed, so a cut cannot turn a rule into a broader one, and the `truncated`
  issue says so. A file of exactly `maxBytes` is no longer marked truncated. `FileSizeCheck`
  judges against the limit the file was read under, given as `new Auditor(maxBytes: …)` (default
  500 KB, with unchanged wording).
- **Timeouts cap the whole request.** With a streamed response, Guzzle's `timeout` bounded each
  read, so a server trickling bytes could hold a request open indefinitely. Robots.txt, page and
  sitemap bodies are now read through `DeadlineStream`, which stops at the timeout measured from
  the request's start. A robots.txt body that times out or fails partway used to escape
  `parseUrl()` as a `RuntimeException`; it is now a `fetch_failed` response with `error()` set.
  `HttpConfiguration::$connectTimeout` (default 5) is sent as `connect_timeout`; Guzzle's stream
  handler ignores that option, so with the default client the connection stays bounded by the
  request timeout. `RobotsFetcher` and `SitemapProbe` accept a clock for testing.
- **Sitemaps that were not seen are not checked.** `SitemapProbeResult` gains a `SitemapOutcome`
  (`observed`, `http_error`, `blocked`, `skipped`, `timeout`, `error`) and a `reason`, with
  `blocked()` and `skipped()` constructors. Only a 4xx or 5xx answer is `sitemap-unreachable`; a
  timeout or a failed request used to be that Critical finding and is now listed under
  `sitemap-not-checked` (`NotChecked`) with blocked and skipped sitemaps. A result stored without
  an outcome gets the one its fields imply; one with no status and no error, the stand-in for a
  sitemap left unfetched, reads back as `skipped`. Stored scores that counted a timeout as
  unreachable change when audited again.
- **Sitemaps on another origin need a decision.** `SitemapProbe` implements the new
  `Contract\OriginScopedSitemapProber`, which the audit scopes to the robots.txt's origin. A
  sitemap on another origin is fetched only when the `decideElsewhere` closure says that origin's
  robots.txt allows it, is `blocked` when it says not, and is `skipped` without one. The package
  never fetches another origin's robots.txt.
- **A 4xx on robots.txt is either a missing file or a refusal.** This replaces the 2.0.1 rule
  that "a 4xx other than 429 still means there are no rules, so the page is fetched". A 404 or
  410 is a missing file: its error page is no longer parsed as robots.txt, the document is empty
  with a `robots_missing` issue (low), every path is allowed per RFC 9309 §2.3.1.3, and the page
  is fetched. A 401, 403 or any other 4xx but 429 is a refusal: the body is not parsed, the page
  is not requested, and `error()` is set with a `robots_refused` issue. This departs from the RFC,
  which reads every 4xx as "no file", so that a bot turned away does not knock again. 429 and
  5xx are unchanged.
- **Fetch issues are not syntax faults.** `SyntaxCheck` counted the `robots_unreachable` issue
  added in 2.0.1 as a line crawlers would ignore. It now leaves it out, with `robots_missing` and
  `robots_refused`.

## v2.0.1 - 2026-10-14

- **An unreachable robots.txt blocks the page fetch.** When robots.txt answers with a 5xx or
  a 429, `parseUrl()` no longer requests the page: RFC 9309 section 2.3.1.4 treats every path
  as disallowed. The response carries a `robots_unreachable` issue, and `pageInspected()` is
  false. A 4xx other than 429 still means there are no rules, so the page is fetched.

## v2.0.0 - 2026-10-11

Everything here breaks something a consumer may rely on.

- **Path tiers.** `SensitivePathCheck` reports a path only when listing it tells a reader
  something they could not guess. `/admin`, `/administrator`, `/backend`, `/cpanel` and a bare
  `/staging`, `/backup` or `/private` are no longer flagged. What is reported falls into a
  tier: secrets and data files (`/.env.production`, `/dump.sql`, `/data/app.sqlite`),
  specific names (`/backup-2023/`, `/app/staging/`, `/export.csv`), tool panels and wildcard
  patterns. `Allow` lines are checked as well as `Disallow`; `dev`, `test` and `logs` count
  only with a number, beside a sensitive token, or below the first level. The lists are
  constructor parameters.
- **Finding-id split.** The `sensitive-paths` finding no longer exists. There is one finding
  per tier: `sensitive-paths-secrets`, `sensitive-paths-specific`, `sensitive-paths-tools` and
  `sensitive-paths-wildcard`. Anything that looks a finding up by the old id finds nothing.
- **`Finding::category`.** Every finding carries a `Category` (`crawler_access`, `security`,
  `syntax`, `discovery`, `indexing`, `usage_preference`), also present in `toArray()`. It is a
  required constructor argument, so a custom check must pass one. Findings of one check may
  differ in category: `DeprecatedDirectiveCheck` files its `Noindex:` finding under `indexing`.
- **Severity recalibration.** Nothing in `SensitivePathCheck` is critical, because nothing is
  fetched: secrets and specific names are warnings, tool panels and wildcard patterns are
  info. A later reachability check may raise a secret to critical on a confirmed 200.
- **`SitemapProber` contract.** `Auditor` and `SitemapCheck` take a `Contract\SitemapProber`
  where they took the concrete `SitemapProbe`, which is now its HTTP implementation. A caller
  can record what a probe found (`SitemapProbeResult::toArray()` / `fromArray()`) and replay
  it, so a stored file audited again reaches the verdict it had when it was fetched.
- **`PageProber` contract.** `RobotsTxtParser::withPageProber()` takes a `Contract\PageProber`
  to ask for a page's meta robots tags and X-Robots-Tag headers; `Http\PageInspector` is its
  HTTP implementation, and its `inspect()` method and constructor changed to match. What a
  probe found is on `Response::pageProbe()` as a `PageProbeResult` (`toArray()` /
  `fromArray()`). `RobotsTxtParser::parseTextForUrl()` parses a stored robots.txt for a URL
  without fetching it, deciding again whether the page may be requested and asking the
  prober only when it may. It also takes the two things about the robots.txt response that
  the file does not hold: its size when fetched, since kept content measures less than the
  original, and the X-Robots-Tag values sent with it.
## v1.0.1 - 2026-10-11

- `SensitivePathCheck` no longer flags `/api`, `/graphql` or `/wp-admin`. Listing them
  discloses nothing a site does not already show, and Disallow is the right way to keep
  crawlers off them.
- Its advice now says to remove a sensitive path from the file and rely on authentication
  plus a `noindex` header or meta tag, which a crawler can only read once the path is no
  longer Disallowed.

## v1.0.0 - 2026-10-01

Initial release of the repository in its current form: the parser, the audit
and the bundled agent dataset. The dataset carries agent names, categories,
operators and paths only; see [src/data/NOTICE.md](src/data/NOTICE.md) for its
sources and licensing.
