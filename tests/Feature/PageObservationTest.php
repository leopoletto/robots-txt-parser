<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Report;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\PageProbeResult;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A page that was requested but never actually read must not be audited as
 * if it had been: its absence of directives proves nothing.
 */
final class PageObservationTest extends TestCase
{
    private const URL = 'https://example.com/pricing';

    private const ROBOTS = "User-agent: *\nAllow: /\n";

    /**
     * @param list<mixed> $queue
     */
    private function parse(array $queue, ?HttpConfiguration $config = null): Response
    {
        $config ??= new HttpConfiguration();

        $fetcher = new RobotsFetcher(
            new Client(['handler' => HandlerStack::create(new MockHandler($queue)), 'http_errors' => false]),
            $config,
        );

        return (new RobotsTxtParser($config, null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->parseUrl(self::URL);
    }

    private function audit(Response $response): Report
    {
        return (new Auditor())->audit($response);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unobservedPages(): array
    {
        return [
            '403' => [new PsrResponse(403, [], '<html><head><meta name="robots" content="noindex"></head></html>')],
            '404' => [new PsrResponse(404, [], 'Not found')],
            '500' => [new PsrResponse(500, [], 'Server error')],
            '403 with X-Robots-Tag' => [new PsrResponse(403, ['X-Robots-Tag' => 'noindex'], 'Forbidden')],
            'timeout' => [new ConnectException('Operation timed out', new Request('GET', self::URL))],
        ];
    }

    #[Test]
    #[DataProvider('unobservedPages')]
    public function a_page_that_was_not_read_is_not_checked(mixed $page): void
    {
        $response = $this->parse([new PsrResponse(200, [], self::ROBOTS), $page]);
        $report = $this->audit($response);

        $finding = $report->find('indexing-not-observed');
        $this->assertNotNull($finding);
        $this->assertSame(Status::NotChecked, $finding->status);
        $this->assertFalse($report->has('indexing-none'));
        $this->assertFalse($report->has('indexing-ok'));
        $this->assertFalse($report->has('indexing-noindex'));
        $this->assertSame([], $response->records()->headerDirectives());
        $this->assertSame([], $response->records()->metaDirectives());
        $this->assertFalse($response->pageProbe()?->observed());
    }

    #[Test]
    public function the_finding_names_the_status_the_page_answered_with(): void
    {
        $report = $this->audit($this->parse([
            new PsrResponse(200, [], self::ROBOTS),
            new PsrResponse(403, [], 'Forbidden'),
        ]));

        $this->assertStringContainsString('HTTP 403', (string) $report->find('indexing-not-observed')?->summary);
    }

    #[Test]
    public function a_page_whose_body_fails_partway_is_not_checked(): void
    {
        $body = FnStream::decorate(Utils::streamFor('<html><head><meta name="robots" content="noindex">'), [
            'eof' => static fn (): bool => false,
            'read' => static fn (): string => throw new RuntimeException('Connection reset by peer'),
        ]);

        $response = $this->parse([
            new PsrResponse(200, [], self::ROBOTS),
            new PsrResponse(200, [], $body),
        ]);

        $probe = $response->pageProbe();
        $this->assertNotNull($probe);
        $this->assertFalse($probe->observed());
        $this->assertStringContainsString('Connection reset by peer', (string) $probe->error);
        $this->assertTrue($this->audit($response)->has('indexing-not-observed'));
    }

    #[Test]
    public function a_page_that_answered_normally_is_audited_as_before(): void
    {
        $response = $this->parse([
            new PsrResponse(200, [], self::ROBOTS),
            new PsrResponse(200, [], '<html><head><meta name="robots" content="noindex"></head></html>'),
        ]);

        $this->assertTrue($response->pageProbe()?->observed());
        $this->assertTrue($this->audit($response)->has('indexing-noindex'));
    }

    #[Test]
    public function a_head_cut_off_by_the_size_limit_is_recorded_as_truncated(): void
    {
        $response = $this->parse([
            new PsrResponse(200, [], self::ROBOTS),
            new PsrResponse(200, [], '<html><head>' . str_repeat('<link rel="x">', 2000) . '</head></html>'),
        ], new HttpConfiguration(maxHtmlBytes: 1024));

        $this->assertTrue($response->pageProbe()?->truncated);

        $whole = $this->parse([
            new PsrResponse(200, [], self::ROBOTS),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ]);

        $this->assertFalse($whole->pageProbe()?->truncated);
    }

    #[Test]
    public function the_new_fields_survive_being_written_out_and_read_back(): void
    {
        $probe = new PageProbeResult(self::URL, 200, metaTags: [['name' => 'robots', 'content' => 'noindex']], truncated: true);

        $this->assertEquals($probe, PageProbeResult::fromArray($probe->toArray()));
    }

    #[Test]
    public function a_stored_result_without_the_new_fields_is_observed_only_when_it_was_a_clean_2xx(): void
    {
        $this->assertTrue(PageProbeResult::fromArray(['url' => self::URL, 'statusCode' => 200])->observed());
        $this->assertFalse(PageProbeResult::fromArray(['url' => self::URL, 'statusCode' => 403])->observed());
        $this->assertFalse(PageProbeResult::fromArray(['url' => self::URL, 'statusCode' => null, 'error' => 'timed out'])->observed());
        $this->assertFalse(PageProbeResult::fromArray(['url' => self::URL, 'statusCode' => 200])->truncated);
    }
}
