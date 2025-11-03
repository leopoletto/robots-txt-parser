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

// Parse from URL
$response = $parser->parseUrl('https://example.com');

// Get file size
$response->size();

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

