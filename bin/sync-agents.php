<?php

/**
 * Refreshes the canonical agent dataset from knownagents.com.
 *
 * The roster comes from the site's sitemap rather than its 36 paginated
 * listing pages — one request instead of thirty-six, and it never misses an
 * agent that pagination would have shifted between pages mid-crawl.
 *
 * Each agent's own page supplies the full description; the listing pages
 * truncate it. Detail pages are fetched only for agents the local dataset does
 * not already have, so a routine sync costs one request per genuinely new
 * agent rather than 1,700.
 *
 * Politeness is not optional here: this package exists to report on robots.txt,
 * so the sync reads the site's own robots.txt through this very parser and
 * stops if it is not permitted to proceed. Requests are paced and sequential.
 *
 * Usage:
 *   php bin/sync-agents.php                 # add agents missing locally
 *   php bin/sync-agents.php --refresh       # re-fetch every agent
 *   php bin/sync-agents.php --limit=25      # cap detail fetches (try it out)
 *   php bin/sync-agents.php --dry-run       # report changes, write nothing
 *   php bin/sync-agents.php --delay=500     # ms between requests (default 350)
 */

declare(strict_types=1);

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

require __DIR__ . '/../vendor/autoload.php';

const BASE_URL = 'https://knownagents.com';
const SITEMAP_URL = BASE_URL . '/sitemap.xml';
const VERSION = '3.0';

