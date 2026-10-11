<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Category;
use Leopoletto\RobotsTxtParser\Audit\Evidence;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Flags paths a robots.txt gives away by naming them.
 *
 * robots.txt is world-readable and one of the first files any scanner fetches,
 * but listing a path is only a leak when it tells a reader something they could
 * not already guess. /admin, /wp-admin, /login, /api and a bare /staging or
 * /backup are in every wordlist, and Disallow is a legitimate way to keep
 * crawlers off them, so they are never reported.
 *
 * What is reported falls into one tier per line, the most severe it matches:
 * a secret or data file, which is a serious exposure if it is served; a sensitive name made
 * specific by a qualifier or by where it sits, or a spreadsheet named by its
 * full path, which nobody would have guessed; and a tool panel, which reveals
 * what is installed. A pattern with a wildcard names no location, so it is only
 * ever a note that such files may exist.
 *
 * Some words are too common to trust on a qualifier alone: "/test-drive/" and
 * "/dev-tools/" are public pages. Those weak tokens count only with a number
 * beside them, beside a stronger token, or below the first level.
 *
 * Allow lines are read as well: they disclose a path exactly as Disallow does.
 *
 * Nothing is fetched here, so nothing is known about whether a path answers.
 * A listing alone is therefore never critical: the worst this check reports is
 * a warning.
 */
final class SensitivePathCheck implements AuditCheck
{
    private const SECRETS = 'secrets';
    private const SPECIFIC = 'specific';
    private const TOOLS = 'tools';
    private const WILDCARD = 'wildcard';

    /** The most lines a finding quotes, however many it covers. */
    private const EVIDENCE_LIMIT = 8;

    /** @var list<string> */
    private readonly array $secretSegments;

    /** @var list<string> */
    private readonly array $secretPrefixes;

    /** @var list<string> */
    private readonly array $templateSegments;

    /** @var list<string> */
    private readonly array $dataExtensions;

    /** @var list<string> */
    private readonly array $exportExtensions;

    /** @var list<string> */
    private readonly array $toolSegments;

    /** Matches a sensitive token standing as a word of its own inside a segment. */
    private readonly ?string $tokenPattern;

    /** The same, for the tokens that need more than a qualifier to count. */
    private readonly ?string $weakTokenPattern;

    /**
     * @param list<string> $secretSegments   Segments that are a secret in themselves, wherever they sit.
     * @param list<string> $secretPrefixes   Segments that are a secret alone or with any suffix, as ".env.production" is.
     * @param list<string> $templateSegments Segments a prefix would catch that are templates holding no secret.
     * @param list<string> $dataExtensions   Endings of a literal path that mark a dump, archive, database, backup or log.
     * @param list<string> $exportExtensions Endings of a literal path that mark a spreadsheet, which may be public by design.
     * @param list<string> $sensitiveTokens  Words that are generic alone at the top level and telling once qualified.
     * @param list<string> $weakTokens       Words common in public paths, telling only with a number, a sensitive
     *                                       token beside them, or below the first level.
     * @param list<string> $toolSegments     Segments naming an administration tool.
     */
    public function __construct(
        array $secretSegments = ['.git', '.svn', '.hg', '.htpasswd', '.DS_Store'],
        array $secretPrefixes = ['.env'],
        array $templateSegments = ['.env.example', '.env.sample', '.env.dist'],
        array $dataExtensions = [
            '.sql', '.bak', '.dump', '.zip', '.tar', '.gz', '.tgz', '.7z', '.old', '.swp', '.log',
            '.sqlite', '.sqlite3', '.db', '.mdb',
        ],
        array $exportExtensions = ['.csv', '.xls', '.xlsx'],
        array $sensitiveTokens = [
            'backup', 'backups', 'dump', 'staging', 'stage', 'internal', 'private',
            'secret', 'old', 'legacy', 'export', 'db', 'uat', 'sandbox',
        ],
        array $weakTokens = ['dev', 'test', 'logs'],
        array $toolSegments = ['phpmyadmin', 'adminer', 'server-status', 'server-info'],
    ) {
        $this->secretSegments = array_map(strtolower(...), $secretSegments);
        $this->secretPrefixes = array_map(strtolower(...), $secretPrefixes);
        $this->templateSegments = array_map(strtolower(...), $templateSegments);
        $this->dataExtensions = array_map(strtolower(...), $dataExtensions);
        $this->exportExtensions = array_map(strtolower(...), $exportExtensions);
        $this->toolSegments = array_map(strtolower(...), $toolSegments);
        $this->tokenPattern = self::wordPattern($sensitiveTokens);
        $this->weakTokenPattern = self::wordPattern($weakTokens);
    }

