<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Http\RobotsFetcher;
use Leopoletto\RobotsTxtParser\Response;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The three entry points must produce identical results for identical content.
 *
 * Version 2 lowercased and deduplicated lines on the text path only, so the
 * same document reported different rules and different line numbers depending
 * on whether it was pasted, uploaded or fetched.
 */
final class SourceParityTest extends TestCase
{
    private const DOCUMENT = <<<'TXT'
        # Crawler policy
        User-agent: GPTBot
        User-agent: ChatGPT-User
        Disallow: /admin
        Allow: /admin/public

        User-agent: *
        Disallow: /admin
        Crawl-delay: 5

        Sitemap: https://example.com/sitemap.xml
        TXT;

    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null && is_file($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function text_file_and_url_parses_agree(): void
    {
        $fromText = (new RobotsTxtParser())->parseText(self::DOCUMENT);
        $fromFile = (new RobotsTxtParser())->parseFile($this->writeFixture(self::DOCUMENT));
        $fromUrl = $this->parseUrl(self::DOCUMENT);

        $this->assertSame(
            $this->fingerprint($fromText),
            $this->fingerprint($fromFile),
            'text and file parses diverged',
        );

        $this->assertSame(
            $this->fingerprint($fromText),
            $this->fingerprint($fromUrl),
            'text and URL parses diverged',
        );
    }

    #[Test]
    public function all_three_report_the_same_content_when_asked_to_keep_it(): void
    {
        $text = (new RobotsTxtParser())->keepContent()->parseText(self::DOCUMENT);
        $file = (new RobotsTxtParser())->keepContent()->parseFile($this->writeFixture(self::DOCUMENT));

        $this->assertSame(self::DOCUMENT, $text->content());
        $this->assertSame(self::DOCUMENT, $file->content());
    }

    #[Test]
    public function repeated_identical_lines_survive_every_route(): void
    {
        $document = "User-agent: A\nDisallow: /admin\nUser-agent: B\nDisallow: /admin";

        $text = (new RobotsTxtParser())->parseText($document);
        $file = (new RobotsTxtParser())->parseFile($this->writeFixture($document));

        $this->assertCount(2, $text->records()->disallowed());
        $this->assertCount(2, $file->records()->disallowed());
    }

    /**
     * A stable summary of everything a parse recovered, for comparison across routes.
     */
    private function fingerprint(Response $response): string
    {
        $document = $response->records();

        return json_encode([
            'userAgents' => array_map(
                static fn ($ua): array => ['line' => $ua->line, 'token' => $ua->token],
                $document->userAgents(),
            ),
            'directives' => array_map(
                static fn ($d): array => ['line' => $d->line, 'type' => $d->type->value, 'value' => $d->value],
                $document->directives(),
            ),
            'sitemaps' => array_map(static fn ($s): array => $s->toArray(), $document->sitemaps()),
            'comments' => array_map(static fn ($c): array => $c->toArray(), $document->comments()),
            'issues' => array_map(static fn ($i): array => $i->toArray(), $document->issues()),
        ], JSON_THROW_ON_ERROR);
    }

    private function writeFixture(string $content): string
    {
        $this->path = tempnam(sys_get_temp_dir(), 'robots');
        file_put_contents((string) $this->path, $content);

        return (string) $this->path;
    }

    private function parseUrl(string $body): Response
    {
        $mock = new MockHandler([
            new PsrResponse(200, [], $body),
            // The page request, which follows the robots.txt fetch.
            new PsrResponse(200, [], '<html><head></head><body></body></html>'),
        ]);

        $fetcher = new RobotsFetcher(
            new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]),
            new HttpConfiguration(),
        );

        return (new RobotsTxtParser(new HttpConfiguration(), null, $fetcher))
            ->withBotSignature('TestBot', '1.0', 'https://example.com')
            ->parseUrl('https://example.com/');
    }
}
