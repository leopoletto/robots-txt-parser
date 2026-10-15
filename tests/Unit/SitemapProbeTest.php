<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Report;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbe;
use Leopoletto\RobotsTxtParser\Audit\SitemapProbeResult;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\SitemapProber;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SitemapProbeTest extends TestCase
{
    private const ROBOTS = <<<'TXT'
        User-agent: *
        Disallow: /private

        Sitemap: https://example.com/gone.xml
        Sitemap: https://example.com/page.xml
        Sitemap: https://example.com/sitemap.xml
        TXT;

    /**
     * A prober answering from results fixed in advance, and counting its calls.
     *
     * @param list<SitemapProbeResult> $results
     */
    private function prober(array $results): SitemapProber
    {
        return new class ($results) implements SitemapProber {
            public int $calls = 0;

            /** @param list<SitemapProbeResult> $results */
            public function __construct(private readonly array $results)
            {
            }

            public function probe(string $url): SitemapProbeResult
            {
                $this->calls++;

                foreach ($this->results as $result) {
                    if ($result->url === $url) {
                        return $result;
                    }
                }

                return new SitemapProbeResult($url, null, false, false, error: 'not recorded');
            }
        };
    }

    /**
     * @return list<SitemapProbeResult>
     */
    private function results(): array
    {
        return [
            new SitemapProbeResult('https://example.com/gone.xml', 404, false, false),
            new SitemapProbeResult('https://example.com/page.xml', 200, true, false, 'text/html'),
            new SitemapProbeResult('https://example.com/sitemap.xml', 200, true, true, 'application/xml'),
        ];
    }

    private function audit(?SitemapProber $prober): Report
    {
        return (new Auditor(probe: $prober))->audit((new RobotsTxtParser())->parseText(self::ROBOTS));
    }

    #[Test]
    public function the_audit_asks_any_prober_about_each_declared_sitemap(): void
    {
        $prober = $this->prober($this->results());

        $report = $this->audit($prober);

        $this->assertSame(3, $prober->calls);
        $this->assertSame(Status::Critical, $report->find('sitemap-unreachable')?->status);
        $this->assertSame('https://example.com/gone.xml', $report->find('sitemap-unreachable')?->evidence[0]->text);
        $this->assertSame(Status::Warning, $report->find('sitemap-not-xml')?->status);
        $this->assertSame('https://example.com/page.xml', $report->find('sitemap-not-xml')?->evidence[0]->text);
    }

    #[Test]
    public function without_a_prober_the_audit_says_nothing_about_reachability(): void
    {
        $report = $this->audit(null);

        $this->assertNull($report->find('sitemap-unreachable'));
        $this->assertNull($report->find('sitemap-not-xml'));
    }

    #[Test]
    public function results_written_out_and_read_back_lead_the_audit_to_the_same_report(): void
    {
        $recorded = array_map(static fn (SitemapProbeResult $result): array => $result->toArray(), $this->results());

        /** @var list<array<string, mixed>> $stored */
        $stored = json_decode((string) json_encode($recorded), true);
        $replayed = array_map(SitemapProbeResult::fromArray(...), $stored);

        $this->assertEquals($this->results(), $replayed);
        $this->assertSame(
            $this->audit($this->prober($this->results()))->toArray(),
            $this->audit($this->prober($replayed))->toArray(),
        );
    }

    #[Test]
    public function a_failed_request_keeps_its_error_when_written_out_and_read_back(): void
    {
        $failed = new SitemapProbeResult('https://example.com/sitemap.xml', null, false, false, error: 'timed out');

        $this->assertEquals($failed, SitemapProbeResult::fromArray($failed->toArray()));
        $this->assertSame('Request failed: timed out', SitemapProbeResult::fromArray($failed->toArray())->describe());
    }

    /**
     * @return iterable<string, array{HttpResponse|ConnectException, bool, bool, int|null}>
     */
    public static function responses(): iterable
    {
        $urlset = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';

        yield 'a sitemap' => [new HttpResponse(200, ['Content-Type' => 'application/xml'], $urlset), true, true, 200];
        yield 'a sitemap index' => [new HttpResponse(200, [], '<sitemapindex></sitemapindex>'), true, true, 200];
        yield 'a gzipped sitemap' => [new HttpResponse(200, [], "\x1f\x8b\x08rest"), true, true, 200];
        yield 'a web page' => [new HttpResponse(200, ['Content-Type' => 'text/html'], '<html></html>'), true, false, 200];
        yield 'a missing file' => [new HttpResponse(404, [], 'Not found'), false, false, 404];
        yield 'no answer' => [new ConnectException('timed out', new Request('GET', '/')), false, false, null];
    }

    #[Test]
    #[DataProvider('responses')]
    public function the_http_probe_reports_what_the_sitemap_address_answered(
        HttpResponse|ConnectException $response,
        bool $reachable,
        bool $looksLikeXml,
        ?int $statusCode,
    ): void {
        $probe = new SitemapProbe(
            new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]),
            'TestBot/1.0',
        );

        $result = $probe->probe('https://example.com/sitemap.xml');

        $this->assertInstanceOf(SitemapProber::class, $probe);
        $this->assertSame('https://example.com/sitemap.xml', $result->url);
        $this->assertSame($reachable, $result->reachable);
        $this->assertSame($looksLikeXml, $result->looksLikeXml);
        $this->assertSame($statusCode, $result->statusCode);
    }

    #[Test]
    public function a_trickling_sitemap_stops_at_the_timeout_with_an_error(): void
    {
        $now = 0.0;
        $body = \GuzzleHttp\Psr7\FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor(''), [
            'eof' => static fn (): bool => false,
            'read' => static function () use (&$now): string {
                $now += 1.0;

                return ' ';
            },
        ]);

        $probe = new SitemapProbe(
            new Client(['handler' => HandlerStack::create(new MockHandler([new HttpResponse(200, [], $body)]))]),
            'TestBot/1.0',
            timeout: 3,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $result = $probe->probe('https://example.com/sitemap.xml');

        $this->assertLessThanOrEqual(4.0, $now);
        $this->assertStringContainsString('Timed out', (string) $result->error);
        $this->assertSame(200, $result->statusCode);
    }
}
