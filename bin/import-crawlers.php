<?php

/**
 * Rebuilds src/data/crawlers.json — the crawler list the audit reports on —
 * from an export of Cloudflare Radar's bot directory.
 *
 * Radar has no public JSON endpoint for this, so the export is produced in the
 * browser and handed to this script, which validates it, maps Radar's purpose
 * labels onto the report's groups, and preserves the prose that explains what
 * blocking each group costs.
 *
 * Expected input: a JSON array of objects, each with at least an agent name.
 * Everything else is optional and filled in from the current list where absent.
 *
 *   [
 *     {
 *       "agent": "GPTBot",
 *       "operator": "OpenAI",
 *       "purpose": "AI Crawler",       // or "group": "ai_training"
 *       "description": "OpenAI model training"
 *     }
 *   ]
 *
 * Usage:
 *   php bin/import-crawlers.php --input=radar-export.json
 *   php bin/import-crawlers.php --input=radar-export.json --dry-run
 *   php bin/import-crawlers.php --input=radar-export.json --replace
 *
 * By default the export is merged into the existing list: known crawlers have
 * their operator and description refreshed, new ones are added to the group
 * their purpose maps to, and nothing is removed. --replace discards the current
 * crawler list first, for a full regeneration.
 */

declare(strict_types=1);

$options = getopt('', ['input:', 'output::', 'dry-run', 'replace']);

if (! isset($options['input'])) {
    fwrite(STDERR, "Usage: php bin/import-crawlers.php --input=radar-export.json [--dry-run] [--replace]\n");
    exit(1);
}

$inputPath = (string) $options['input'];
$outputPath = (string) ($options['output'] ?? __DIR__ . '/../src/data/crawlers.json');
$dryRun = isset($options['dry-run']);
$replace = isset($options['replace']);

/**
 * Radar labels a bot by what it is for. These are the phrasings seen in its
 * directory — both the human labels ("Training") and the machine codes
 * ("AI_CRAWLER", whose underscores are normalised to spaces before lookup) —
 * mapped onto the report's groups.
 *
 * Anything unrecognised is reported rather than silently filed in the wrong
 * place, because a crawler in the wrong group produces confidently wrong advice.
 */
const PURPOSE_MAP = [
    // Search indexes
    'search' => 'search',
    'search engine' => 'search',
    'search engine crawler' => 'search',

    // Answer engines that crawl in order to cite
    'ai search' => 'ai_search',
    'ai search crawler' => 'ai_search',
    'search augmentation' => 'ai_search',
    'grounding' => 'ai_search',

    // Fetches made because a person asked
    'ai assistant' => 'ai_user',
    'user action' => 'ai_user',
    'user-triggered' => 'ai_user',
    'user triggered fetcher' => 'ai_user',
    'page fetcher' => 'ai_user',

    // Link unfurlers
    'social media' => 'social',
    'link preview' => 'social',
    'page preview' => 'social',
    'fetcher' => 'social',

    // Model training
    'training' => 'ai_training',
    'ai training' => 'ai_training',
    'ai crawler' => 'ai_training',
    'ai data scraper' => 'ai_training',
    'undocumented ai agent' => 'ai_training',
];

// ------------------------------------------------------------------- loading

$current = readJson($outputPath) ?? [];
if ($current === []) {
    fail("Could not read the current crawler list at {$outputPath}. It supplies the group definitions.");
}

$groups = $current['groups'] ?? [];
if (! is_array($groups) || $groups === []) {
    fail('The current crawler list defines no groups; refusing to write a list nothing can be filed under.');
}

$export = readJson($inputPath);
if ($export === null) {
    fail("Could not read or parse {$inputPath}.");
}

// Accept either a bare array or an object wrapping one.
$rows = array_is_list($export) ? $export : ($export['crawlers'] ?? $export['bots'] ?? $export['data'] ?? null);
if (! is_array($rows) || ! array_is_list($rows)) {
    fail('Expected a JSON array of crawler objects, or an object with a "crawlers", "bots" or "data" array.');
}

info(sprintf('Read %d row(s) from %s', count($rows), $inputPath));

// -------------------------------------------------------------------- merging

