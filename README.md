# Robots TXT Parser

A comprehensive PHP package for parsing robots.txt files, including support for meta tags and X-Robots-Tag HTTP headers.

## Installation

```bash
composer require leopoletto/robots-txt-parser
```

## Usage

```php
use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();
$robots->configureUserAgent('RobotsTxtParser', 1.0, 'https://github.com/leopoletto/robots-txt-parser');

// Parse from URL
$response = $parser->parseUrl('https://example.com');

return response()->json([
    'size' => $response->size(),
    'lines' => $response->records()->lines(),
    'comments' => $response->records()->comments()->toArray(),
    'html' => $response->records()->metaTagsDirectives()->toArray(),
    'headers' => $response->records()->headersDirectives()->toArray(),
]);
```

Response

```JSON
{
    "size": 249403,
    "lines": 4440,
    "comments": [
        {
            "line": 1,
            "comment": "Notice: The use of robots or other automated means to access LinkedIn without"
        },
        {
            "line": 2,
            "comment": "the express permission of LinkedIn is strictly prohibited."
        },
        {
            "line": 3,
            "comment": "See https://www.linkedin.com/legal/user-agreement."
        },
        {
            "line": 4,
            "comment": "LinkedIn may, in its discretion, permit certain automated access to certain LinkedIn pages,"
        }
    ],
    "html": [
        [
            "index",
            "follow",
            "max-image-preview:large",
            "max-snippet:-1",
            "max-video-preview:-1"
        ]
    ],
    "headers": [
        {
            "X-Robots-Tag": [
                "all"
            ]
        }
    ],
}
```

```php
// Get all records
$response->records();

// Get comments
$response->comments();
$response->comments()->count();

// Filter records
$response->records()->filter(fn($record) => $record instanceof \Leopoletto\RobotsTxtParser\Records\UserAgent);
$response->records()->filter(fn($record) => $record instanceof \Leopoletto\RobotsTxtParser\Records\MetaDirective);

// Parse from file
$response = $parser->parseFile('/path/to/robots.txt');

// Parse from text
$response = $parser->parseText($robotsTxtContent);
```

## License

MIT

