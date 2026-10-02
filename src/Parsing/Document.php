<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Parsing;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Matching\Decision;
use Leopoletto\RobotsTxtParser\Matching\RuleResolver;
use Leopoletto\RobotsTxtParser\Model\DirectiveType;
use Leopoletto\RobotsTxtParser\Model\EffectiveRules;
use Leopoletto\RobotsTxtParser\Model\Group;
use Leopoletto\RobotsTxtParser\Record\Comment;
use Leopoletto\RobotsTxtParser\Record\Directive;
use Leopoletto\RobotsTxtParser\Record\HeaderDirective;
use Leopoletto\RobotsTxtParser\Record\Issue;
use Leopoletto\RobotsTxtParser\Record\MetaDirective;
use Leopoletto\RobotsTxtParser\Record\Sitemap;
use Leopoletto\RobotsTxtParser\Record\UserAgent;

/**
 * A parsed robots.txt document: its records in source order, and the groups
 * they form.
 *
 * Every query method is pure — nothing here carries state between calls, so a
 * Document can be held, shared and queried repeatedly without surprises.
 */
final class Document
{
    private ?RuleResolver $resolver = null;

    /**
     * @param list<Record> $records
     * @param list<Group>  $groups
     */
    public function __construct(
        private readonly array $records,
        private readonly array $groups,
        private readonly int $size = 0,
        private readonly bool $truncated = false,
    ) {
    }

    /**
     * A copy with extra records appended — used to attach HTTP headers and
     * meta tags discovered after the body was parsed.
     *
     * @param list<Record> $records
     */
    public function withRecords(array $records): self
    {
        return new self([...$this->records, ...$records], $this->groups, $this->size, $this->truncated);
    }

    /** @return list<Record> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Number of records recovered — not the document's line count, which
     * includes blanks and is available from the highest record line.
     */
    public function count(): int
    {
        return count($this->records);
    }

    /** @return list<UserAgent> */
    public function userAgents(): array
    {
        return $this->ofType(UserAgent::class);
    }

    /** @return list<Comment> */
    public function comments(): array
    {
        return $this->ofType(Comment::class);
    }

    /** @return list<Sitemap> */
    public function sitemaps(): array
    {
        return $this->ofType(Sitemap::class);
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return $this->ofType(Issue::class);
    }

    /** @return list<HeaderDirective> */
    public function headerDirectives(): array
    {
        return $this->ofType(HeaderDirective::class);
    }

    /** @return list<MetaDirective> */
    public function metaDirectives(): array
    {
        return $this->ofType(MetaDirective::class);
    }

    /**
     * Directives, optionally narrowed to one type and/or one user agent.
     *
     * Passing a user agent applies the same group-selection rules as an
     * allowance check, wildcard fallback included.
     *
     * @return list<Directive>
     */
    public function directives(?DirectiveType $type = null, ?string $userAgent = null): array
    {
        if ($userAgent === null) {
            $directives = $this->ofType(Directive::class);

            return $type === null
                ? $directives
                : array_values(array_filter($directives, static fn (Directive $d): bool => $d->type === $type));
        }

        $directives = [];
        foreach ($this->resolver()->groupsFor($userAgent) as $group) {
            foreach ($group->directives($type) as $directive) {
                $directives[] = $directive;
            }
        }

        return $directives;
    }

    /** @return list<Directive> */
    public function allowed(?string $userAgent = null): array
    {
        return $this->directives(DirectiveType::Allow, $userAgent);
    }

    /** @return list<Directive> */
    public function disallowed(?string $userAgent = null): array
    {
        return $this->directives(DirectiveType::Disallow, $userAgent);
    }

    /** @return list<Directive> */
    public function crawlDelay(?string $userAgent = null): array
    {
        return $this->directives(DirectiveType::CrawlDelay, $userAgent);
    }

    /**
     * The group governing a user agent, or null when the document has none and
     * declares no wildcard group either.
     */
    public function groupFor(string $userAgent): ?Group
    {
        return $this->resolver()->groupsFor($userAgent)[0] ?? null;
    }

    public function isAllowed(string $userAgent, string $path): bool
    {
        return $this->resolver()->isAllowed($userAgent, $path);
    }

    public function decide(string $userAgent, string $path): Decision
    {
        return $this->resolver()->decide($userAgent, $path);
    }

    public function resolver(): RuleResolver
    {
        return $this->resolver ??= new RuleResolver($this->groups);
    }

    /**
     * The combined effect of the page's meta tags and X-Robots-Tag headers.
     */
    public function effectiveRules(): EffectiveRules
    {
        return EffectiveRules::from($this->metaDirectives(), $this->headerDirectives());
    }

    /**
     * A JSON-ready summary of everything the document contains.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'truncated' => $this->truncated,
            'userAgents' => $this->summariseUserAgents(),
            'sitemaps' => self::mapToArray($this->sitemaps()),
            'comments' => self::mapToArray($this->comments()),
            'issues' => self::mapToArray($this->issues()),
            'headers' => self::mapToArray($this->headerDirectives()),
            'metaTags' => self::mapToArray($this->metaDirectives()),
        ];
    }

    /**
     * One entry per declared user agent, with the rules that govern it.
     *
     * @return list<array<string, mixed>>
     */
    public function summariseUserAgents(): array
    {
        $summary = [];

        foreach ($this->groups as $group) {
            foreach ($group->userAgents() as $userAgent) {
                $summary[] = $userAgent->toArray() + [
                    'allow' => self::mapToArray($group->directives(DirectiveType::Allow)),
                    'disallow' => self::mapToArray($group->directives(DirectiveType::Disallow)),
                    'crawlDelay' => self::mapToArray($group->directives(DirectiveType::CrawlDelay)),
                ];
            }
        }

        return $summary;
    }

    /**
     * @template T of Record
     * @param class-string<T> $class
     * @return list<T>
     */
    private function ofType(string $class): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (Record $record): bool => $record instanceof $class
        ));
    }

    /**
     * @param list<Record> $records
     * @return list<array<string, mixed>>
     */
    private static function mapToArray(array $records): array
    {
        return array_map(static fn (Record $record): array => $record->toArray(), $records);
    }
}
