<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Audit\Category;
use Leopoletto\RobotsTxtParser\Audit\Check\SensitivePathCheck;
use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\RobotsTxtParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitivePathCheckTest extends TestCase
{
    /**
     * @return array<string, Finding> Findings keyed by id.
     */
    private function findings(string $robots, ?SensitivePathCheck $check = null): array
    {
        $findings = ($check ?? new SensitivePathCheck())->run((new RobotsTxtParser())->parseText($robots));

        return array_column($findings, null, 'id');
    }

    /**
     * @return iterable<string, array{string, string|null, Status|null}>
     */
    public static function lines(): iterable
    {
        $none = [
            // Guessable: every scanner wordlist already has them.
            'Disallow: /admin', 'Disallow: /administrator', 'Disallow: /wp-admin/', 'Disallow: /backend',
            'Disallow: /login', 'Disallow: /api', 'Disallow: /api/_snippets/*', 'Disallow: /graphql',
            'Disallow: /cpanel',
            // A sensitive word alone at the top level is just as guessable.
            'Disallow: /staging', 'Disallow: /backup', 'Disallow: /backups/', 'Disallow: /private/',
            'Disallow: /dev/', 'Disallow: /test', 'Disallow: /beta', 'Disallow: /preview', 'Disallow: /logs',
            'Disallow: /STAGING/', 'Disallow: /staging/', 'Disallow: /backups',
            // A weak token needs more than an ordinary qualifier.
            'Disallow: /test-drive/', 'Disallow: /dev-tools/', 'Disallow: /test-site/', 'Disallow: /logs/',
            'Disallow: /beta-2023/', 'Disallow: /preview2/',
            // Templates hold no secret, and a name that merely starts like one is not it.
            'Disallow: /.env.example', 'Disallow: /.env.sample', 'Disallow: /.env.dist', 'Disallow: /.gitignore',
            'Disallow: /.gitconfig', 'Disallow: /.environment',
            // A bare word is as generic with an extension as without one.
            'Disallow: /old.html', 'Disallow: /backup.html', 'Disallow: /db.php', 'Disallow: /test.html',
            // A token inside a longer word is not the token.
            'Disallow: /devices', 'Disallow: /testimonials', 'Disallow: /older-posts', 'Disallow: /stagecoach',
            'Disallow: /dbz', 'Disallow: /developers', 'Disallow: /blog', 'Disallow: /changelog',
            // Nothing to read.
            'Disallow: /', 'Disallow:', 'Allow: /',
            // A wildcard around nothing sensitive.
            'Disallow: /*.php$', 'Disallow: /*/admin/',
        ];

        foreach ($none as $line) {
            yield $line => [$line, null, null];
        }

        $tiers = [
            'sensitive-paths-secrets' => [Status::Warning, [
                'Disallow: /.git/', 'Disallow: /.env', 'Disallow: /app/.svn/entries', 'Disallow: /.htpasswd',
                'Disallow: /.DS_Store', 'Disallow: /dump.sql', 'Disallow: /site-backup.zip',
                'Disallow: /var/app.log', 'Disallow: /db.sql$', 'Disallow: /backup.tar.gz*', 'Allow: /.env',
                'Disallow: /.env.production', 'Disallow: /app/.env.local', 'Disallow: /.git',
                'Disallow: /.git/config', 'Disallow: /data/app.sqlite', 'Disallow: /app.sqlite3',
                'Disallow: /storage/users.db', 'Disallow: /legacy.mdb',
            ]],
            'sensitive-paths-specific' => [Status::Warning, [
                'Disallow: /backup-2023/', 'Disallow: /staging2/', 'Disallow: /app/staging/',
                'Disallow: /old_site/', 'Disallow: /db-export/', 'Disallow: /Backup-2023/',
                'Disallow: /admin/internal/', 'Disallow: /staging2/*', 'Allow: /backup-2023/',
                'Disallow: /dev-2023/', 'Disallow: /dev2/', 'Disallow: /test-2023/', 'Disallow: /logs_v1/',
                'Disallow: /old-dev/', 'Disallow: /app/test/', 'Disallow: /backup-2023.html',
                'Disallow: /app/old.html', 'Disallow: /export.csv', 'Disallow: /reports/users.xlsx',
                'Disallow: /prices.xls',
            ]],
            'sensitive-paths-tools' => [Status::Info, [
                'Disallow: /phpmyadmin/', 'Disallow: /adminer', 'Disallow: /server-status',
                'Disallow: /tools/server-info',
            ]],
            // A pattern is never a disclosure, whatever it would match.
            'sensitive-paths-wildcard' => [Status::Info, [
                'Disallow: /*.sql$', 'Disallow: /*/backup/', 'Disallow: /*/.git/', 'Disallow: /uploads/*.zip',
                'Disallow: /*/phpmyadmin/', 'Disallow: /*.csv$', 'Disallow: /*/.env.production',
            ]],
        ];

        foreach ($tiers as $id => [$status, $lines]) {
            foreach ($lines as $line) {
                yield $line => [$line, $id, $status];
            }
        }
    }

    #[Test]
    #[DataProvider('lines')]
    public function it_puts_each_line_in_one_tier(string $line, ?string $id, ?Status $status): void
    {
        $findings = $this->findings("User-agent: *\n{$line}");

        if ($id === null) {
            $this->assertSame([], $findings);

            return;
        }

        $this->assertSame([$id], array_keys($findings));
        $this->assertSame($status, $findings[$id]->status);
        $this->assertSame($line, $findings[$id]->evidence[0]->text);
        $this->assertSame(2, $findings[$id]->evidence[0]->line);
    }

    #[Test]
    public function it_reports_one_finding_per_tier_with_the_lines_that_fell_into_it(): void
    {
        $findings = $this->findings(<<<'TXT'
            User-agent: *
            Disallow: /admin
            Disallow: /.env
            Disallow: /backup-2023/
            Allow: /app/staging/
            Disallow: /phpmyadmin/
            Disallow: /*.sql$
            Disallow: /dump.sql
            Disallow: /staging
            TXT);

        $lines = array_map(
            static fn (Finding $finding): array => array_map(static fn (Evidence $e): ?int => $e->line, $finding->evidence),
            $findings,
        );

        $this->assertSame([
            'sensitive-paths-secrets' => [3, 8],
            'sensitive-paths-specific' => [4, 5],
            'sensitive-paths-tools' => [6],
            'sensitive-paths-wildcard' => [7],
        ], $lines);
        $this->assertSame('Allow: /app/staging/', $findings['sensitive-paths-specific']->evidence[1]->text);
    }

    #[Test]
    public function it_quotes_no_more_than_eight_lines_but_counts_them_all(): void
    {
        $rules = implode("\n", array_map(static fn (int $year): string => "Disallow: /backup-{$year}/", range(2010, 2019)));

        $finding = $this->findings("User-agent: *\n{$rules}")['sensitive-paths-specific'];

        $this->assertCount(8, $finding->evidence);
        $this->assertStringStartsWith('10 rules', $finding->title);
    }

    #[Test]
    public function it_mentions_noindex_only_for_specific_names_and_never_beside_a_disallow(): void
    {
        $findings = $this->findings(<<<'TXT'
            User-agent: *
            Disallow: /.env
            Disallow: /backup-2023/
            Disallow: /phpmyadmin/
            Disallow: /*.sql$
            TXT);

        $this->assertStringContainsString('noindex', (string) $findings['sensitive-paths-specific']->fix);
        $this->assertStringContainsString('without the Disallow', (string) $findings['sensitive-paths-specific']->fix);

        foreach (['sensitive-paths-secrets', 'sensitive-paths-tools', 'sensitive-paths-wildcard'] as $id) {
            $this->assertStringNotContainsString('noindex', (string) $findings[$id]->fix);
        }
    }

    #[Test]
    public function it_tells_the_reader_to_rotate_credentials_when_a_secret_is_named(): void
    {
        $fix = (string) $this->findings("User-agent: *\nDisallow: /.git/")['sensitive-paths-secrets']->fix;

        $this->assertStringContainsString('web root', $fix);
        $this->assertStringContainsString('rotate', $fix);
    }

    #[Test]
    public function it_never_calls_a_listing_critical_and_says_the_exposure_depends_on_the_file_being_served(): void
    {
        $findings = $this->findings(<<<'TXT'
            User-agent: *
            Disallow: /.env
            Disallow: /.git/
            Disallow: /dump.sql
            Disallow: /backup-2023/
            Disallow: /phpmyadmin/
            Disallow: /*.sql$
            TXT);

        foreach ($findings as $finding) {
            $this->assertNotSame(Status::Critical, $finding->status);
        }

        $this->assertStringStartsWith('If this file is served', $findings['sensitive-paths-secrets']->impact);
    }

    #[Test]
    public function it_files_every_finding_under_security(): void
    {
        $findings = $this->findings(<<<'TXT'
            User-agent: *
            Disallow: /.env
            Disallow: /backup-2023/
            Disallow: /phpmyadmin/
            Disallow: /*.sql$
            TXT);

        $this->assertCount(4, $findings);

        foreach ($findings as $finding) {
            $this->assertSame(Category::Security, $finding->category);
            $this->assertSame('security', $finding->toArray()['category']);
        }
    }

    #[Test]
    public function it_takes_its_lists_from_the_constructor(): void
    {
        $check = new SensitivePathCheck(
            secretSegments: ['.vault'],
            secretPrefixes: ['.secrets'],
            templateSegments: ['.secrets.example'],
            dataExtensions: ['.parquet'],
            exportExtensions: ['.ods'],
            sensitiveTokens: ['archive'],
            weakTokens: ['tmp'],
            toolSegments: ['grafana'],
        );

        $findings = $this->findings(<<<'TXT'
            User-agent: *
            Disallow: /.vault/
            Disallow: /.secrets.live
            Disallow: /.secrets.example
            Disallow: /users.parquet
            Disallow: /prices.ods
            Disallow: /archive-2020/
            Disallow: /tmp-2020/
            Disallow: /tmp-files/
            Disallow: /grafana/
            Disallow: /.env
            Disallow: /backup-2023/
            Disallow: /dev-2023/
            Disallow: /export.csv
            Disallow: /phpmyadmin/
            TXT, $check);

        $lines = array_map(
            static fn (Finding $finding): array => array_map(static fn (Evidence $e): ?int => $e->line, $finding->evidence),
            $findings,
        );

        $this->assertSame([
            'sensitive-paths-secrets' => [2, 3, 5],
            'sensitive-paths-specific' => [6, 7, 8],
            'sensitive-paths-tools' => [10],
        ], $lines);
    }
}
