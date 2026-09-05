<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Agents;

use JsonException;
use Leopoletto\RobotsTxtParser\Contract\AgentRepository;

/**
 * Reads the agent dataset from per-letter shards, loading only the shard a
 * lookup actually needs.
 *
 * The dataset is ~1600 agents; decoding it whole costs ~470 KB per parser
 * instance. Sharding by leading letter brings a lookup down to a single file
 * of ~17 KB on average (58 KB worst case), and the manifest lets a miss on an
 * absent letter resolve without touching the filesystem at all.
 *
 * Decoded shards are cached statically: robots.txt files repeat user-agent
 * tokens heavily, and a host application typically parses many documents per
 * process.
 */
final class ShardedAgentRepository implements AgentRepository
{
    /**
     * Decoded shards, keyed by "{directory}\0{shard}".
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $shardCache = [];

    /**
     * Shard manifests, keyed by directory. Maps shard key to agent count.
     *
     * @var array<string, array<string, int>>
     */
    private static array $manifestCache = [];

    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? __DIR__ . '/../data/agents', '/');
    }

    public function find(string $name): ?Agent
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return null;
        }

        $shard = self::shardKey($key);

        // The manifest tells us which shards exist, so an unknown leading
        // character costs nothing beyond the already-cached manifest.
        $manifest = $this->manifest();
        if ($manifest !== null && ! isset($manifest[$shard])) {
            return null;
        }

        $entries = $this->shard($shard);
        if (! isset($entries[$key]) || ! is_array($entries[$key])) {
            return null;
        }

        return Agent::fromArray($entries[$key]);
    }

    /**
     * Total number of agents in the dataset, or null when no manifest is present.
     */
    public function count(): ?int
    {
        $manifest = $this->manifest();

        return $manifest === null ? null : array_sum($manifest);
    }

    /**
     * Drop cached shards. Intended for tests and long-lived workers that swap
     * the dataset on disk.
     */
    public static function flush(): void
    {
        self::$shardCache = [];
        self::$manifestCache = [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function shard(string $shard): array
    {
        $cacheKey = $this->directory . "\0" . $shard;

        if (isset(self::$shardCache[$cacheKey])) {
            return self::$shardCache[$cacheKey];
        }

        $decoded = $this->readJson($this->directory . '/' . $shard . '.json');

        return self::$shardCache[$cacheKey] = is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, int>|null
     */
    private function manifest(): ?array
    {
        if (array_key_exists($this->directory, self::$manifestCache)) {
            return self::$manifestCache[$this->directory] ?: null;
        }

        $decoded = $this->readJson($this->directory . '/index.json');
        $shards = is_array($decoded) && is_array($decoded['shards'] ?? null) ? $decoded['shards'] : [];

        self::$manifestCache[$this->directory] = $shards;

        return $shards ?: null;
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupt shard degrades to "agent unknown" rather than breaking
            // the parse; agent metadata is descriptive, never load-bearing.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function shardKey(string $lowercasedName): string
    {
        $first = $lowercasedName[0] ?? '_';

        return $first >= 'a' && $first <= 'z' ? $first : '_';
    }
}
