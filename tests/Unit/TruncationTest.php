<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Audit\Auditor;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file cut at the size limit must lose what was past it, never gain a rule
 * the site did not write.
 */
final class TruncationTest extends TestCase
{
    private const FILE = "User-agent: *\nDisallow: /a\nDisallow: /private-area\nDisallow: /b\n";

    private function parser(int $maxBytes): RobotsTxtParser
    {
        return new RobotsTxtParser(new HttpConfiguration(maxBytes: $maxBytes));
    }

    #[Test]
    public function a_file_of_exactly_the_limit_is_not_truncated(): void
    {
        $response = $this->parser(strlen(self::FILE))->parseText(self::FILE);

        $this->assertFalse($response->truncated());
        $this->assertSame(strlen(self::FILE), $response->size());
    }

    #[Test]
    public function a_line_cut_by_the_limit_is_not_parsed_into_a_broader_rule(): void
    {
        $cut = strlen("User-agent: *\nDisallow: /a\nDisallow: /priv");

        $response = $this->parser($cut)->parseText(self::FILE);

        $this->assertTrue($response->truncated());

        $values = array_map(static fn (Directive $d): string => $d->value, $response->records()->directives());
        $this->assertSame(['/a'], $values);
        $this->assertTrue($response->isAllowed('AnyBot', '/private-area'));
        $this->assertTrue($response->isAllowed('AnyBot', '/priv'));

        $truncated = array_values(array_filter(
            $response->records()->issues(),
            static fn (Issue $issue): bool => $issue->type === 'truncated',
        ));
        $this->assertCount(1, $truncated);
        $this->assertStringContainsString('incomplete last line was not parsed', $truncated[0]->message);
    }

    #[Test]
    public function the_size_check_judges_against_the_configured_limit(): void
    {
        $file = "User-agent: *\n" . str_repeat("Disallow: /path\n", 200);

        $report = (new Auditor(maxBytes: 1024))->audit($this->parser(1024)->parseText($file));

        $finding = $report->find('size-over-limit');
        $this->assertSame(Status::Critical, $finding?->status);
        $this->assertStringContainsString('exceeds the 1 KB crawl limit', (string) $finding?->title);

        $default = (new Auditor())->audit((new RobotsTxtParser())->parseText(str_repeat("Disallow: /path\n", 33000)));
        $this->assertStringContainsString('exceeds the 500 KB crawl limit', (string) $default->find('size-over-limit')?->title);

        $small = (new Auditor(maxBytes: 4096))->audit($this->parser(4096)->parseText("User-agent: *\n" . str_repeat("Disallow: /path\n", 150)));
        $this->assertTrue($small->has('size-approaching-limit'));
    }
}
