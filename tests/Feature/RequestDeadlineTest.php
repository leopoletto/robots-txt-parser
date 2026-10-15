<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A server that trickles bytes must not hold a request open past its timeout,
 * and a body that stops partway must not be taken for the whole file.
 */
final class RequestDeadlineTest extends TestCase
{
    private float $now = 0.0;

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function clock(): Closure
    {
        return fn (): float => $this->now;
    }

    /**
     * A body that hands over one byte per read, a second apart.
     */
    private function trickle(string $content): StreamInterface
    {
        $position = 0;

        return FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor($content), [
            'eof' => static function () use (&$position, $content): bool {
                return $position >= strlen($content);
            },
            'read' => function () use (&$position, $content): string {
                $this->now += 1.0;

                return $content[$position++] ?? '';
            },
        ]);
    }

    /**
     * @param list<mixed> $queue
     */
    private function parse(string $url, array $queue, HttpConfiguration $config): Response
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $fetcher = new RobotsFetcher(
            new Client(['handler' => $stack, 'http_errors' => false]),
            $config,
            $this->clock(),
        );

        return (new RobotsTxtParser($config, null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->parseUrl($url);
    }

    #[Test]
    public function a_trickling_robots_txt_stops_at_the_timeout_with_an_error(): void
    {
        $response = $this->parse(
            'https://example.com/robots.txt',
            [new PsrResponse(200, [], $this->trickle(str_repeat("Disallow: /x\n", 10)))],
            new HttpConfiguration(robotsTimeout: 3),
        );

        $this->assertNotNull($response->error());
        $this->assertStringContainsString('timed out', strtolower((string) $response->error()));
        $this->assertSame([], $response->records()->directives());
        $this->assertLessThanOrEqual(4.0, $this->now);
    }

    #[Test]
    public function a_robots_txt_that_stops_mid_body_is_an_error_not_a_shorter_file(): void
    {
        $body = FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor(''), [
            'eof' => static fn (): bool => false,
            'read' => static function (): string {
                static $calls = 0;

                if ($calls++ === 0) {
                    return "User-agent: *\nDisallow: /a\n";
                }

                throw new RuntimeException('Unable to read from stream');
            },
        ]);

        $response = $this->parse('https://example.com/robots.txt', [new PsrResponse(200, [], $body)], new HttpConfiguration());

        $this->assertNotNull($response->error());
        $this->assertSame([], $response->records()->directives());
    }

    #[Test]
    public function a_trickling_page_is_not_observed_and_the_robots_txt_still_stands(): void
    {
        $response = $this->parse('https://example.com/pricing', [
            new PsrResponse(200, [], "User-agent: *\nDisallow: /private\n"),
            new PsrResponse(200, [], $this->trickle('<html><head><meta name="robots" content="noindex"></head></html>')),
        ], new HttpConfiguration(pageTimeout: 3));

        $this->assertNull($response->error());
        $this->assertFalse($response->isAllowed('TestBot', '/private'));
        $this->assertFalse($response->pageProbe()?->observed());
        $this->assertStringContainsString('timed out', strtolower((string) $response->pageProbe()?->error));
    }

    #[Test]
    public function the_connect_timeout_is_sent_with_every_request(): void
    {
        $this->parse('https://example.com/pricing', [
            new PsrResponse(200, [], "User-agent: *\nAllow: /\n"),
            new PsrResponse(200, [], '<html><head></head></html>'),
        ], new HttpConfiguration(connectTimeout: 4));

        $this->assertCount(2, $this->history);

        foreach ($this->history as $entry) {
            $this->assertSame(4, $entry['options']['connect_timeout'] ?? null);
        }
    }
}
