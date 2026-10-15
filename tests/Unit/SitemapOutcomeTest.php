<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Report;
use Leopoletto\RobotsTxtParser\Audit\SitemapOutcome;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbe;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbeResult;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\PageProber;
use Leopoletto\RobotsTxtParser\Contract\SitemapProber;
use Leopoletto\RobotsTxtParser\Http\PageProbeResult;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A sitemap that was not actually seen is reported as not checked, never as
 * unreachable and never as fine.
 */
final class SitemapOutcomeTest extends TestCase
{
    private const SITEMAP = 'https://example.com/sitemap.xml';

    private function answering(SitemapProbeResult $result): SitemapProber
    {
        return new class ($result) implements SitemapProber {
            public function __construct(private readonly SitemapProbeResult $result)
            {
            }

            public function probe(string $url): SitemapProbeResult
            {
                return $this->result;
            }
        };
    }

    private function audit(SitemapProber $prober, string $robots = 'Sitemap: ' . self::SITEMAP): Report
    {
        return (new Auditor(probe: $prober))->audit((new RobotsTxtParser())->parseText($robots));
    }

    /**
     * A URL parse of https://example.com, replayed without the network, so
     * the response knows the origin its robots.txt came from.
     */
    private function urlParse(string $robots): Response
    {
        $page = new class () implements PageProber {
            public function probe(string $url): PageProbeResult
            {
                return new PageProbeResult($url, 200);
            }
        };

        return (new RobotsTxtParser())
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->withPageProber($page)
            ->parseTextForUrl($robots, 'https://example.com/');
    }

    #[Test]
    public function a_sitemap_that_timed_out_is_not_checked_rather_than_unreachable(): void
    {
        $report = $this->audit($this->answering(new SitemapProbeResult(self::SITEMAP, null, false, false, error: 'Operation timed out')));

        $this->assertFalse($report->has('sitemap-unreachable'));
        $this->assertSame(Status::NotChecked, $report->find('sitemap-not-checked')?->status);
        $this->assertStringContainsString('timed out', (string) $report->find('sitemap-not-checked')?->evidence[0]->detail);
    }

    #[Test]
    public function a_sitemap_blocked_for_our_bot_is_not_checked_rather_than_passed(): void
    {
        $report = $this->audit($this->answering(SitemapProbeResult::blocked(self::SITEMAP, 'robots.txt disallows it for our bot (line 3)')));

        $this->assertFalse($report->has('sitemap-declared'));
        $this->assertSame(Status::NotChecked, $report->find('sitemap-not-checked')?->status);
        $this->assertStringContainsString('line 3', (string) $report->find('sitemap-not-checked')?->evidence[0]->detail);
    }

    #[Test]
    public function a_sitemap_skipped_by_a_budget_is_not_checked(): void
    {
        $report = $this->audit($this->answering(SitemapProbeResult::skipped(self::SITEMAP, 'over the time budget')));

        $this->assertSame(Status::NotChecked, $report->find('sitemap-not-checked')?->status);
        $this->assertFalse($report->has('sitemap-declared'));
    }

    #[Test]
    public function a_sitemap_that_answered_404_is_still_unreachable(): void
    {
        $report = $this->audit($this->answering(new SitemapProbeResult(self::SITEMAP, 404, false, false)));

        $this->assertSame(Status::Critical, $report->find('sitemap-unreachable')?->status);
        $this->assertFalse($report->has('sitemap-not-checked'));
    }

    #[Test]
    public function each_result_carries_its_outcome(): void
    {
        $this->assertSame(SitemapOutcome::Observed, (new SitemapProbeResult(self::SITEMAP, 200, true, true))->outcome);
        $this->assertSame(SitemapOutcome::HttpError, (new SitemapProbeResult(self::SITEMAP, 503, false, false))->outcome);
        $this->assertSame(SitemapOutcome::Timeout, (new SitemapProbeResult(self::SITEMAP, null, false, false, error: 'cURL error 28: Operation timed out'))->outcome);
        $this->assertSame(SitemapOutcome::Error, (new SitemapProbeResult(self::SITEMAP, null, false, false, error: 'Could not resolve host'))->outcome);
        $this->assertSame(SitemapOutcome::Blocked, SitemapProbeResult::blocked(self::SITEMAP, 'disallowed')->outcome);
        $this->assertFalse(SitemapProbeResult::blocked(self::SITEMAP, 'disallowed')->reachable);
    }

