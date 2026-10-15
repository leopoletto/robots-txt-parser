<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Contract\PageProber;
use Leopoletto\RobotsTxtParser\Exception\MissingUserAgent;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\PageInspector;
use Leopoletto\RobotsTxtParser\Http\PageProbeResult;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageProberTest extends TestCase
{
    private const URL = 'https://example.com/pricing';

    private const ROBOTS = "User-agent: *\nDisallow: /private\n";

    private const PAGE = '<html><head><meta name="robots" content="noindex, nofollow">'
        . '<meta name="googlebot" content="index, noindex"></head><body></body></html>';

    /**
     * A prober answering with a fixed result, and counting its calls.
     */
    private function prober(PageProbeResult $result): PageProber
    {
        return new class ($result) implements PageProber {
            public int $calls = 0;

            public function __construct(private readonly PageProbeResult $result)
            {
            }

            public function probe(string $url): PageProbeResult
            {
                $this->calls++;

                return $this->result;
            }
        };
    }

    private function parser(?RobotsFetcher $fetcher = null): RobotsTxtParser
    {
        return (new RobotsTxtParser(new HttpConfiguration(), null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com');
    }

    /**
     * @param list<PsrResponse> $queue
     */
    private function fetcher(array $queue): RobotsFetcher
    {
        return new RobotsFetcher(
            new Client(['handler' => HandlerStack::create(new MockHandler($queue)), 'http_errors' => false]),
            new HttpConfiguration(),
        );
    }

    /**
     * The URL fetched over the network, robots.txt and page both.
     */
    private function fetched(string $robots = self::ROBOTS, string $url = self::URL): Response
    {
        return $this->parser($this->fetcher([
            new PsrResponse(200, [], $robots),
            new PsrResponse(200, ['X-Robots-Tag' => ['noarchive', 'googlebot: nosnippet']], self::PAGE),
        ]))->parseUrl($url);
    }

    #[Test]
    public function a_fetched_url_carries_what_the_page_sent_as_it_was_sent(): void
    {
        $probe = $this->fetched()->pageProbe();

        $this->assertSame([
            'url' => self::URL,
            'statusCode' => 200,
            'headers' => ['noarchive', 'googlebot: nosnippet'],
            'metaTags' => [
                ['name' => 'robots', 'content' => 'noindex, nofollow'],
                ['name' => 'googlebot', 'content' => 'index, noindex'],
            ],
            'error' => null,
            'truncated' => false,
            'challenge' => false,
        ], $probe?->toArray());
    }

    #[Test]
    public function a_result_written_out_and_read_back_is_the_same_result(): void
    {
        $probe = $this->fetched()->pageProbe();
        $this->assertNotNull($probe);

        /** @var array<string, mixed> $stored */
        $stored = json_decode((string) json_encode($probe->toArray()), true);

        $this->assertEquals($probe, PageProbeResult::fromArray($stored));
    }

    #[Test]
    public function a_stored_file_parsed_with_a_replayed_result_is_audited_as_it_was_when_fetched(): void
    {
        $fetched = $this->fetched();
        $stored = (array) json_decode((string) json_encode($fetched->pageProbe()?->toArray()), true);
        $prober = $this->prober(PageProbeResult::fromArray($stored));

        $replayed = $this->parser()->withPageProber($prober)->parseTextForUrl(self::ROBOTS, self::URL);

        $this->assertSame(1, $prober->calls);
        $this->assertSame(self::URL, $replayed->requestedUrl());
        $this->assertSame(200, $replayed->pageStatusCode());
        $this->assertTrue($replayed->pageDecision()?->allowed);
        $this->assertCount(2, $replayed->records()->metaDirectives());
        $this->assertCount(2, $replayed->records()->headerDirectives());
        $this->assertSame('page', $replayed->records()->headerDirectives()[0]->origin);
        $this->assertSame(
            (new Auditor())->audit($fetched)->toArray(),
            (new Auditor())->audit($replayed)->toArray(),
        );
        $this->assertSame('warning', (new Auditor())->audit($replayed)->find('indexing-noindex')?->status->value);
    }

    #[Test]
    public function the_blocked_page_decision_is_worked_out_again_and_the_prober_is_not_asked(): void
    {
        $robots = "User-agent: *\nDisallow: /pricing\n";
        $fetched = $this->parser($this->fetcher([new PsrResponse(200, [], $robots)]))->parseUrl(self::URL);
        $prober = $this->prober(new PageProbeResult(self::URL, 200, ['noindex']));

        $replayed = $this->parser()->withPageProber($prober)->parseTextForUrl($robots, self::URL);

        $this->assertNull($fetched->pageProbe());
        $this->assertSame(0, $prober->calls);
        $this->assertNull($replayed->pageProbe());
        $this->assertFalse($replayed->pageDecision()?->allowed);
        $this->assertFalse($replayed->pageInspected());
        $this->assertSame([], $replayed->records()->headerDirectives());
        $this->assertSame(
            (new Auditor())->audit($fetched)->toArray(),
            (new Auditor())->audit($replayed)->toArray(),
        );
        $this->assertNotNull((new Auditor())->audit($replayed)->find('indexing-not-inspected'));
    }

    #[Test]
    public function a_file_that_now_allows_the_page_asks_the_prober_where_it_did_not_before(): void
    {
        // The decision follows the content given, not what was true at fetch.
        $prober = $this->prober(new PageProbeResult(self::URL, 200));

        $replayed = $this->parser()->withPageProber($prober)->parseTextForUrl("User-agent: *\nAllow: /\n", self::URL);

        $this->assertSame(1, $prober->calls);
        $this->assertTrue($replayed->pageDecision()?->allowed);
    }

    #[Test]
    public function headers_sent_with_the_robots_txt_itself_are_replayed_under_their_own_origin(): void
    {
        $fetched = $this->parser($this->fetcher([
            new PsrResponse(200, ['X-Robots-Tag' => 'noindex'], self::ROBOTS),
            new PsrResponse(200, ['X-Robots-Tag' => 'noarchive'], '<html><head></head></html>'),
        ]))->parseUrl(self::URL);

        $robotsHeaders = [];
        foreach ($fetched->records()->headerDirectives() as $header) {
            if ($header->origin === 'robots.txt') {
                $robotsHeaders[] = $header->raw;
            }
        }

        $probe = $fetched->pageProbe();
        $this->assertNotNull($probe);

        $replayed = $this->parser()
            ->withPageProber($this->prober($probe))
            ->parseTextForUrl(self::ROBOTS, self::URL, $fetched->size(), $robotsHeaders);

        $this->assertSame(['noindex'], $robotsHeaders);
        $this->assertSame(['noarchive'], $probe->headers);
        $this->assertSame(
            ['robots.txt', 'page'],
            array_map(static fn ($header): string => $header->origin, $replayed->records()->headerDirectives()),
        );
        $this->assertSame(
            (new Auditor())->audit($fetched)->toArray(),
            (new Auditor())->audit($replayed)->toArray(),
        );
    }

    #[Test]
    public function a_stored_file_is_measured_as_the_size_it_had_when_fetched(): void
    {
        // Kept content loses its final newline and any carriage returns.
        $fetched = $this->parser($this->fetcher([
            new PsrResponse(200, [], "User-agent: *\r\nDisallow: /private\r\n"),
            new PsrResponse(200, [], self::PAGE),
        ]))->keepContent()->parseUrl(self::URL);

        $prober = $this->prober(new PageProbeResult(self::URL, 200));
        $stored = (string) $fetched->content();

        $measured = $this->parser()->withPageProber($prober)->parseTextForUrl($stored, self::URL, $fetched->size());
        $unmeasured = $this->parser()->withPageProber($prober)->parseTextForUrl($stored, self::URL);

        $this->assertSame($fetched->size(), $measured->size());
        $this->assertLessThan($fetched->size(), $unmeasured->size());
    }

    #[Test]
    public function a_url_that_is_the_robots_txt_has_no_page_to_ask_about(): void
    {
        $prober = $this->prober(new PageProbeResult('https://example.com/robots.txt', 200));

        $response = $this->parser()->withPageProber($prober)->parseTextForUrl(self::ROBOTS, 'https://example.com/robots.txt');

        $this->assertSame(0, $prober->calls);
        $this->assertNull($response->pageDecision());
    }

    #[Test]
    public function a_page_that_could_not_be_fetched_is_recorded_with_its_error_and_no_directives(): void
    {
        $failed = new PageProbeResult(self::URL, null, error: 'timed out');

        $replayed = $this->parser()->withPageProber($this->prober($failed))->parseTextForUrl(self::ROBOTS, self::URL);

        $this->assertEquals($failed, PageProbeResult::fromArray($failed->toArray()));
        $this->assertSame([], $failed->records());
        $this->assertFalse($replayed->pageInspected());
        $this->assertSame([], $replayed->records()->metaDirectives());
    }

    #[Test]
    public function parsing_a_stored_file_for_a_url_needs_a_bot_signature_to_decide_for(): void
    {
        $this->expectException(MissingUserAgent::class);

        (new RobotsTxtParser())->parseTextForUrl(self::ROBOTS, self::URL);
    }

    #[Test]
    public function the_http_inspector_is_a_page_prober(): void
    {
        $inspector = new PageInspector(
            $this->fetcher([new PsrResponse(200, ['X-Robots-Tag' => 'noindex'], self::PAGE)]),
            'TestBot/1.0',
        );

        $result = $inspector->probe(self::URL);

        $this->assertInstanceOf(PageProber::class, $inspector);
        $this->assertSame(['noindex'], $result->headers);
        $this->assertCount(2, $result->metaTags);
    }
}
