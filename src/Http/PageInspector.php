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
    ) {
    }

    public function probe(string $url): PageProbeResult
    {
        $fetch = $this->fetcher->page($url, $this->userAgent);

        if (! $fetch->succeeded()) {
            return new PageProbeResult($url, $fetch->statusCode, error: $fetch->error);
        }

        $metaTags = [];

        if ($fetch->isOk()) {
            foreach ($this->meta->extract($this->readHead($fetch)) as $tag) {
                $metaTags[] = ['name' => $tag->name, 'content' => $tag->raw];
            }
        }

        return new PageProbeResult($url, $fetch->statusCode, $fetch->header('X-Robots-Tag'), $metaTags);
    }

    /**
     * Read enough of the response to cover <head>, then stop.
     *
     * Reading stops early once </head> arrives, so a large page costs only the
     * bytes before it rather than the configured ceiling.
     */
    private function readHead(FetchResult $fetch): string
    {
        $body = $fetch->body();
        if ($body === null) {
            return '';
        }

        $html = '';

        try {
            while (! $body->eof() && strlen($html) < $this->config->maxHtmlBytes) {
                $chunk = $body->read(8 * 1024);
                if ($chunk === '') {
                    break;
                }

                $html .= $chunk;

                if (stripos($html, '</head>') !== false) {
                    break;
                }
            }
        } catch (Throwable) {
            // A body that stops mid-read still yields whatever arrived.
        } finally {
            try {
                $body->close();
            } catch (Throwable) {
                // Nothing further to recover from a stream that will not close.
            }
        }

        return $html;
    }
}