    #[Test]
    public function outcomes_survive_being_written_out_and_read_back(): void
    {
        foreach ([
            SitemapProbeResult::blocked(self::SITEMAP, 'disallowed by line 2'),
            SitemapProbeResult::skipped(self::SITEMAP, 'over the budget'),
            new SitemapProbeResult(self::SITEMAP, 200, true, true, 'application/xml'),
        ] as $result) {
            $this->assertEquals($result, SitemapProbeResult::fromArray($result->toArray()));
        }
    }

    #[Test]
    public function a_stored_result_without_an_outcome_is_given_the_one_its_fields_imply(): void
    {
        $stored = ['url' => self::SITEMAP, 'statusCode' => null, 'reachable' => false, 'looksLikeXml' => false, 'error' => 'timed out'];
        $this->assertSame(SitemapOutcome::Timeout, SitemapProbeResult::fromArray($stored)->outcome);

        // The stand-in some consumers used for a sitemap they chose not to fetch.
        $standIn = ['url' => self::SITEMAP, 'statusCode' => null, 'reachable' => true, 'looksLikeXml' => true];
        $this->assertSame(SitemapOutcome::Skipped, SitemapProbeResult::fromArray($standIn)->outcome);
    }

    #[Test]
    public function a_sitemap_on_another_origin_is_not_fetched_without_a_decision_for_it(): void
    {
        $mock = new MockHandler([new HttpResponse(200, [], '<urlset></urlset>')]);
        $probe = new SitemapProbe(new Client(['handler' => HandlerStack::create($mock)]), 'TestBot/1.0');

        $report = (new Auditor(probe: $probe))->audit($this->urlParse("Sitemap: https://cdn.example.net/sitemap.xml\n"));

        $this->assertSame(1, $mock->count());
        $finding = $report->find('sitemap-not-checked');
        $this->assertSame(Status::NotChecked, $finding?->status);
        $this->assertStringContainsString('different origin', (string) $finding?->evidence[0]->detail);
    }

    #[Test]
    public function a_sitemap_on_another_origin_follows_the_decision_given_for_it(): void
    {
        $robots = "Sitemap: https://cdn.example.net/allowed.xml\nSitemap: https://cdn.example.net/forbidden.xml\n";
        $mock = new MockHandler([new HttpResponse(200, [], '<urlset></urlset>')]);

        $probe = new SitemapProbe(
            new Client(['handler' => HandlerStack::create($mock)]),
            'TestBot/1.0',
            decideElsewhere: static fn (string $url): ?bool => str_ends_with($url, 'allowed.xml'),
        );

        $report = (new Auditor(probe: $probe))->audit($this->urlParse($robots));

        $this->assertSame(0, $mock->count());
        $finding = $report->find('sitemap-not-checked');
        $this->assertCount(1, $finding?->evidence ?? []);
        $this->assertSame('https://cdn.example.net/forbidden.xml', $finding?->evidence[0]->text);
    }

    #[Test]
    public function a_sitemap_on_the_same_origin_is_fetched_as_before(): void
    {
        $mock = new MockHandler([new HttpResponse(200, [], '<urlset></urlset>')]);
        $probe = new SitemapProbe(new Client(['handler' => HandlerStack::create($mock)]), 'TestBot/1.0');

        $report = (new Auditor(probe: $probe))->audit($this->urlParse('Sitemap: ' . self::SITEMAP));

        $this->assertSame(0, $mock->count());
        $this->assertTrue($report->has('sitemap-declared'));
    }
}
