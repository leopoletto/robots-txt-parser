<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
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
final class PageInspector
{
    public function __construct(
        private readonly RobotsFetcher $fetcher,
        private readonly HttpConfiguration $config = new HttpConfiguration(),
        private readonly HeaderExtractor $headers = new HeaderExtractor(),
        private readonly MetaTagExtractor $meta = new MetaTagExtractor(),
    ) {
    }

    /**
     * @return array{records: list<Record>, fetch: FetchResult}
     */
    public function inspect(string $pageUrl, BotSignature $signature): array
    {
        $fetch = $this->fetcher->page($pageUrl, $signature->value);

        if (! $fetch->succeeded()) {
            return ['records' => [], 'fetch' => $fetch];
        }

        $records = $this->headers->extract($fetch->header('X-Robots-Tag'), origin: 'page');

        if ($fetch->isOk()) {
            foreach ($this->meta->extract($this->readHead($fetch)) as $metaDirective) {
                $records[] = $metaDirective;
            }
        }

        return ['records' => $records, 'fetch' => $fetch];
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
