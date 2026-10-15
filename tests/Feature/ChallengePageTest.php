<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\PageProbeResult;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bot challenge answered in place of a page is the protection layer's page,
 * not the site's: its noindex must not be read as the site's own.
 */
final class ChallengePageTest extends TestCase
{
    private const URL = 'https://example.com/pricing';

    private function parse(PsrResponse $page): Response
    {
        $fetcher = new RobotsFetcher(
            new Client(['handler' => HandlerStack::create(new MockHandler([
                new PsrResponse(200, [], "User-agent: *\nAllow: /\n"),
                $page,
            ])), 'http_errors' => false]),
            new HttpConfiguration(),
        );

        return (new RobotsTxtParser(new HttpConfiguration(), null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->parseUrl(self::URL);
    }

    private static function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/cloudflare-challenge.html');
    }

    #[Test]
    public function a_cloudflare_challenge_served_as_200_is_not_the_page(): void
    {
        $response = $this->parse(new PsrResponse(200, ['Server' => 'cloudflare'], self::fixture()));

        $probe = $response->pageProbe();
        $this->assertNotNull($probe);
        $this->assertTrue($probe->challenge);
        $this->assertFalse($probe->observed());
        $this->assertSame([], $probe->metaTags);
        $this->assertSame([], $response->records()->metaDirectives());

        $finding = (new Auditor())->audit($response)->find('indexing-not-observed');
        $this->assertSame(Status::NotChecked, $finding?->status);
        $this->assertStringContainsString('bot challenge', (string) $finding?->summary);
    }

    #[Test]
    public function a_challenge_named_by_its_header_is_recognised_whatever_the_status(): void
    {
        $probe = $this->parse(new PsrResponse(403, ['cf-mitigated' => 'challenge'], 'Forbidden'))->pageProbe();

        $this->assertTrue($probe?->challenge);
    }

    #[Test]
    public function a_normal_page_served_through_cloudflare_is_read(): void
    {
        $response = $this->parse(new PsrResponse(
            200,
            ['Server' => 'cloudflare', 'CF-RAY' => '0-AMS'],
            '<html><head><title>Pricing</title><meta name="robots" content="noindex"></head><body></body></html>',
        ));

        $this->assertFalse($response->pageProbe()?->challenge);
        $this->assertTrue($response->pageProbe()?->observed());
        $this->assertCount(1, $response->records()->metaDirectives());
    }

    #[Test]
    public function a_page_titled_just_a_moment_is_not_a_challenge_on_its_own(): void
    {
        $probe = $this->parse(new PsrResponse(200, [], '<html><head><title>Just a moment...</title></head></html>'))->pageProbe();

        $this->assertFalse($probe?->challenge);
    }

    #[Test]
    public function the_challenge_flag_survives_being_written_out_and_read_back(): void
    {
        $probe = new PageProbeResult(self::URL, 200, challenge: true);

        $this->assertEquals($probe, PageProbeResult::fromArray($probe->toArray()));
        $this->assertFalse(PageProbeResult::fromArray($probe->toArray())->observed());
    }
}
