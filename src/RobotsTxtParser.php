<?php

namespace Leopoletto\RobotsTxtParser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Leopoletto\RobotsTxtParser\Collection\RobotsCollection;
use Leopoletto\RobotsTxtParser\Contract\RobotsLineInterface;
use Leopoletto\RobotsTxtParser\Records\Comment;
use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use Leopoletto\RobotsTxtParser\Records\RobotsDirective;
use Leopoletto\RobotsTxtParser\Records\Sitemap;
use Leopoletto\RobotsTxtParser\Records\SyntaxError;
use Leopoletto\RobotsTxtParser\Records\UserAgent;
use RuntimeException;

class RobotsTxtParser
{
    private const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB
    private const MAX_REDIRECTS = 5;
    private const MAX_HTML_SIZE = 5 * 1024 * 1024; // 5MB for HTML (meta tags are in head)
    private const DEFAULT_TIMEOUT = 30;
    private const TIMEOUT_URL = 10;
    private const TIMEOUT_ROBOTS_URL = 10;
    private const CHUNK_SIZE = 8 * 1024; // 8KB
    /** @var array<UserAgent> */
    private array $currentUserAgents = [];
    public ?RobotsDirective $currentDirective = null;

    private Client $httpClient;

    /**
     * Anatomy: Mozilla/5.0 (compatible; Bot Name/Version; Url)
     * Example: Mozilla/5.0 (compatible; RobotsTxtParser/1.0; https://github.com/leopoletto/robots-txt-parser)
     *
     * @var string
     */
    private string $userAgent = '';

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => true,
            ],
            'timeout' => self::DEFAULT_TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * Set custom user agent string directly
     */
    public function setUserAgent(string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Configure user agent with bot name, version, and URL
     * Format: Mozilla/5.0 (compatible; $bot/$version; $url)
     */
    public function configureUserAgent(string $bot, string $version, string $url): void
    {
        $this->userAgent = "Mozilla/5.0 (compatible; {$bot}/{$version}; {$url})";
    }

    /**
     * Get the current user agent string
     */
    private function getUserAgent(): string
    {
        if (strlen($this->userAgent) === 0) {
            throw new RuntimeException('Bot Signature Undefined, Use `configureUserAgent` method to configure.');
        }

        return $this->userAgent;
    }

    /**
     * Parse robots.txt from a URL
     * Downloads the robots.txt file, collects X-Robots-Tag headers, and meta tags
     */
    public function parseUrl(string $url): Response
    {
        // Normalize URL - ensure it ends with /robots.txt or add it
        $robotsUrl = $this->normalizeRobotsUrl($url);

        $size = 0;
        $records = RobotsCollection::build();
        $userAgent = $this->getUserAgent();
        $isNotRobotsTxt = ! str_ends_with($url, 'robots.txt');

        // Reset parser state
        $this->currentUserAgents = [];
        $this->currentDirective = null;

        // Step 1: Request the given URL to get X-Robots-Tag headers and meta tags
        if ($isNotRobotsTxt) {
            try {
                $pageResponse = $this->httpClient->get($url, [
                    RequestOptions::HEADERS => [
                        'User-Agent' => $userAgent,
                    ],
                    RequestOptions::TIMEOUT => self::TIMEOUT_URL,
                ]);

                $statusCode = $pageResponse->getStatusCode();

                // Get X-Robots-Tag headers from the page response
                $xRobotsTags = $pageResponse->getHeader('X-Robots-Tag');
                if (! empty($xRobotsTags)) {
                    $headerDirectives = HeaderDirective::parseXRobotsTagHeaders($xRobotsTags);
                    if (! empty($headerDirectives)) {
                        $records->push(new HeaderDirective($headerDirectives));
                    }
                }

                // Get meta tags from HTML (stream read with limit)
                if ($statusCode === 200) {
                    $body = $pageResponse->getBody();
                    $html = '';
                    $htmlSize = 0;

                    // Only read first part of HTML (meta tags are in <head>, usually first 100KB)
                    // But limit to 5MB to be safe
                    while (! $body->eof() && $htmlSize < self::MAX_HTML_SIZE) {
                        $chunk = $body->read(self::CHUNK_SIZE);

                        // Break if we get an empty chunk (no more data)
                        if ($chunk === '' || $chunk === false) {
                            break;
                        }

                        $html .= $chunk;
                        $htmlSize += strlen($chunk);
                    }

                    // Ensure stream is closed
                    try {
                        $body->close();
                    } catch (\Exception $e) {
                        // Ignore close errors
                    }

                    $metaDirectives = MetaDirective::parseMetaTags($html);
                    if (! empty($metaDirectives)) {
                        $records->push(new MetaDirective($metaDirectives));
                    }

                    // Only count actual size we read
                    $size += $htmlSize;

                    // Free memory
                    unset($html, $body);
                }
            } catch (RequestException $e) {
                // Continue even if page request fails - we still have robots.txt to parse
            }
        }

        // Step 2: Download robots.txt (regardless of whether page request succeeded)
        try {
            $robotsResponse = $this->httpClient->get($robotsUrl, [
                RequestOptions::HEADERS => [
                    'User-Agent' => $userAgent,
                ],
                RequestOptions::STREAM => true,
                RequestOptions::TIMEOUT => self::TIMEOUT_ROBOTS_URL,
                RequestOptions::ALLOW_REDIRECTS => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    'referer' => true,
                    'track_redirects' => true,
                ],
            ]);

            if ($robotsResponse->getHeader('X-Guzzle-Redirect-History')) {
                $redirectHistory = $robotsResponse->getHeader('X-Guzzle-Redirect-History');
                if (count($redirectHistory) >= self::MAX_REDIRECTS) {
                    $records->push(new SyntaxError(0, 'Redirect chain exceeds ' . self::MAX_REDIRECTS . ' redirects limit'));

                    return new Response($records, $size);
                }
            }

            // Get X-Robots-Tag headers from robots.txt response (if any)
            $xRobotsTags = $robotsResponse->getHeader('X-Robots-Tag');
            if (! empty($xRobotsTags)) {
                $headerDirectives = HeaderDirective::parseXRobotsTagHeaders($xRobotsTags);
                if (! empty($headerDirectives)) {
                    $records->push(new HeaderDirective($headerDirectives));
                }
            }

            // Parse robots.txt content (stream reading line by line)
            $body = $robotsResponse->getBody();
            $contentSize = 0;
            $buffer = '';
            $lineNumber = 0;

            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_SIZE); // Read in 8KB chunks

                // Break if we get an empty chunk and buffer is empty (stream ended)
                if (($chunk === '' || $chunk === false) && $buffer === '') {
                    break;
                }

                // If we got empty chunk but have buffer data, process buffer and check once more
                if ($chunk === '' || $chunk === false) {
                    // Process any remaining buffer and then break
                    break;
                }

                $buffer .= $chunk;
                $contentSize += strlen($chunk);

                if ($contentSize > self::MAX_FILE_SIZE) {
                    try {
                        $body->close();
                    } catch (\Exception $e) {
                        // Ignore close errors
                    }

                    throw new \RuntimeException('Robots.txt file size exceeds 500MB limit');
                }

                // Process complete lines from buffer
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $lineNumber++;
                    $line = rtrim($line, "\r\n");

                    // Skip empty lines
                    if (trim($line) === '') {
                        continue;
                    }

                    // Parse the line and add to records
                    $parsed = $this->parseLine($line, $lineNumber);
                    if ($parsed !== null) {
                        if ($parsed instanceof UserAgent) {
                            // Check if the last record was also a User-agent
                            $lastRecord = $records->last();
                            if ($lastRecord instanceof UserAgent) {
                                // Consecutive User-agent lines - add to current group
                                $this->currentUserAgents[] = $parsed;
                            } else {
                                // New User-agent group - reset array
                                $this->currentUserAgents = [$parsed];
                            }
                            $records->push($parsed);
                        } else {
                            if ($parsed instanceof RobotsDirective) {
                                $this->currentDirective = $parsed;
                            }
                            $records->push($parsed);
                        }
                    }
                }
            }

            // Process remaining buffer
            if (! empty(trim($buffer))) {
                $lineNumber++;
                $line = rtrim($buffer, "\r\n");
                if (trim($line) !== '') {
                    $parsed = $this->parseLine($line, $lineNumber);
                    if ($parsed !== null) {
                        if ($parsed instanceof UserAgent) {
                            // Check if the last record was also a User-agent
                            $lastRecord = $records->last();
                            if ($lastRecord instanceof UserAgent) {
                                // Consecutive User-agent lines - add to current group
                                $this->currentUserAgents[] = $parsed;
                            } else {
                                // New User-agent group - reset array
                                $this->currentUserAgents = [$parsed];
                            }
                            $records->push($parsed);
                        } else {
                            if ($parsed instanceof RobotsDirective) {
                                $this->currentDirective = $parsed;
                            }
                            $records->push($parsed);
                        }
                    }
                }
            }

            // Ensure stream is closed
            try {
                $body->close();
            } catch (\Exception $e) {
                // Ignore close errors
            }

            $size += $contentSize;

            // Free memory
            unset($buffer, $body);

        } catch (RequestException $e) {
            // Continue even if robots.txt download fails - return whatever we collected
        } catch (\RuntimeException $e) {
            // Re-throw size limit exceptions and other critical errors
            throw $e;
        }

        return new Response($records, $size);
    }

    /**
     * Parse robots.txt from a file path
     */
    public function parseFile(string $filePath): Response
    {
        $records = RobotsCollection::build();
        $lineNumber = 0;

        // Reset parser state
        $this->currentUserAgents = [];
        $this->currentDirective = null;

        if (! file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new \RuntimeException("File is not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $records->push(new SyntaxError(0, 'File size exceeds 500MB limit'));

            return new Response($records, $fileSize);
        }

        // Read file line by line to save memory
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$filePath}");
        }


        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = rtrim($line, "\r\n");

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Parse the line
            $parsed = $this->parseLine($line, $lineNumber);
            if ($parsed !== null) {
                if ($parsed instanceof UserAgent) {
                    // Check if the last record was also a User-agent
                    $lastRecord = $records->last();
                    if ($lastRecord instanceof UserAgent) {
                        // Consecutive User-agent lines - add to current group
                        $this->currentUserAgents[] = $parsed;
                    } else {
                        // New User-agent group - reset array
                        $this->currentUserAgents = [$parsed];
                    }
                    $records->push($parsed);
                } else {
                    if ($parsed instanceof RobotsDirective) {
                        $this->currentDirective = $parsed;
                    }
                    $records->push($parsed);
                }
            }
        }

        fclose($handle);

        return new Response($records, $fileSize);
    }

    /**
     * Parse robots.txt from text content
     */
    public function parseText(string $content): Response
    {
        $size = strlen($content);

        $records = RobotsCollection::build();
        $lineNumber = 0;

        // Reset parser state
        $this->currentUserAgents = [];
        $this->currentDirective = null;

        if ($size > self::MAX_FILE_SIZE) {
            $records->push(new SyntaxError(0, 'Content size exceeds 500MB limit'));

            return new Response($records, $size);
        }

        // Use string stream for memory-efficient line-by-line processing
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not create memory stream');
        }

        fwrite($handle, $content);
        rewind($handle);

        // Free original content immediately
        unset($content);

        $lines = [];
        $firstLine = null;
        while (($line = fgets($handle)) !== false) {
            if ($firstLine !== null && str_ends_with($line, $firstLine)) {
                continue;
            }
            if ($firstLine === null) {
                $firstLine = $line;
            }

            $line = rtrim($line, "\r\n");

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            $line = strtolower(trim($line));
            if (in_array($line, $lines)) {
                continue;
            }
            $lineNumber++;
            $lines[] = $line;

            // Parse the line
            $parsed = $this->parseLine($line, $lineNumber);
            if ($parsed !== null) {
                if ($parsed instanceof UserAgent) {
                    // Check if the last record was also a User-agent
                    $lastRecord = $records->last();
                    if ($lastRecord instanceof UserAgent) {
                        // Consecutive User-agent lines - add to current group
                        $this->currentUserAgents[] = $parsed;
                    } else {
                        // New User-agent group - reset array
                        $this->currentUserAgents = [$parsed];
                    }
                    $records->push($parsed);
                } else {
                    if ($parsed instanceof RobotsDirective) {
                        $this->currentDirective = $parsed;
                    }
                    $records->push($parsed);
                }
            }
        }

        fclose($handle);

        return new Response($records, $size);
    }

    /**
     * Parse a single line and return the appropriate record object(s)
     *
     * @param string $line
     * @param int $lineNumber
     * @return RobotsLineInterface|array<RobotsLineInterface>|null
     */
    private function parseLine(string $line, int $lineNumber): RobotsLineInterface|array|null
    {
        // Parse comment
        if (Comment::isComment($line)) {
            return Comment::parse($line, $lineNumber);
        }

        // Parse sitemap
        if (Sitemap::isSitemap($line)) {
            return Sitemap::parse($line, $lineNumber);
        }

        // Parse user agent
        if (UserAgent::isUserAgent($line)) {
            return UserAgent::parse($line, $lineNumber);
        }

        // Parse directive (must follow a user agent)
        if (RobotsDirective::isDirective($line)) {
            if (empty($this->currentUserAgents)) {
                return new SyntaxError($lineNumber, 'Directive must follow a user agent');
            }

            // Store directive only once with the first user agent in the group
            // The collection will expand it on-demand when querying by user agent
            return RobotsDirective::parse($line, $lineNumber, $this->currentUserAgents[0]);
        }

        return null;
    }

    /**
     * Normalize URL to robots.txt location
     */
    private function normalizeRobotsUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) . '/robots.txt';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $robotsUrl = $scheme . '://' . $host;

        return $robotsUrl;
    }
}
