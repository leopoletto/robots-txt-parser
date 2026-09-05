<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Agents\ShardedAgentRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShardedAgentRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        ShardedAgentRepository::flush();
    }

    #[Test]
    public function it_resolves_a_known_agent(): void
    {
        $agent = (new ShardedAgentRepository())->find('GPTBot');

        $this->assertNotNull($agent);
        $this->assertSame('GPTBot', $agent->name);
        $this->assertNotNull($agent->category);
        $this->assertNotEmpty($agent->description);
    }

    #[Test]
    public function lookup_is_case_insensitive(): void
    {
        $repository = new ShardedAgentRepository();

        $this->assertSame('GPTBot', $repository->find('gptbot')?->name);
        $this->assertSame('GPTBot', $repository->find('GPTBOT')?->name);
    }

    #[Test]
    public function it_trims_surrounding_whitespace(): void
    {
        $this->assertNotNull((new ShardedAgentRepository())->find('  GPTBot  '));
    }

    #[Test]
    public function it_returns_null_for_an_unknown_agent(): void
    {
        $this->assertNull((new ShardedAgentRepository())->find('DefinitelyNotARealBot'));
    }

    #[Test]
    public function it_returns_null_for_an_empty_name(): void
    {
        $this->assertNull((new ShardedAgentRepository())->find(''));
        $this->assertNull((new ShardedAgentRepository())->find('   '));
    }

    #[Test]
    public function it_resolves_an_agent_whose_name_starts_with_a_non_letter(): void
    {
        // Names beginning with a digit or symbol land in the "_" shard.
        $this->assertNotNull((new ShardedAgentRepository())->find('360Spider'));
    }

    #[Test]
    public function it_reports_the_dataset_size(): void
    {
        $this->assertGreaterThan(1000, (new ShardedAgentRepository())->count());
    }

    #[Test]
    public function a_missing_dataset_directory_degrades_to_no_matches(): void
    {
        $repository = new ShardedAgentRepository('/nonexistent/path/agents');

        $this->assertNull($repository->find('GPTBot'));
        $this->assertNull($repository->count());
    }

    #[Test]
    public function it_only_reads_the_shard_a_lookup_needs(): void
    {
        $directory = sys_get_temp_dir() . '/robots-agents-' . uniqid();
        mkdir($directory);

        file_put_contents($directory . '/index.json', json_encode([
            'shards' => ['g' => 1, 'z' => 1],
        ]));
        file_put_contents($directory . '/g.json', json_encode([
            'gptbot' => ['name' => 'GPTBot', 'category' => 'AI Data Scraper'],
        ]));
        // Deliberately corrupt: reaching for it would surface as a failure.
        file_put_contents($directory . '/z.json', '{ not json');

        $repository = new ShardedAgentRepository($directory);

        $this->assertSame('GPTBot', $repository->find('GPTBot')?->name);

        // A letter absent from the manifest never touches the filesystem.
        $this->assertNull($repository->find('Qbot'));

        array_map('unlink', glob($directory . '/*.json') ?: []);
        rmdir($directory);
    }
}
