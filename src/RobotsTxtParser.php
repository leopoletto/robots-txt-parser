<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser;

use Leopoletto\RobotsTxtParser\Agents\ShardedAgentRepository;
use Leopoletto\RobotsTxtParser\Contract\AgentRepository;
use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Contract\Source;
use Leopoletto\RobotsTxtParser\Exception\MissingUserAgent;
use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
use Leopoletto\RobotsTxtParser\Http\BotSignature;
use Leopoletto\RobotsTxtParser\Http\FetchResult;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\PageInspector;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Http\RobotsUrl;
use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Parsing\ContentBuffer;
use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Parsing\DocumentParser;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\Source\FileSource;
use Leopoletto\RobotsTxtParser\Source\StreamSource;
use Leopoletto\RobotsTxtParser\Source\TextSource;

/**
 * Parses robots.txt from a URL, a file, or a string.
 *
 * All three routes run the same parser over the same abstraction, so a
 * document produces identical records however it arrives.
 */
final class RobotsTxtParser
{
    private readonly DocumentParser $parser;

    private ?BotSignature $signature = null;

    private bool $keepContent = false;

    public function __construct(
        private readonly HttpConfiguration $config = new HttpConfiguration(),
        ?AgentRepository $agents = null,
        private ?RobotsFetcher $fetcher = null,
    ) {
        $this->parser = new DocumentParser($agents ?? new ShardedAgentRepository());
    }

    /**
     * Identify this bot as Mozilla/5.0 (compatible; $bot/$version; $url).
     */
    public function withBotSignature(string $bot, string $version, string $url): self
    {
        $this->signature = BotSignature::of($bot, $version, $url);

        return $this;
    }

    /**
     * Use a user-agent string verbatim.
     */
    public function withUserAgent(string $userAgent): self
    {
        $this->signature = BotSignature::raw($userAgent);

        return $this;
    }

    /**
     * Keep the raw document so it can be read back from Response::content().
     */
    public function keepContent(bool $keep = true): self
    {
        $this->keepContent = $keep;

        return $this;
    }

    public function parseText(string $content): Response
    {
        return $this->parseSource(new TextSource($content, $this->config->maxBytes));
    }

    public function parseFile(string $path): Response
    {
        return $this->parseSource(new FileSource($path, $this->config->maxBytes));
    }

    /**
     * Analyse a URL: fetch the origin's robots.txt, then — only if those rules
     * allow it — fetch the URL itself for its meta tags and X-Robots-Tag
     * headers.
     */
    public function parseUrl(string $url): Response
    {
        $signature = $this->signature ?? throw MissingUserAgent::make();

        $url = RobotsUrl::withScheme($url);
        $robotsUrl = RobotsUrl::forOrigin($url);

        $fetch = $this->fetcher()->robots($robotsUrl, $signature->value);

        if (! $fetch->succeeded()) {
            return new Response(
                document: new Document([new Issue(0, "Could not fetch {$robotsUrl}: {$fetch->error}", Severity::High, 'fetch_failed')], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                error: $fetch->error,
            );
        }

        if ($fetch->exceededRedirectLimit($this->config->maxRedirects)) {
            return new Response(
                document: new Document([new Issue(
                    0,
                    "Redirect chain exceeds the {$this->config->maxRedirects} redirect limit",
                    Severity::High,
                    'too_many_redirects',
                )], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                finalUrl: $fetch->finalUrl,
                redirects: $fetch->redirects,
                statusCode: $fetch->statusCode,
            );
        }

        $body = $fetch->body();
        if ($body === null) {
            return new Response(
                document: new Document([new Issue(
                    0,
                    "Response from {$robotsUrl} carried no body",
                    Severity::High,
                    'empty_response',
                )], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                finalUrl: $fetch->finalUrl,
                redirects: $fetch->redirects,
                statusCode: $fetch->statusCode,
            );
        }

        $buffer = $this->keepContent ? new ContentBuffer() : null;
        $document = $this->parser->parse(new StreamSource($body, $this->config->maxBytes), $buffer);

        // X-Robots-Tag on the robots.txt response itself.
        $document = $document->withRecords(
            (new HeaderExtractor())->extract($fetch->header('X-Robots-Tag'), origin: 'robots.txt')
        );

        [$document, $pageStatus, $decision] = $this->inspectPage($url, $document, $signature, $fetch);

        return new Response(
            document: $document,
            content: $buffer?->content(),
            requestedUrl: $url,
            robotsUrl: $robotsUrl,
            finalUrl: $fetch->finalUrl,
            redirects: $fetch->redirects,
            statusCode: $fetch->statusCode,
            pageStatusCode: $pageStatus,
            pageDecision: $decision,
        );
    }

    /**
     * Fetch the requested page for its own directives — but only when the
     * robots.txt we just parsed permits it.
     *
     * Two rules govern this deliberately:
     *
     * 1. The page inspected is the URL the caller gave, never the origin's home
     *    page. Meta tags and X-Robots-Tag are per-page.
     * 2. If robots.txt disallows that URL for our product token, we do not
     *    request it. A tool that reports on robots.txt has no business
     *    ignoring one.
     *
     * @return array{0: Document, 1: int|null, 2: \Leopoletto\RobotsTxtParser\Matching\Decision|null}
     */
    private function inspectPage(
        string $url,
        Document $document,
        BotSignature $signature,
        FetchResult $robotsFetch,
    ): array {
        // The URL already is the robots.txt; there is no separate page.
        if (RobotsUrl::isRobotsTxt($url)) {
            return [$document, null, null];
        }

        $decision = $document->decide($signature->productToken, RobotsUrl::targetPath($url));

        if (! $decision->allowed) {
            // A disallowed decision names the rule behind it; line 0 stands in
            // for the theoretical case of a denial with no rule attached.
            $rule = $decision->rule;

            return [$document->withRecords([new Issue(
                $rule !== null ? $rule->line : 0,
                "Page not fetched: robots.txt disallows {$decision->path} for {$signature->productToken}, "
                    . 'so meta tags and page headers were not read',
                Severity::Low,
                'page_disallowed',
            )]), null, $decision];
        }

        $inspection = (new PageInspector($this->fetcher(), $this->config))->inspect($url, $signature);

        /** @var list<Record> $records */
        $records = $inspection['records'];

        return [
            $document->withRecords($records),
            $inspection['fetch']->statusCode,
            $decision,
        ];
    }

    private function parseSource(Source $source): Response
    {
        $buffer = $this->keepContent ? new ContentBuffer() : null;

        return new Response(
            document: $this->parser->parse($source, $buffer),
            content: $buffer?->content(),
        );
    }

    private function fetcher(): RobotsFetcher
    {
        return $this->fetcher ??= RobotsFetcher::default($this->config);
    }
}
