<?php

namespace Leopoletto\RobotsTxtParser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Records\Comment;
use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use Leopoletto\RobotsTxtParser\Records\RobotsDirective;
use Leopoletto\RobotsTxtParser\Records\Sitemap;
use Leopoletto\RobotsTxtParser\Records\UserAgent;

class RobotsTxtParser
{
    private const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB
    private const MAX_REDIRECTS = 5;
    private const MAX_HTML_SIZE = 5 * 1024 * 1024; // 5MB for HTML (meta tags are in head)

    private Client $httpClient;
    private string $userAgent = 'Mozilla/5.0 (compatible; RobotsTxtParser/1.0; https://github.com/leopoletto/robots-txt-parser)';
    private ?string $botName = null;
    private ?string $botVersion = null;
    private ?string $botUrl = null;

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => true
            ],
            'timeout' => 30,
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
        $this->botName = $bot;
        $this->botVersion = $version;
        $this->botUrl = $url;
        $this->userAgent = "Mozilla/5.0 (compatible; {$bot}/{$version}; {$url})";
    }

    /**
     * Get the current user agent string
     */
    private function getUserAgent(): string
    {
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
        $records = new Collection();
        $userAgent = $this->getUserAgent();

        // Step 1: Request the given URL to get X-Robots-Tag headers and meta tags
        try {
            $pageResponse = $this->httpClient->get($url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $pageResponse->getStatusCode();
            
            // Get X-Robots-Tag headers from the page response
            $xRobotsTags = $pageResponse->getHeader('X-Robots-Tag');
            if (!empty($xRobotsTags)) {
                $headerDirectives = $this->parseXRobotsTagHeaders($xRobotsTags);
                if (!empty($headerDirectives)) {
                    $records->push(new HeaderDirective($headerDirectives));
                }
            }

            // Get meta tags from HTML (stream read with limit)
            if ($statusCode === 200) {
                $body = $pageResponse->getBody();
                $html = '';
                $htmlSize = 0;
                
                // Only read first part of HTML (meta tags are in <head>, usually first 100KB)
                // But we'll read up to 5MB to be safe
                while (!$body->eof() && $htmlSize < self::MAX_HTML_SIZE) {
                    $chunk = $body->read(8192);
                    
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
                
                $metaDirectives = $this->parseMetaTags($html);
                if (!empty($metaDirectives)) {
                    $records->push(new MetaDirective($metaDirectives));
                }
                
                // Only count actual size we read
                $size += $htmlSize;
                
                // Free memory
                unset($html, $body);
            }

        } catch (RequestException $e) {
            // Continue even if page request fails
        }

        // Step 2: Download robots.txt (regardless of whether page request succeeded)
        try {
            $robotsResponse = $this->httpClient->get($robotsUrl, [
                'headers' => [
                    'User-Agent' => $userAgent,
                ],
                'stream' => true,
            ]);

            // Get X-Robots-Tag headers from robots.txt response (if any)
            $xRobotsTags = $robotsResponse->getHeader('X-Robots-Tag');
            if (!empty($xRobotsTags)) {
                $headerDirectives = $this->parseXRobotsTagHeaders($xRobotsTags);
                if (!empty($headerDirectives)) {
                    $records->push(new HeaderDirective($headerDirectives));
                }
            }

            // Parse robots.txt content (stream reading line by line)
            $body = $robotsResponse->getBody();
            $contentSize = 0;
            $buffer = '';
            $lineNumber = 0;
            $currentUserAgent = null;

            while (!$body->eof()) {
                $chunk = $body->read(8192); // Read in 8KB chunks
                
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
                    $parsed = $this->parseLine($line, $lineNumber, $currentUserAgent);
                    if ($parsed !== null) {
                        if ($parsed instanceof UserAgent) {
                            $currentUserAgent = $parsed->userAgent;
                        }
                        $records->push($parsed);
                    }
                }
            }

            // Process remaining buffer
            if (!empty(trim($buffer))) {
                $lineNumber++;
                $line = rtrim($buffer, "\r\n");
                if (trim($line) !== '') {
                    $parsed = $this->parseLine($line, $lineNumber, $currentUserAgent);
                    if ($parsed !== null) {
                        $records->push($parsed);
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
            // Continue even if robots.txt download fails
        } catch (\RuntimeException $e) {
            // Re-throw size limit exceptions
            throw $e;
        }

        return new Response($records, $size);
    }

    /**
     * Parse robots.txt from a file path
     */
    public function parseFile(string $filePath): Response
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File is not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File size exceeds 500MB limit');
        }

        // Read file line by line to save memory
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$filePath}");
        }

        $records = new Collection();
        $lineNumber = 0;
        $currentUserAgent = null;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = rtrim($line, "\r\n");

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Parse the line
            $parsed = $this->parseLine($line, $lineNumber, $currentUserAgent);
            if ($parsed !== null) {
                if ($parsed instanceof UserAgent) {
                    $currentUserAgent = $parsed->userAgent;
                }
                $records->push($parsed);
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
        if ($size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('Content size exceeds 500MB limit');
        }

        $records = new Collection();
        $lineNumber = 0;
        $currentUserAgent = null;
        
        // Use string stream for memory-efficient line-by-line processing
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not create memory stream');
        }
        
        fwrite($handle, $content);
        rewind($handle);
        
        // Free original content immediately
        unset($content);
        
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = rtrim($line, "\r\n");

            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }

            // Parse the line
            $parsed = $this->parseLine($line, $lineNumber, $currentUserAgent);
            if ($parsed !== null) {
                if ($parsed instanceof UserAgent) {
                    $currentUserAgent = $parsed->userAgent;
                }
                $records->push($parsed);
            }
        }
        
        fclose($handle);

        return new Response($records, $size);
    }

    /**
     * Parse a single line and return the appropriate record object
     * 
     * @param string $line
     * @param int $lineNumber
     * @param string|null $currentUserAgent Reference to current user agent (may be updated)
     * @return Comment|Sitemap|UserAgent|RobotsDirective|null
     */
    private function parseLine(string $line, int $lineNumber, ?string &$currentUserAgent): Comment|Sitemap|UserAgent|RobotsDirective|null
    {
        // Parse comment
        if ($this->isComment($line)) {
            return $this->parseComment($line, $lineNumber);
        }

        // Parse sitemap
        if ($this->isSitemap($line)) {
            return $this->parseSitemap($line, $lineNumber);
        }

        // Parse user agent
        if ($this->isUserAgent($line)) {
            $userAgent = $this->parseUserAgent($line, $lineNumber);
            if ($userAgent) {
                $currentUserAgent = $userAgent->userAgent;
                return $userAgent;
            }
            return null;
        }

        // Parse directive (must follow a user agent)
        if ($currentUserAgent !== null && $this->isDirective($line)) {
            return $this->parseDirective($line, $lineNumber);
        }

        return null;
    }

    /**
     * Normalize URL to robots.txt location
     */
    private function normalizeRobotsUrl(string $url): string
    {
        $url = rtrim($url, '/');
        
        // If URL already ends with robots.txt, return as is
        if (str_ends_with(strtolower($url), '/robots.txt')) {
            return $url;
        }

        // Otherwise, append /robots.txt
        return $url . '/robots.txt';
    }

    /**
     * Check if line is a comment
     */
    private function isComment(string $line): bool
    {
        $trimmed = trim($line);
        return str_starts_with($trimmed, '#') && !str_contains($trimmed, '#', 1);
    }

    /**
     * Parse comment line
     */
    private function parseComment(string $line, int $lineNumber): ?Comment
    {
        $trimmed = trim($line);
        if (strlen($trimmed) < 2) {
            return null;
        }

        $comment = substr($trimmed, 1); // Remove the #
        return new Comment($lineNumber, trim($comment));
    }

    /**
     * Check if line is a sitemap
     */
    private function isSitemap(string $line): bool
    {
        return str_starts_with(strtolower(trim($line)), 'sitemap:');
    }

    /**
     * Parse sitemap line
     */
    private function parseSitemap(string $line, int $lineNumber): ?Sitemap
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $url = trim($parts[1]);
        $valid = filter_var($url, FILTER_VALIDATE_URL) !== false && str_ends_with(strtolower($url), '.xml');

        return new Sitemap($lineNumber, $url, $valid);
    }

    /**
     * Check if line is a user agent
     */
    private function isUserAgent(string $line): bool
    {
        return str_starts_with(strtolower(trim($line)), 'user-agent:');
    }

    /**
     * Parse user agent line
     */
    private function parseUserAgent(string $line, int $lineNumber): ?UserAgent
    {
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $userAgent = trim($parts[1]);
        if ($userAgent === '') {
            return null;
        }

        return new UserAgent($lineNumber, $userAgent);
    }

    /**
     * Check if line is a directive
     */
    private function isDirective(string $line): bool
    {
        $trimmed = strtolower(trim($line));
        return str_starts_with($trimmed, 'allow:') 
            || str_starts_with($trimmed, 'disallow:') 
            || str_starts_with($trimmed, 'crawl-delay:');
    }

    /**
     * Parse directive line
     */
    private function parseDirective(string $line, int $lineNumber): ?RobotsDirective
    {
        $trimmed = trim($line);
        $lowerTrimmed = strtolower($trimmed);
        
        if (str_starts_with($lowerTrimmed, 'allow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new RobotsDirective($lineNumber, 'allow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'disallow:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new RobotsDirective($lineNumber, 'disallow', $path);
        }

        if (str_starts_with($lowerTrimmed, 'crawl-delay:')) {
            $parts = explode(':', $trimmed, 2);
            $path = count($parts) === 2 ? trim($parts[1]) : '';
            return new RobotsDirective($lineNumber, 'crawl-delay', $path);
        }

        return null;
    }

    /**
     * Parse X-Robots-Tag HTTP headers
     * 
     * @param array<string> $xRobotsTags
     * @return array<string>
     */
    private function parseXRobotsTagHeaders(array $xRobotsTags): array
    {
        $directives = [];

        foreach ($xRobotsTags as $tag) {
            // Split by comma if multiple directives in one header
            $parts = array_map('trim', explode(',', $tag));
            
            foreach ($parts as $part) {
                // Check if it has user agent prefix (e.g., "googlebot: noindex")
                if (strpos($part, ':') !== false) {
                    $directiveParts = explode(':', $part, 2);
                    if (count($directiveParts) === 2) {
                        $directives[] = trim($directiveParts[1]);
                    }
                } else {
                    $directives[] = $part;
                }
            }
        }

        return array_unique($directives);
    }

    /**
     * Parse robots meta tags from HTML
     * Supports both attribute orders and self-closing tags
     * 
     * @return array<string>
     */
    private function parseMetaTags(string $html): array
    {
        $directives = [];

        // Match <meta name="robots|googlebot|googlebot-news" content="..."> or <meta content="..." name="...">
        // Handles both attribute orders and self-closing tags
        $pattern = '/<meta\s+(?:name=["\'](robots|googlebot|googlebot-news)["\']\s+content=["\']([^"\']+)["\']|content=["\']([^"\']+)["\']\s+name=["\'](robots|googlebot|googlebot-news)["\'])\s*\/?>/i';
        
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Check which attribute order was matched
                if (!empty($match[2])) {
                    // name first, content second
                    $content = trim($match[2]);
                } elseif (!empty($match[3])) {
                    // content first, name second
                    $content = trim($match[3]);
                } else {
                    continue;
                }

                // Split by comma if multiple directives
                $parts = array_map('trim', explode(',', $content));
                $directives = array_merge($directives, $parts);
            }
        }

        return array_unique($directives);
    }
}

