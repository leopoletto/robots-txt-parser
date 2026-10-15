<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Leopoletto\RobotsTxtParser\Contract\OriginScopedSitemapProber;
use Leopoletto\RobotsTxtParser\Http\DeadlineStream;
use Leopoletto\RobotsTxtParser\Http\RobotsUrl;
use Throwable;

/**
 * Fetches a declared sitemap far enough to tell whether it is really there.
 *
 * Only the opening bytes are read: enough to see a <urlset> or <sitemapindex>
 * root element, never the whole file, which for a large site can be megabytes.
 */
final class SitemapProbe implements OriginScopedSitemapProber
{
    private const PREVIEW_BYTES = 2048;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param int                     $timeout        Seconds for the whole request, body included.
     * @param int                     $connectTimeout Seconds to connect, where the client honours it.
     * @param (Closure(): float)|null $clock          The current time in seconds; the system clock by default.
     * @param (Closure(string): ?bool)|null $decideElsewhere Whether our bot may fetch a sitemap on another
     *                                                      origin than the robots.txt's, by that origin's
     *                                                      own robots.txt: true to fetch, false when it
     *                                                      forbids it, null when it could not be told.
     *                                                      Without one, such a sitemap is skipped.
     * @param string|null             $origin         The robots.txt URL this probe is scoped to; see forOrigin().
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $userAgent,
        private readonly int $timeout = 8,
        private readonly int $connectTimeout = 5,
        ?Closure $clock = null,
        private readonly ?Closure $decideElsewhere = null,
        private readonly ?string $origin = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * This probe, for the sitemaps the robots.txt at the given URL declares.
     * The robots.txt of another origin is never fetched here: whether such a
     * sitemap may be fetched is for $decideElsewhere to say.
     */
    public function forOrigin(string $robotsUrl): self
    {
        return new self(
            $this->client,
            $this->userAgent,
            $this->timeout,
            $this->connectTimeout,
            $this->clock,
            $this->decideElsewhere,
            RobotsUrl::forOrigin($robotsUrl),
        );
    }

    public function probe(string $url): SitemapProbeResult
    {
        if ($this->origin !== null && strcasecmp(RobotsUrl::forOrigin($url), $this->origin) !== 0) {
            $decision = $this->decideElsewhere !== null ? ($this->decideElsewhere)($url) : null;

            if ($decision === null) {
                return SitemapProbeResult::skipped($url, "it is on a different origin, whose robots.txt was not checked");
            }

            if ($decision === false) {
                return SitemapProbeResult::blocked($url, "the robots.txt of its own origin disallows it for our bot");
            }
        }

        $startedAt = ($this->clock)();

        try {
            $response = $this->client->get($url, [
                RequestOptions::HEADERS => ['User-Agent' => $this->userAgent],
                RequestOptions::STREAM => true,
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => ['max' => 3, 'track_redirects' => true],
            ]);
        } catch (GuzzleException $e) {
            return new SitemapProbeResult($url, null, false, false, error: $e->getMessage());
        }

        $status = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');

        $preview = '';
        $error = null;

        try {
            $body = new DeadlineStream($response->getBody(), $startedAt + $this->timeout, $this->timeout, $this->clock);
            while (! $body->eof() && strlen($preview) < self::PREVIEW_BYTES) {
                $chunk = $body->read(self::PREVIEW_BYTES);
                if ($chunk === '') {
                    break;
                }
                $preview .= $chunk;
            }
            $body->close();
        } catch (Throwable $e) {
            // The status code still stands; the body was not seen in full.
            $error = $e->getMessage();
        }

        return new SitemapProbeResult(
            url: $url,
            statusCode: $status,
            reachable: $status >= 200 && $status < 300,
            looksLikeXml: self::looksLikeSitemap($preview),
            contentType: $contentType !== '' ? $contentType : null,
            error: $error,
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