    /**
     * A pattern finding any of the tokens as a word of its own.
     *
     * "-", "_", "." and digits separate words, so "staging2" and "old_site"
     * carry the token while "stagecoach" and "older" do not.
     *
     * @param list<string> $tokens
     */
    private static function wordPattern(array $tokens): ?string
    {
        $quoted = array_map(
            static fn (string $token): string => preg_quote(strtolower($token), '/'),
            $tokens,
        );

        return $quoted === [] ? null : '/(?:^|[-_.\d])(' . implode('|', $quoted) . ')(?=[-_.\d]|$)/';
    }

    public function run(Response $response): array
    {
        $document = $response->document();

        $directives = [
            ...$document->directives(DirectiveType::Disallow),
            ...$document->directives(DirectiveType::Allow),
        ];
        usort($directives, static fn (Directive $a, Directive $b): int => $a->line <=> $b->line);

        /** @var array<string, list<Evidence>> $matches */
        $matches = [];

        foreach ($directives as $directive) {
            $match = $this->classify($directive->value);

            if ($match !== null) {
                $matches[$match['tier']][] = new Evidence(
                    text: ($directive->type === DirectiveType::Allow ? 'Allow: ' : 'Disallow: ') . $directive->value,
                    line: $directive->line,
                    detail: $match['detail'],
                );
            }
        }

        $findings = [];

        foreach ([self::SECRETS, self::SPECIFIC, self::TOOLS, self::WILDCARD] as $tier) {
            if (isset($matches[$tier])) {
                $findings[] = $this->finding($tier, $matches[$tier]);
            }
        }

        return $findings;
    }

    /**
     * The tier a directive's value falls into, and what put it there.
     *
     * @return array{tier: string, detail: string}|null
     */
    private function classify(string $value): ?array
    {
        $path = strtolower(rtrim($value, '*$'));

        if (! str_contains($path, '*')) {
            return $this->classifyLiteral($path);
        }

        // A wildcard stands for text the file does not give, so it is read as
        // a separator and the pattern is never treated as a location.
        $match = $this->classifyLiteral(str_replace('*', '-', $path));

        return $match === null ? null : ['tier' => self::WILDCARD, 'detail' => $match['detail']];
    }

    /**
     * @return array{tier: string, detail: string}|null
     */
    private function classifyLiteral(string $path): ?array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        foreach ($segments as $segment) {
            if ($this->isSecret($segment)) {
                return ['tier' => self::SECRETS, 'detail' => "Secret file or directory ({$segment})"];
            }
        }

        foreach ($this->dataExtensions as $extension) {
            if (str_ends_with($path, $extension)) {
                return ['tier' => self::SECRETS, 'detail' => "Data file ({$extension})"];
            }
        }

        // A file's extension says nothing about its name: "/old.html" is the
        // bare word "old", exactly as "/old" is.
        if ($segments !== [] && ! str_ends_with($path, '/')) {
            $last = array_key_last($segments);
            $segments[$last] = preg_replace('/(?<=.)\.[a-z0-9]{1,5}$/', '', $segments[$last]) ?? $segments[$last];
        }

        foreach ($segments as $depth => $segment) {
            $token = self::wordIn($this->tokenPattern, $segment);

            // Alone at the top level the word is as guessable as "/admin".
            if ($token !== null && ($segment !== $token || $depth > 0)) {
                return [
                    'tier' => self::SPECIFIC,
                    'detail' => $segment !== $token ? "\"{$token}\" with a qualifier" : "\"{$token}\" below the first level",
                ];
            }

            $weak = self::wordIn($this->weakTokenPattern, $segment);

            if ($weak !== null && ($depth > 0 || preg_match('/\d/', $segment) === 1)) {
                return [
                    'tier' => self::SPECIFIC,
                    'detail' => $depth > 0 ? "\"{$weak}\" below the first level" : "\"{$weak}\" with a number",
                ];
            }
        }

