# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

## Planned for 2.1

- **Directive config check**, replacing `DeprecatedDirectiveCheck`. That check's verdict on
  `Crawl-delay` is wrong as it stands: it says crawlers ignore the directive, and Bing
  honours it.
- **Unknown-directive config and scoring change.**

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