$options = getopt('', ['refresh', 'dry-run', 'limit::', 'delay::', 'source::']);
$refresh = isset($options['refresh']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
$delayMs = isset($options['delay']) ? max(0, (int) $options['delay']) : 350;
$sourcePath = $options['source'] ?? __DIR__ . '/../src/data/agents.source.json';

$userAgent = sprintf(
    'Mozilla/5.0 (compatible; RobotsTxtParser/%s; +https://github.com/leopoletto/robots-txt-parser)',
    VERSION
);

// ---------------------------------------------------------------- permission

info('Checking ' . BASE_URL . '/robots.txt');

$parser = (new RobotsTxtParser())->withUserAgent($userAgent);
$robots = $parser->parseUrl(BASE_URL . '/agents');

if ($robots->error() !== null) {
    fail('Could not read robots.txt: ' . $robots->error());
}

if (! $robots->document()->isAllowed('RobotsTxtParser', '/agents/')) {
    fail('robots.txt disallows /agents/ for this bot. Stopping.');
}

foreach ($robots->document()->crawlDelay('RobotsTxtParser') as $directive) {
    $declared = (int) round(($directive->delay() ?? 0) * 1000);
    if ($declared > $delayMs) {
        $delayMs = $declared;
        info("Honouring declared crawl-delay of {$declared}ms");
    }
}

info("Permitted. Pacing requests {$delayMs}ms apart.");

// ------------------------------------------------------------- local dataset

$existing = [];
if (is_file($sourcePath)) {
    $decoded = json_decode((string) file_get_contents($sourcePath), true);
    foreach (is_array($decoded) ? $decoded : [] as $agent) {
        $name = trim((string) ($agent['agent'] ?? ''));
        if ($name !== '') {
            $existing[mb_strtolower($name)] = $agent;
        }
    }
}

info(sprintf('Local dataset holds %d agents.', count($existing)));

// -------------------------------------------------------------- agent roster

info('Fetching sitemap');
$sitemap = get(SITEMAP_URL, $userAgent);
if ($sitemap === null) {
    fail('Could not fetch the sitemap.');
}

preg_match_all('#<loc>\s*([^<\s]+/agents/[^<\s]+)\s*</loc>#i', $sitemap, $matches);
$urls = array_values(array_unique($matches[1]));

if ($urls === []) {
    fail('The sitemap listed no agent pages; the site layout may have changed.');
}

info(sprintf('Sitemap lists %d agent pages.', count($urls)));

// Map each URL to the slug it ends with, so a locally-known agent can be
// recognised without fetching its page.
$knownSlugs = [];
foreach ($existing as $agent) {
    $path = (string) ($agent['ahref'] ?? $agent['path'] ?? '');
    if ($path !== '') {
        $knownSlugs[basename($path)] = true;
    }
}

$queue = [];
foreach ($urls as $url) {
    $slug = basename(parse_url($url, PHP_URL_PATH) ?: '');
    if ($slug === '' || $slug === 'agents') {
        continue;
    }

    if (! $refresh && isset($knownSlugs[$slug])) {
        continue;
    }

    $queue[$slug] = $url;
}

if ($limit !== null) {
    $queue = array_slice($queue, 0, $limit, true);
}

if ($queue === []) {
    info('Nothing to fetch — the local dataset is already current.');
    exit(0);
}

info(sprintf('%d agent page(s) to fetch.%s', count($queue), $dryRun ? ' (dry run)' : ''));

// -------------------------------------------------------------------- detail

$added = [];
$failed = [];
$position = 0;

foreach ($queue as $slug => $url) {
    $position++;
    printf("  [%d/%d] %s\n", $position, count($queue), $slug);

    $html = get($url, $userAgent);
    if ($html === null) {
        $failed[] = $slug;
        usleep($delayMs * 1000);

        continue;
    }

    $agent = parseAgentPage($html, $slug);
    if ($agent === null) {
        $failed[] = $slug;
        usleep($delayMs * 1000);

        continue;
    }

    $added[mb_strtolower($agent['agent'])] = $agent;

    // Pace requests. The site is someone else's to run, not ours to hammer.
    usleep($delayMs * 1000);
}

// -------------------------------------------------------------------- report

printf("\nFetched %d agent(s).\n", count($added));
if ($failed !== []) {
    printf("Could not parse %d page(s): %s\n", count($failed), implode(', ', array_slice($failed, 0, 10)));
}

$new = array_diff_key($added, $existing);
printf("New agents: %d\n", count($new));
foreach (array_slice($new, 0, 20) as $agent) {
    printf("  + %s (%s)\n", $agent['agent'], $agent['category'] ?? 'Uncategorized');
}
if (count($new) > 20) {
    printf("  … and %d more\n", count($new) - 20);
}

if ($dryRun) {
    info('Dry run — nothing written.');
    exit(0);
}

$merged = array_values($added + $existing);
usort($merged, static fn (array $a, array $b): int => strcasecmp($a['agent'], $b['agent']));

file_put_contents(
    $sourcePath,
    json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

printf("\nWrote %d agents to %s\n", count($merged), $sourcePath);
info('Now run: composer agents:build');

// ----------------------------------------------------------------- functions

/**
 * Pull an agent's details out of its own page.
 *
 * Anchors matter here: a detail page also lists *related* agents in a sidebar,
 * so the first ".name agent-name" and the first ".tag" on the page belong to a
 * different agent entirely. The h1 and the "agent-type" block are the ones
 * that describe the page's own subject.
 *
 * @return array<string, string|bool|null>|null
 */
function parseAgentPage(string $html, string $slug): ?array
{
    // "What is AmazonBuyForMe?" — the only h1 on the page.
    $name = match_first($html, '#<h1[^>]*>\s*What is\s+(.*?)\??\s*</h1>#is')
        ?? match_first($html, '#<title>\s*What Is\s+(.*?)\?#is');

    if ($name === null || $name === '') {
        return null;
    }

    return array_filter([
        'ahref' => '/agents/' . $slug,
        'agent' => decode($name),

        // The category block for this agent, not a sidebar entry.
        'category' => decode(
            match_first($html, '#<div class="agent-type"[^>]*>\s*<div class="tag"[^>]*>\s*([^<]+?)\s*<#is')
                ?? 'Uncategorized'
        ),

        // The listing pages truncate descriptions; the meta tag carries it whole.
        'description' => decode(match_first($html, '#<meta name="description" content="([^"]*)"#i') ?? ''),

        'operator' => decode(tableValue($html, 'Operated By') ?? ''),
        'source' => match_first($html, '#<td>Source</td>\s*<td><a[^>]*href="([^"]+)"#is'),
        'respects_robots_txt' => match (strtolower((string) tableValue($html, 'Expected To Follow Robots.txt'))) {
            'yes' => true,
            'no' => false,
            default => null,
        },
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
}

/**
 * Read a cell out of the page's key/value overview table.
 */
function tableValue(string $html, string $label): ?string
{
    $pattern = '#<td>\s*' . preg_quote($label, '#') . '\s*</td>\s*<td>(.*?)</td>#is';

    $value = match_first($html, $pattern);

    return $value === null ? null : trim(strip_tags($value));
}

function decode(string $value): string
{
    return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
}

function match_first(string $subject, string $pattern): ?string
{
    return preg_match($pattern, $subject, $m) === 1 ? trim($m[1]) : null;
}

function get(string $url, string $userAgent): ?string
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return is_string($body) && $status === 200 ? $body : null;
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