        foreach ($this->exportExtensions as $extension) {
            if (str_ends_with($path, $extension)) {
                return ['tier' => self::SPECIFIC, 'detail' => "Spreadsheet ({$extension})"];
            }
        }

        foreach ($segments as $segment) {
            if (in_array($segment, $this->toolSegments, true)) {
                return ['tier' => self::TOOLS, 'detail' => $segment];
            }
        }

        return null;
    }

    /**
     * Whether a segment is a secret by its own name, or by a prefix such as
     * ".env" that any suffix but a template's leaves just as secret.
     */
    private function isSecret(string $segment): bool
    {
        if (in_array($segment, $this->secretSegments, true)) {
            return true;
        }

        if (in_array($segment, $this->templateSegments, true)) {
            return false;
        }

        foreach ($this->secretPrefixes as $prefix) {
            if ($segment === $prefix || str_starts_with($segment, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }

    private static function wordIn(?string $pattern, string $segment): ?string
    {
        if ($pattern === null || preg_match($pattern, $segment, $found) !== 1) {
            return null;
        }

        return $found[1];
    }

    /**
     * One finding for a tier, however many lines fell into it.
     *
     * @param list<Evidence> $evidence
     */
    private function finding(string $tier, array $evidence): Finding
    {
        $count = count($evidence);
        $rules = $count === 1 ? '1 rule' : "{$count} rules";
        $s = $count === 1 ? 's' : '';
        $quoted = array_slice($evidence, 0, self::EVIDENCE_LIMIT);

        return match ($tier) {
            self::SECRETS => new Finding(
                id: 'sensitive-paths-secrets',
                title: "{$rules} name{$s} a secret or data file",
                // TODO: a reachability check that requests these paths may raise
                // this to Critical once one of them answers 200. Until something
                // has confirmed the file is served, the listing is only a lead.
                status: Status::Warning,
                category: Category::Security,
                summary: 'Rules name version-control directories, credential files, databases, dumps, archives or logs.',
                impact: 'If this file is served, it is a critical exposure; listing it here suggests it exists. '
                    . 'robots.txt is public and among the first files a scanner reads, and it protects nothing: '
                    . 'it only tells readers where to look.',
                fix: 'Check whether these paths answer. Remove the files from the web root or block them at the '
                    . 'server, then delete the lines. If a .env file or a .git directory was ever served, rotate '
                    . 'every credential it held.',
                evidence: $quoted,
            ),
            self::SPECIFIC => new Finding(
                id: 'sensitive-paths-specific',
                title: "{$rules} name{$s} a location nobody would have guessed",
                status: Status::Warning,
                category: Category::Security,
                summary: 'Rules name a sensitive location made specific by a qualifier or by where it sits, '
                    . 'or a spreadsheet by its full path.',
                impact: 'A bare "/backup" is in every scanner wordlist; "/backup-2023/" is in none. Listing it '
                    . 'advertises the location to the readers least likely to honour the rule.',
                fix: 'Put these locations behind authentication or remove them; once they are protected, the '
                    . 'line can stay or go. Responses behind authentication are not indexed, so they need no '
                    . '"noindex". Content that is public by design and only has to stay out of search wants a '
                    . '"noindex" without the Disallow: a crawler only sees a noindex on a page it may fetch.',
                evidence: $quoted,
            ),
            self::TOOLS => new Finding(
                id: 'sensitive-paths-tools',
                title: "{$rules} reveal{$s} an installed tool",
                status: Status::Info,
                category: Category::Security,
                summary: 'Rules name an administration tool, which tells a reader it is installed.',
                impact: 'Scanners try these paths anyway, so the listing only matters if the tool answers.',
                fix: 'Make sure the tool is not publicly reachable: restrict it by address or put it behind '
                    . 'authentication.',
                evidence: $quoted,
            ),
            default => new Finding(
                id: 'sensitive-paths-wildcard',
                title: "{$rules} suggest{$s} sensitive files may exist",
                status: Status::Info,
                category: Category::Security,
                summary: 'Wildcard patterns name no location, but suggest such files may exist under public paths.',
                impact: 'A pattern such as "/*.sql$" discloses nothing a reader can request directly. It does '
                    . 'say what kind of file the site expects to have lying around.',
                fix: 'Verify that files matching these patterns are not served.',
                evidence: $quoted,
            ),
        };
    }
}
