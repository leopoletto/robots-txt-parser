# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

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
