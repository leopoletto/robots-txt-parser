# Robots TXT Parser

[![Latest Version on Packagist](https://img.shields.io/packagist/v/leopoletto/robots-txt-parser.svg?style=flat-square)](https://packagist.org/packages/leopoletto/robots-txt-parser)
[![Tests](https://img.shields.io/github/actions/workflow/status/leopoletto/robots-txt-parser/run-tests-phpunit.yml?branch=main&label=tests&style=flat-square)](https://github.com/leopoletto/robots-txt-parser/actions/workflows/run-tests-phpunit.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/leopoletto/robots-txt-parser.svg?style=flat-square)](https://packagist.org/packages/leopoletto/robots-txt-parser)

A comprehensive PHP package for parsing and analyzing robots.txt files. This library is designed to help you understand the structure and content of robots.txt files, including support for X-Robots-Tag HTTP headers and meta tags from HTML pages.

> **Note**: This library is designed for **parsing and analyzing** robots.txt files to understand their structure. It does **not** validate whether a specific bot can crawl a specific URL.

## Installation

Install via Composer:

```bash
composer require leopoletto/robots-txt-parser
```

## Requirements

- PHP 8.2 or higher

### Dependencies

- Guzzle HTTP Client
- Illuminate Collections

## Quick Start

```php
use Leopoletto\RobotsTxtParser\RobotsTxtParser;

// Instantiate the parser
$parser = new RobotsTxtParser();

// Configure your bot's user agent (required for parseUrl)
$parser->configureUserAgent('MyBot', '1.0', 'https://example.com/mybot');

// Parse from URL
$response = $parser->parseUrl('https://example.com');
```

## Configuration

Before parsing from a URL, you must configure your bot's user agent. This is used when making HTTP requests.

### Method 1: Using `configureUserAgent()`

```php
$parser->configureUserAgent('BotName', '1.0', 'https://example.com/bot');
// Results in: Mozilla/5.0 (compatible; BotName/1.0; https://example.com/bot)
```

### Method 2: Using `setUserAgent()`

```php
$parser->setUserAgent('MyCustomUserAgent/1.0');
```

## Parsing Methods

The library provides three methods for parsing robots.txt content:

### 1. Parse from URL (`parseUrl`)

Parses robots.txt from a URL and also extracts:

- **X-Robots-Tag** HTTP headers from the robots.txt response
- **Meta tags** (robots, googlebot, googlebot-news) from the HTML page if a non-robots.txt URL is provided

```php
$parser = new RobotsTxtParser();
$parser->configureUserAgent('MyBot', '1.0', 'https://example.com');

// Parse from any URL (will automatically fetch /robots.txt)
$response = $parser->parseUrl('https://example.com');
// or
$response = $parser->parseUrl('https://example.com/robots.txt');

$records = $response->records();
```

**What `parseUrl` returns:**

- All robots.txt directives (User-agent, Allow, Disallow, Crawl-delay, Sitemap)
- X-Robots-Tag headers from the robots.txt response
- Meta tags from the HTML page (if parsing a non-robots.txt URL)
- Comments and syntax errors

### 2. Parse from File (`parseFile`)

Parses a robots.txt file from the local filesystem.

```php
$parser = new RobotsTxtParser();
$response = $parser->parseFile('/path/to/robots.txt');

$records = $response->records();
```

### 3. Parse from Text (`parseText`)

Parses robots.txt content directly from a string.

```php
$parser = new RobotsTxtParser();
$content = "User-agent: *\nDisallow: /admin/";
$response = $parser->parseText($content);

$records = $response->records();
```

## Accessing Parsed Data

All parsing methods return a `Response` object with the following methods:

### Basic Information

```php
$response = $parser->parseUrl('https://example.com');

// Get the size of the parsed content in bytes
$size = $response->size();

// Get all records as a collection
$records = $response->records();

// Get total number of records
$totalLines = $records->lines();
```

### User Agents

Get all user agents and their directives:

```php
// Get all user agents
$userAgents = $records->userAgents()->toArray();

// Get a specific user agent
$googlebot = $records->userAgents('Googlebot')->toArray();
```

**Example output:**

```json
{
    "*": {
        "line": 19,
        "userAgent": "*",
        "allow": [
            {
                "line": 20,
                "directive": "allow",
                "path": "/researchtools/ose/$"
            }
        ],
        "disallow": [
            {
                "line": 32,
                "directive": "disallow",
                "path": "/admin/"
            }
        ],
        "crawlDelay": []
    },
    "GPTBot": {
        "line": 11,
        "userAgent": "GPTBot",
        "allow": [],
        "disallow": [
            {
                "line": 12,
                "directive": "disallow",
                "path": "/blog/"
            }
        ],
        "crawlDelay": []
    }
}
```

### Directives

Get specific directive types:

```php
// Get all disallowed paths
$disallowed = $records->disallowed()->toArray();

// Get disallowed paths for a specific user agent
$disallowed = $records->disallowed('Googlebot')->toArray();

// Get all allowed paths
$allowed = $records->allowed()->toArray();

// Get crawl delays
$crawlDelays = $records->crawlDelay()->toArray();
```

**Example output:**

```json
[
    {
        "line": 32,
        "directive": "disallow",
        "path": "/admin/"
    },
    {
        "line": 33,
        "directive": "disallow",
        "path": "/private/"
    }
]
```

### Display User Agent Information

When you want to see which user agents apply to each directive:

```php
// Show user agents as an array for each directive
$disallowed = $records->displayUserAgent()->disallowed()->toArray();
```

**Example output:**

```json
[
    {
        "line": 32,
        "directive": "disallow",
        "path": "/admin/",
        "userAgent": ["*", "GPT-User"]
    }
]
```

When querying by a specific user agent with `displayUserAgent()`, directives are expanded:

```php
// Expand directives for all user agents in the same group
$disallowed = $records->displayUserAgent()->disallowed('*')->toArray();
```

**Example output:**

```json
[
    {
        "line": 32,
        "directive": "disallow",
        "path": "/admin/",
        "userAgent": "*"
    },
    {
        "line": 32,
        "directive": "disallow",
        "path": "/admin/",
        "userAgent": "GPT-User"
    }
]
```

### Sitemaps

```php
$sitemaps = $records->sitemaps()->toArray();
```

**Example output:**

```json
[
    {
        "line": 52,
        "url": "https://example.com/sitemap.xml",
        "valid": true
    }
]
```

### Comments

```php
$comments = $records->comments()->toArray();
```

**Example output:**

```json
[
    {
        "line": 1,
        "comment": "File last updated May 5, 2025"
    }
]
```

### X-Robots-Tag Headers (from `parseUrl`)

When parsing from a URL, you can access X-Robots-Tag HTTP headers:

```php
$headers = $records->headersDirectives()->toArray();
```

**Example output:**

```json
[
    {
        "X-Robots-Tag": ["all"]
    }
]
```

### Meta Tags (from `parseUrl`)

When parsing from a URL (non-robots.txt), you can access robots meta tags from the HTML:

```php
$metaTags = $records->metaTagsDirectives()->toArray();
```

**Example output:**

```json
[
    [
        "index",
        "follow",
        "max-image-preview:large",
        "max-snippet:-1",
        "max-video-preview:-1"
    ]
]
```

### Syntax Errors

Check for parsing errors:

```php
$errors = $records->syntaxErrors()->toArray();
```

**Example output:**

```json
[
    {
        "line": 5,
        "message": "Directive must follow a user agent"
    }
]
```

## Complete Example

Here's a complete example showing all available data:

```php
use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();
$parser->configureUserAgent('MyBot', '1.0', 'https://example.com/mybot');

// Parse from URL
$response = $parser->parseUrl('https://example.com');
$records = $response->records();

// Build comprehensive response
$data = [
    'size' => $response->size(),
    'lines' => $records->lines(),
    'user_agents' => $records->userAgents()->toArray(),
    'disallowed' => $records->displayUserAgent()->disallowed()->toArray(),
    'allowed' => $records->allowed()->toArray(),
    'crawls_delay' => $records->crawlDelay()->toArray(),
    'sitemaps' => $records->sitemaps()->toArray(),
    'comments' => $records->comments()->toArray(),
    'html' => $records->metaTagsDirectives()->toArray(),      // From parseUrl only
    'headers' => $records->headersDirectives()->toArray(),    // From parseUrl only
    'errors' => $records->syntaxErrors()->toArray(),
];

return response()->json($data);
```

See `public/example.json` for a complete example of the output structure.

## User Agent Groups

The library correctly handles consecutive User-agent declarations, which in robots.txt format means they share the same directives:

```robots.txt
User-agent: *
User-agent: GPT-User
Disallow: /admin/
```

Both `*` and `GPT-User` will have the same directives. When you query by either user agent, you'll get the same results:

```php
$disallowed1 = $records->disallowed('*')->toArray();
$disallowed2 = $records->disallowed('GPT-User')->toArray();
// Both return the same directives
```

## Features

- ✅ Parse robots.txt from URL, file, or text
- ✅ Extract X-Robots-Tag HTTP headers
- ✅ Extract robots meta tags from HTML pages
- ✅ Handle consecutive User-agent declarations (groups)
- ✅ Efficient storage (no duplicate directives)
- ✅ Support for all standard directives (Allow, Disallow, Crawl-delay, Sitemap)
- ✅ Comments and syntax error detection
- ✅ Memory-efficient streaming for large files
- ✅ Comprehensive test coverage

## Credits

- [leopoletto](https://github.com/leopoletto)
- [All Contributors](../../contributors)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
