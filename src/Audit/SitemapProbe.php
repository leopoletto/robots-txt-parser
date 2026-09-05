<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Fetches a declared sitemap far enough to tell whether it is really there.
 *
 * Only the opening bytes are read: enough to see a <urlset> or <sitemapindex>
 * root element, never the whole file, which for a large site can be megabytes.
 */
final class SitemapProbe
{
    private const PREVIEW_BYTES = 2048;

    public function __construct(
        private readonly Client $client,
        private readonly string $userAgent,
        private readonly int $timeout = 8,
    ) {
    }

    public function probe(string $url): SitemapProbeResult
    {
        try {
            $response = $this->client->get($url, [
                RequestOptions::HEADERS => ['User-Agent' => $this->userAgent],
                RequestOptions::STREAM => true,
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => ['max' => 3, 'track_redirects' => true],
            ]);
        } catch (GuzzleException $e) {
            return new SitemapProbeResult($url, null, false, false, error: $e->getMessage());
        }

        $status = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');

        $preview = '';

        try {
            $body = $response->getBody();
            while (! $body->eof() && strlen($preview) < self::PREVIEW_BYTES) {
                $chunk = $body->read(self::PREVIEW_BYTES);
                if ($chunk === '') {
                    break;
                }
                $preview .= $chunk;
            }
            $body->close();
        } catch (Throwable) {
            // A truncated read still tells us the status code.
        }

        return new SitemapProbeResult(
            url: $url,
            statusCode: $status,
            reachable: $status >= 200 && $status < 300,
            looksLikeXml: self::looksLikeSitemap($preview),
            contentType: $contentType !== '' ? $contentType : null,
        );
    }

    /**
     * A sitemap opens with a urlset or sitemapindex root, possibly after an XML
     * declaration, a BOM or whitespace. Gzipped sitemaps are accepted on their
     * magic bytes rather than decompressed.
     */
    private static function looksLikeSitemap(string $preview): bool
    {
        if (str_starts_with($preview, "\x1f\x8b")) {
            return true;
        }

        $head = ltrim($preview, "\xEF\xBB\xBF \t\r\n");

        return stripos($head, '<urlset') !== false
            || stripos($head, '<sitemapindex') !== false;
    }
}