/** @var array<string, array<string, string>> $existing keyed by lowercased agent */
$existing = [];
foreach ($replace ? [] : ($current['crawlers'] ?? []) as $crawler) {
    $agent = trim((string) ($crawler['agent'] ?? ''));
    if ($agent !== '') {
        $existing[strtolower($agent)] = $crawler;
    }
}

$added = [];
$updated = [];
$unmapped = [];

foreach ($rows as $row) {
    if (! is_array($row)) {
        continue;
    }

    $agent = trim((string) ($row['agent'] ?? $row['name'] ?? $row['user_agent'] ?? ''));
    if ($agent === '') {
        continue;
    }

    $key = strtolower($agent);
    $group = resolveGroup($row, $existing[$key]['group'] ?? null);

    if ($group === null) {
        $unmapped[] = $agent . ' (' . (string) ($row['purpose'] ?? $row['category'] ?? 'no purpose given') . ')';

        continue;
    }

    if (! isset($groups[$group])) {
        $unmapped[] = "{$agent} (maps to unknown group \"{$group}\")";

        continue;
    }

    $entry = [
        'agent' => $agent,
        'group' => $group,
        'operator' => trim((string) ($row['operator'] ?? $row['company'] ?? $existing[$key]['operator'] ?? '')),
        'description' => trim((string) ($row['description'] ?? $existing[$key]['description'] ?? $agent)),
    ];

    if ($entry['operator'] === '') {
        unset($entry['operator']);
    }

    isset($existing[$key]) ? $updated[] = $agent : $added[] = $agent;
    $existing[$key] = $entry;
}

// --------------------------------------------------------------------- report

printf("\nAdded   %d\n", count($added));
foreach (array_slice($added, 0, 25) as $agent) {
    echo "  + {$agent}\n";
}
if (count($added) > 25) {
    printf("  … and %d more\n", count($added) - 25);
}

printf("Updated %d\n", count($updated));

if ($unmapped !== []) {
    printf("\nSkipped %d row(s) whose purpose did not map to a group:\n", count($unmapped));
    foreach (array_slice($unmapped, 0, 20) as $line) {
        echo "  ? {$line}\n";
    }
    echo "Add the purpose to PURPOSE_MAP in this script, or set \"group\" on the row.\n";
}

if ($dryRun) {
    info("\nDry run — nothing written.");
    exit(0);
}

// ---------------------------------------------------------------------- write

$crawlers = array_values($existing);
usort($crawlers, static function (array $a, array $b) use ($groups): int {
    $order = array_keys($groups);
    $byGroup = array_search($a['group'], $order, true) <=> array_search($b['group'], $order, true);

    return $byGroup !== 0 ? $byGroup : strcasecmp($a['agent'], $b['agent']);
});

$current['crawlers'] = $crawlers;
$current['source'] = [
    'name' => 'Cloudflare Radar',
    'url' => (string) ($current['source']['url'] ?? 'https://radar.cloudflare.com/bots'),
    'note' => (string) ($current['source']['note'] ?? ''),
    'retrieved_at' => gmdate('Y-m-d'),
];

file_put_contents(
    $outputPath,
    json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

printf("\nWrote %d crawlers to %s\n", count($crawlers), $outputPath);

// ------------------------------------------------------------------ functions

/**
 * Decide which report group a row belongs to: an explicit group wins, then the
 * row's stated purpose, then whatever the crawler was already filed under.
 *
 * @param array<string, mixed> $row
 */
function resolveGroup(array $row, ?string $fallback): ?string
{
    $explicit = trim((string) ($row['group'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }

    // Try each label the export might carry, in order of specificity. An
    // empty value must fall through rather than end the search, which "??"
    // would not do.
    foreach (['purpose', 'category', 'type'] as $field) {
        $value = strtolower(trim((string) ($row[$field] ?? '')));
        $value = str_replace('_', ' ', $value);

        if ($value !== '' && isset(PURPOSE_MAP[$value])) {
            return PURPOSE_MAP[$value];
        }
    }

    return $fallback;
}

/**
 * @return array<mixed>|null
 */
function readJson(string $path): ?array
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
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

function info(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}
