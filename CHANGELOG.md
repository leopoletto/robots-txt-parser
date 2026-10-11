# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

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
