<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Performs the two HTTP requests a URL analysis needs.
 *
 * They are deliberately separate calls with distinct targets:
 *
 * - `robots()` fetches the origin's /robots.txt, since robots.txt is
 *   per-origin and never lives at the page's own path.
 * - `page()` fetches **the exact URL the caller supplied** — meta tags and
 *   X-Robots-Tag headers are per-page, so reading them from the home page
 *   instead of the requested page would report the wrong rules for it.
 */
final class RobotsFetcher
{
    public function __construct(
        private readonly Client $client,
        private readonly HttpConfiguration $config = new HttpConfiguration(),
    ) {
    }

    public static function default(HttpConfiguration $config = new HttpConfiguration()): self
    {
        return new self(
            new Client([
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::VERIFY => $config->verify,
            ]),
            $config,
        );
    }

    /**
     * Fetch the origin's robots.txt. The body is left as a stream so the parser
     * can read it incrementally and stop at the size limit.
     */
    public function robots(string $originUrl, string $userAgent): FetchResult
    {
        return $this->send($originUrl, $userAgent, $this->config->robotsTimeout, stream: true);
    }

    /**
     * Fetch the caller's own URL — not the origin — for its headers and HTML.
     */
    public function page(string $pageUrl, string $userAgent): FetchResult
    {
        return $this->send($pageUrl, $userAgent, $this->config->pageTimeout, stream: true);
    }

    private function send(string $url, string $userAgent, int $timeout, bool $stream): FetchResult
    {
        try {
            $response = $this->client->get($url, [
                RequestOptions::HEADERS => ['User-Agent' => $userAgent],
                RequestOptions::STREAM => $stream,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::ALLOW_REDIRECTS => [
                    'max' => $this->config->maxRedirects,
                    'strict' => true,
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                ],
            ]);
        } catch (GuzzleException $e) {
            return FetchResult::failed($url, $e->getMessage());
        }

        return new FetchResult(
            requestedUrl: $url,
            finalUrl: self::finalUrl($url, $response),
            statusCode: $response->getStatusCode(),
            redirects: array_values($response->getHeader('X-Guzzle-Redirect-History')),
            response: $response,
        );
    }

    /**
     * The URL the response actually came from: the last hop of the redirect
     * chain, or the requested URL when there were none.
     */
    private static function finalUrl(string $requested, ResponseInterface $response): string
    {
        $history = $response->getHeader('X-Guzzle-Redirect-History');

        return $history === [] ? $requested : (string) end($history);
    }
}
