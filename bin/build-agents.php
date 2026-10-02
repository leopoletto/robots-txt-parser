<?php

/**
 * Builds the sharded agent dataset consumed by ShardedAgentRepository.
 *
 * Reads the canonical dataset (src/data/agents.source.json) and emits one shard
 * per leading letter into src/data/agents/, plus an index.json manifest. Each
 * shard is an object keyed by the lowercased agent name so a lookup is a single
 * file read followed by a hash hit — never a scan of all 1600+ agents.
 *
 * Usage: php bin/build-agents.php [--source=path] [--out=path]
 */

declare(strict_types=1);

$options = getopt('', ['source::', 'out::']);
$sourcePath = $options['source'] ?? __DIR__ . '/../src/data/agents.source.json';
$outputDir = $options['out'] ?? __DIR__ . '/../src/data/agents';

if (! is_file($sourcePath)) {
    fwrite(STDERR, "Source dataset not found: {$sourcePath}\n");
    exit(1);
}

$raw = file_get_contents($sourcePath);
if ($raw === false) {
    fwrite(STDERR, "Could not read source dataset: {$sourcePath}\n");
    exit(1);
}

try {
    $agents = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "Source dataset is not valid JSON: {$e->getMessage()}\n");
    exit(1);
}

if (! is_array($agents)) {
    fwrite(STDERR, "Source dataset must be a JSON array of agent objects.\n");
    exit(1);
}

/** @var array<string, array<string, array<string, string|null>>> $shards */
$shards = [];
$skipped = 0;

foreach ($agents as $agent) {
    $name = trim((string) ($agent['agent'] ?? ''));
    if ($name === '') {
        $skipped++;

        continue;
    }

    $key = mb_strtolower($name);
    $shard = shardKey($key);

    // First declaration wins; the upstream list occasionally repeats a name.
    if (isset($shards[$shard][$key])) {
        continue;
    }

    $shards[$shard][$key] = [
        'name' => $name,
        'category' => nullableString($agent['category'] ?? null),
        'description' => nullableString($agent['description'] ?? null),
        'path' => nullableString($agent['ahref'] ?? $agent['path'] ?? null),
    ];
}

if (! is_dir($outputDir) && ! mkdir($outputDir, 0o755, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Could not create output directory: {$outputDir}\n");
    exit(1);
}

// Remove stale shards so a rebuild after a rename never leaves orphans behind.
foreach (glob($outputDir . '/*.json') ?: [] as $stale) {
    unlink($stale);
}

ksort($shards);

$manifest = [];
$total = 0;

foreach ($shards as $shard => $entries) {
    ksort($entries);
    $encoded = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents($outputDir . '/' . $shard . '.json', $encoded);

    $manifest[$shard] = count($entries);
    $total += count($entries);

    printf("  %-3s %4d agents  %6.1f KB\n", $shard, count($entries), strlen($encoded) / 1024);
}

file_put_contents(
    $outputDir . '/index.json',
    json_encode([
        'version' => 1,
        'generated_at' => gmdate('c'),
        'total' => $total,
        'shards' => $manifest,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);

printf("\nBuilt %d agents across %d shards into %s\n", $total, count($manifest), $outputDir);
if ($skipped > 0) {
    printf("Skipped %d entries with an empty agent name.\n", $skipped);
}

/**
 * Group agents by their leading ASCII letter; everything else lands in "_".
 */
function shardKey(string $lowercasedName): string
{
    $first = $lowercasedName[0] ?? '_';

    return $first >= 'a' && $first <= 'z' ? $first : '_';
}

function nullableString(mixed $value): ?string
{
    if (! is_string($value)) {
        return null;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? null : $trimmed;
}
