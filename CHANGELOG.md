# Changelog

All notable changes to `robots-txt-parser` will be documented in this file.

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
