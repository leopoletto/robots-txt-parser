<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Leopoletto\RobotsTxtParser\Contract\PageProber;
use Leopoletto\RobotsTxtParser\Extraction\MetaTagExtractor;
use Throwable;

/**
 * Collects the indexing directives that live outside robots.txt: the
 * `X-Robots-Tag` response headers and the robots `<meta>` tags of a page.
 *
 * Both are per-page, so this always inspects the exact URL the caller asked
 * about. The origin's home page would carry different directives, and
 * reporting those as if they governed the requested URL would be wrong.
 */
final class PageInspector implements PageProber
{
    public function __construct(
        private readonly RobotsFetcher $fetcher,
        private readonly string $userAgent,
        private readonly HttpConfiguration $config = new HttpConfiguration(),
        private readonly MetaTagExtractor $meta = new MetaTagExtractor(),
        private readonly ChallengeDetector $challenges = new ChallengeDetector(),
    ) {
    }

    public function probe(string $url): PageProbeResult
    {
        $fetch = $this->fetcher->page($url, $this->userAgent);

        if (! $fetch->succeeded()) {
            return new PageProbeResult($url, $fetch->statusCode, error: $fetch->error);
        }

        // An error page's headers are the error page's, not the page's own
        // directives, so nothing is read from a response that is not 2xx.
        if (! $fetch->isOk()) {
            return new PageProbeResult($url, $fetch->statusCode, challenge: $this->challenges->inHeaders($fetch));
        }

        [$html, $truncated, $error] = $this->readHead($fetch);

        if ($error !== null) {
            return new PageProbeResult($url, $fetch->statusCode, error: $error);
        }

        // The challenge's own noindex is not the page's, so nothing is read.
        if ($this->challenges->isChallenge($fetch, $html)) {
            return new PageProbeResult($url, $fetch->statusCode, challenge: true);
        }

        $metaTags = [];

        foreach ($this->meta->extract($html) as $tag) {
            $metaTags[] = ['name' => $tag->name, 'content' => $tag->raw];
        }

        return new PageProbeResult($url, $fetch->statusCode, $fetch->header('X-Robots-Tag'), $metaTags, truncated: $truncated);
    }

    /**
     * Read enough of the response to cover <head>, then stop.
     *
     * Reading stops early once </head> arrives, so a large page costs only the
     * bytes before it rather than the configured ceiling. A body that fails
     * partway yields an error rather than the part that arrived: a head cut off
     * by a dropped connection would look like a page without directives.
     *
     * @return array{0: string, 1: bool, 2: string|null} The HTML read, whether it was
     *                                                   cut before </head> by the size limit
     *                                                   or partway through a <meta> tag, and
     *                                                   why reading failed, if it did.
     */
    private function readHead(FetchResult $fetch): array
    {
        $body = $fetch->body();
        if ($body === null) {
            return ['', false, null];
        }

        $html = '';
        $headClosed = false;
        $error = null;

        try {
            while (! $body->eof() && strlen($html) < $this->config->maxHtmlBytes) {
                $chunk = $body->read(8 * 1024);
                if ($chunk === '') {
                    break;
                }

                $html .= $chunk;

                if (stripos($html, '</head>') !== false) {
                    $headClosed = true;

                    break;
                }
            }
        } catch (Throwable $e) {
            $error = 'The page stopped responding partway through: ' . $e->getMessage();
        } finally {
            try {
                $body->close();
            } catch (Throwable) {
                // Nothing further to recover from a stream that will not close.
            }
        }

        $truncated = ! $headClosed
            && (strlen($html) >= $this->config->maxHtmlBytes || $this->meta->endsInsideTag($html));

        return [$html, $truncated, $error];
    }
}
