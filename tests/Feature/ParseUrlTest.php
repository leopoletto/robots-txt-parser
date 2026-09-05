<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Leopoletto\RobotsTxtParser\Exception\MissingUserAgent;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ParseUrlTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    #[Test]
    public function it_requires_a_bot_signature(): void
    {
        $this->expectException(MissingUserAgent::class);

        (new RobotsTxtParser())->parseUrl('https://example.com/page');
    }

    #[Test]
    public function it_fetches_robots_txt_from_the_origin_not_the_path(): void
    {
        $this->parse('https://example.com/deep/page?x=1', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ]);

        $this->assertSame('https://example.com/robots.txt', (string) $this->requests[0]->getUri());
    }

    #[Test]
    public function it_preserves_a_non_default_port_when_locating_robots_txt(): void
    {
        $this->parse('http://localhost:8000/app', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ]);

        $this->assertSame('http://localhost:8000/robots.txt', (string) $this->requests[0]->getUri());
    }

    #[Test]
    public function it_inspects_the_requested_url_not_the_home_page(): void
    {
        // Meta tags and X-Robots-Tag are per-page, so the second request must
        // target exactly what the caller asked about.
        $this->parse('https://example.com/products/widget?variant=blue', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(200, [], '<html><head><meta name="robots" content="noindex"></head></html>'),
        ]);

        $this->assertCount(2, $this->requests);
        $this->assertSame(
            'https://example.com/products/widget?variant=blue',
            (string) $this->requests[1]->getUri(),
        );
    }

    #[Test]
    public function it_reads_meta_tags_from_the_requested_page(): void
    {
        $response = $this->parse('https://example.com/page', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(200, [], '<html><head><meta name="robots" content="noindex, nofollow"></head></html>'),
        ]);

        $meta = $response->records()->metaDirectives();
        $this->assertCount(1, $meta);
        $this->assertSame('robots', $meta[0]->name);
        $this->assertTrue($meta[0]->validation->has('noindex'));
        $this->assertFalse($response->records()->effectiveRules()->indexable());
    }

    #[Test]
    public function it_does_not_fetch_the_page_when_robots_txt_disallows_it(): void
    {
        $response = $this->parse('https://example.com/private/report', [
            new PsrResponse(200, [], "User-agent: *\nDisallow: /private"),
        ]);

        // Only the robots.txt request was made. We obey the rules we report on.
        $this->assertCount(1, $this->requests);
        $this->assertFalse($response->pageInspected());
        $this->assertFalse($response->pageDecision()?->allowed);
        $this->assertSame([], $response->records()->metaDirectives());
    }

    #[Test]
    public function it_explains_why_the_page_was_not_fetched(): void
    {
        $response = $this->parse('https://example.com/private/report', [
            new PsrResponse(200, [], "User-agent: *\nDisallow: /private"),
        ]);

        $issues = array_values(array_filter(
            $response->records()->issues(),
            static fn ($issue): bool => $issue->type === 'page_disallowed',
        ));

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('/private/report', $issues[0]->message);
    }

    #[Test]
    public function it_checks_its_own_product_token_against_the_rules(): void
    {
        // The wildcard group allows nothing, but our own bot is named and allowed.
        $response = $this->parse('https://example.com/page', [
            new PsrResponse(200, [], "User-agent: *\nDisallow: /\n\nUser-agent: TestBot\nAllow: /"),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ]);

        $this->assertCount(2, $this->requests);
        $this->assertTrue($response->pageDecision()?->allowed);
    }

    #[Test]
    public function it_sends_the_configured_user_agent(): void
    {
        $this->parse('https://example.com/page', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ]);

        $this->assertSame(
            'Mozilla/5.0 (compatible; TestBot/1.0; https://example.com)',
            $this->requests[0]->getHeaderLine('User-Agent'),
        );
    }

    #[Test]
    public function it_collects_x_robots_tag_headers_from_both_responses(): void
    {
        $response = $this->parse('https://example.com/page', [
            new PsrResponse(200, ['X-Robots-Tag' => 'noarchive'], "User-agent: *\nAllow: /"),
            new PsrResponse(200, ['X-Robots-Tag' => 'googlebot: noindex'], '<html><head></head></html>'),
        ]);

        $headers = $response->records()->headerDirectives();
        $this->assertCount(2, $headers);

        $origins = array_map(static fn ($h): string => $h->origin, $headers);
        $this->assertSame(['robots.txt', 'page'], $origins);
        $this->assertSame('googlebot', $headers[1]->userAgent);
    }

    #[Test]
    public function it_does_not_request_a_page_when_the_url_is_already_a_robots_txt(): void
    {
        $response = $this->parse('https://example.com/robots.txt', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
        ]);

        $this->assertCount(1, $this->requests);
        $this->assertNull($response->pageDecision());
    }

    #[Test]
    public function it_reports_a_failed_fetch_as_an_issue_rather_than_throwing(): void
    {
        $response = $this->parse('https://example.com/page', [
            new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/robots.txt'),
            ),
        ]);

        $this->assertNotNull($response->error());
        $this->assertFalse($response->isValid());
        $this->assertSame('fetch_failed', $response->records()->issues()[0]->type);
    }

    #[Test]
    public function it_records_the_robots_txt_status_separately_from_the_page_status(): void
    {
        $response = $this->parse('https://example.com/page', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /"),
            new PsrResponse(404, [], '<html><head></head></html>'),
        ]);

        $this->assertSame(200, $response->statusCode());
        $this->assertSame(404, $response->pageStatusCode());
    }

    #[Test]
    public function it_stops_reading_robots_txt_at_the_size_limit(): void
    {
        $body = "User-agent: *\nDisallow: /a\n" . str_repeat("Disallow: /padding\n", 500);

        $response = $this->parse(
            'https://example.com/robots.txt',
            [new PsrResponse(200, [], $body)],
            new HttpConfiguration(maxBytes: 100),
        );

        $this->assertTrue($response->truncated());
        $this->assertLessThanOrEqual(100, $response->size());
    }

    /**
     * @param list<mixed> $queue
     */
    private function parse(string $url, array $queue, ?HttpConfiguration $config = null): Response
    {
        $this->requests = [];
        $config ??= new HttpConfiguration();

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->requests));

        $fetcher = new RobotsFetcher(
            new Client(['handler' => $stack, 'http_errors' => false]),
            $config,
        );

        $response = (new RobotsTxtParser($config, null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->parseUrl($url);

        // Middleware::history stores entries as ['request' => ..., 'response' => ...].
        $this->requests = array_map(
            static fn (array $entry): RequestInterface => $entry['request'],
            $this->requests,
        );

        return $response;
    }
}
