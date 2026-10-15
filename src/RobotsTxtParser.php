<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser;

use Leopoletto\RobotsTxtParser\Agents\ShardedAgentRepository;
use Leopoletto\RobotsTxtParser\Contract\AgentRepository;
use Leopoletto\RobotsTxtParser\Contract\PageProber;
use Leopoletto\RobotsTxtParser\Contract\Source;
use Leopoletto\RobotsTxtParser\Exception\MissingUserAgent;
use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
use Leopoletto\RobotsTxtParser\Http\BotSignature;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\PageInspector;
use Leopoletto\RobotsTxtParser\Http\PageProbeResult;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Http\RobotsUrl;
use Leopoletto\RobotsTxtParser\Matching\Decision;
use Leopoletto\RobotsTxtParser\Model\Severity;
use Leopoletto\RobotsTxtParser\Parsing\ContentBuffer;
use Leopoletto\RobotsTxtParser\Parsing\Document;
use Leopoletto\RobotsTxtParser\Parsing\DocumentParser;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\Source\FileSource;
use Leopoletto\RobotsTxtParser\Source\StreamSource;
use Leopoletto\RobotsTxtParser\Source\TextSource;
use RuntimeException;

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

    private ?PageProber $pageProber = null;

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

    /**
     * Ask this prober for a page's indexing directives instead of requesting
     * the page, for instance to replay what an earlier fetch found.
     */
    public function withPageProber(PageProber $prober): self
    {
        $this->pageProber = $prober;

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
     * Analyse a URL from a robots.txt already in hand, such as one stored
     * from an earlier fetch, without requesting the file again.
     *
     * Whether our bot may fetch the page is decided afresh from the given
     * content, exactly as parseUrl() does. The page's own directives are asked
     * of the prober set with withPageProber(); with none set the page is
     * requested, so pass one that replays a stored result to stay offline.
     *
     * @param int|null     $size    The size the file had when it was fetched, when
     *                              known. Content kept by keepContent() has normalised
     *                              line endings and measures less than the original.
     * @param list<string> $headers The X-Robots-Tag values sent with the robots.txt
     *                              itself, which are not part of the file.
     */
    public function parseTextForUrl(string $content, string $url, ?int $size = null, array $headers = []): Response
    {
        $signature = $this->signature ?? throw MissingUserAgent::make();

        $url = RobotsUrl::withScheme($url);

        $buffer = $this->keepContent ? new ContentBuffer() : null;
        $document = $this->parser->parse(new TextSource($content, $this->config->maxBytes), $buffer);

        if ($size !== null) {
            $document = $document->withSize($size);
        }

        // In the place parseUrl() puts them: before anything the page sends.
        $document = $document->withRecords((new HeaderExtractor())->extract($headers, origin: 'robots.txt'));

        [$document, $pageStatus, $decision, $probe] = $this->inspectPage($url, $document, $signature);

        return new Response(
            document: $document,
            content: $buffer?->content(),
            requestedUrl: $url,
            robotsUrl: RobotsUrl::forOrigin($url),
            pageStatusCode: $pageStatus,
            pageDecision: $decision,
            pageProbe: $probe,
        );
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

        // Guzzle throws past the limit, so this is a guard rather than a path
        // taken in practice. Either way no body was read, so it is an error:
        // an empty document here would pass for a file with no rules.
        if ($fetch->exceededRedirectLimit($this->config->maxRedirects)) {
            $reason = "Redirect chain exceeds the {$this->config->maxRedirects} redirect limit";

            return new Response(
                document: new Document([new Issue(0, $reason, Severity::High, 'too_many_redirects')], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                finalUrl: $fetch->finalUrl,
                redirects: $fetch->redirects,
                statusCode: $fetch->statusCode,
                error: $reason,
            );
        }

        $body = $fetch->body();
        if ($body === null) {
            $reason = "Response from {$robotsUrl} carried no body";

            return new Response(
                document: new Document([new Issue(0, $reason, Severity::High, 'empty_response')], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                finalUrl: $fetch->finalUrl,
                redirects: $fetch->redirects,
                statusCode: $fetch->statusCode,
                error: $reason,
            );
        }

        $buffer = $this->keepContent ? new ContentBuffer() : null;

        // A body that stops partway, or runs past the timeout, is not a
        // shorter file: what arrived is discarded rather than parsed as whole.
        try {
            $document = $this->parser->parse(new StreamSource($body, $this->config->maxBytes), $buffer);
        } catch (RuntimeException $e) {
            $reason = "{$robotsUrl} stopped responding partway through: {$e->getMessage()}";

            return new Response(
                document: new Document([new Issue(0, "Could not fetch {$robotsUrl}: {$reason}", Severity::High, 'fetch_failed')], []),
                requestedUrl: $url,
                robotsUrl: $robotsUrl,
                finalUrl: $fetch->finalUrl,
                redirects: $fetch->redirects,
                statusCode: $fetch->statusCode,
                error: $reason,
            );
        }

        // X-Robots-Tag on the robots.txt response itself.
        $document = $document->withRecords(
            (new HeaderExtractor())->extract($fetch->header('X-Robots-Tag'), origin: 'robots.txt')
        );

        [$document, $pageStatus, $decision, $probe] = $this->inspectPage($url, $document, $signature, $fetch->statusCode);

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
            pageProbe: $probe,
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
     * 3. If robots.txt answered with a server error, we do not request it
     *    either. RFC 9309 §2.3.1.4 treats the file as unreachable and every
     *    path as disallowed. 429 counts as one too: the site asked us to stop.
     *
     * @param int|null $robotsStatus The status robots.txt answered with; null
     *                               when the file was not fetched just now.
     *
     * @return array{0: Document, 1: int|null, 2: Decision|null, 3: PageProbeResult|null}
     */
    private function inspectPage(string $url, Document $document, BotSignature $signature, ?int $robotsStatus = null): array
    {
        // The URL already is the robots.txt; there is no separate page.
        if (RobotsUrl::isRobotsTxt($url)) {
            return [$document, null, null, null];
        }

        if ($robotsStatus !== null && ($robotsStatus >= 500 || $robotsStatus === 429)) {
            return [$document->withRecords([new Issue(
                0,
                "Page not fetched: robots.txt answered HTTP {$robotsStatus}, so every path counts as disallowed "
                    . '(RFC 9309, section 2.3.1.4) and meta tags and page headers were not read',
                Severity::Low,
                'robots_unreachable',
            )]), null, null, null];
        }

        $decision = $document->decide($signature->productToken, RobotsUrl::targetPath($url));

        if (! $decision->allowed) {
            // A disallowed decision names the rule behind it; line 0 stands in
            // for the theoretical case of a denial with no rule attached.
            $rule = $decision->rule;

            // The rule that blocked us usually lives in the wildcard group, in
            // which case it governs every crawler — saying it applies to our
            // own token would misreport whose access is restricted.
            $audience = $rule !== null && ! in_array('*', $rule->userAgents(), true)
                ? 'for ' . implode(', ', $rule->userAgents())
                : 'for all crawlers';

            return [$document->withRecords([new Issue(
                $rule !== null ? $rule->line : 0,
                "Page not fetched: robots.txt disallows {$decision->path} {$audience}, "
                    . 'so meta tags and page headers were not read',
                Severity::Low,
                'page_disallowed',
            )]), null, $decision, null];
        }

        $prober = $this->pageProber ?? new PageInspector($this->fetcher(), $signature->value, $this->config);
        $probe = $prober->probe($url);

        return [$document->withRecords($probe->records()), $probe->statusCode, $decision, $probe];
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
