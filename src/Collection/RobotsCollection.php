<?php

namespace Leopoletto\RobotsTxtParser\Collection;

use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Records\Comment;
use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use Leopoletto\RobotsTxtParser\Records\RobotsDirective;
use Leopoletto\RobotsTxtParser\Records\Sitemap;
use Leopoletto\RobotsTxtParser\Records\SyntaxError;
use Leopoletto\RobotsTxtParser\Records\UserAgent;

class RobotsCollection extends Collection
{
    protected bool $displayUserAgent = false;
    protected ?array $userAgentGroups = null;

    public static function build($items = []): self
    {
        return new self($items);
    }

    /**
     * Build a map of user agent groups (consecutive user agents belong to the same group)
     * Returns array mapping user agent name to array of all user agents in the same group
     */
    protected function buildUserAgentGroups(): array
    {
        if ($this->userAgentGroups !== null) {
            return $this->userAgentGroups;
        }

        $groups = [];
        $currentGroup = [];
        $previousWasUserAgent = false;

        foreach ($this->items as $item) {
            if ($item instanceof UserAgent) {
                if ($previousWasUserAgent) {
                    // Consecutive User-agent - add to current group
                    $currentGroup[] = $item->userAgent;
                } else {
                    // New User-agent group - finalize previous group if exists
                    if (! empty($currentGroup)) {
                        foreach ($currentGroup as $ua) {
                            $groups[$ua] = $currentGroup;
                        }
                    }
                    // Start a new group with this user agent
                    $currentGroup = [$item->userAgent];
                }
                $previousWasUserAgent = true;
            } elseif ($item instanceof RobotsDirective) {
                // Directives belong to the current group, don't finalize
                $previousWasUserAgent = false;
            } else {
                // Other record types (comments, sitemaps, etc.) finalize the current group
                if (! empty($currentGroup)) {
                    foreach ($currentGroup as $ua) {
                        $groups[$ua] = $currentGroup;
                    }
                    $currentGroup = [];
                }
                $previousWasUserAgent = false;
            }
        }

        // Finalize any remaining group
        if (! empty($currentGroup)) {
            foreach ($currentGroup as $ua) {
                $groups[$ua] = $currentGroup;
            }
        }

        $this->userAgentGroups = $groups;

        return $groups;
    }

    /**
     * Get all user agents in the same group as the given user agent
     */
    protected function getUserAgentGroup(string $userAgent): array
    {
        $groups = $this->buildUserAgentGroups();

        return $groups[$userAgent] ?? [$userAgent];
    }

    public function sitemaps(): RobotsCollection
    {
        return (new RobotsCollection($this->filter(fn ($item) => $item instanceof Sitemap)->values()))
            ->map(fn (Sitemap $item) => [
                'line' => $item->line,
                'url' => $item->url,
                'valid' => $item->valid,
            ])->values();
    }

    public function userAgents(?string $userAgent = null): RobotsCollection
    {
        $userAgents = $this->filter(fn ($item) => $item instanceof UserAgent)->values();

        if (! is_null($userAgent)) {
            $userAgents = $userAgents->filter(fn (UserAgent $item) => $item->userAgent === $userAgent);
        }

        return (new RobotsCollection($userAgents))->map(fn (UserAgent $item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent,
            'description' => $item->description,
            'category' => $item->category,
            'allow' => $this->allowed($item->userAgent)->toArray(),
            'disallow' => $this->disallowed($item->userAgent)->toArray(),
            'crawlDelay' => $this->crawlDelay($item->userAgent)->toArray(),
        ])->keyBy('userAgent');
    }

    public function lines(): int
    {
        return $this->count();
    }

    public function comments(): RobotsCollection
    {
        return (new RobotsCollection($this->filter(fn ($item) => $item instanceof Comment)->values()))
            ->map(fn (Comment $item) => [
                'line' => $item->line,
                'comment' => $item->comment,
            ])->values();
    }

    public function disallowed(?string $userAgent = null): RobotsCollection
    {
        // Get all disallow directives (stored once, not duplicated)
        $disallowed = $this->filter(
            fn ($item) => $item instanceof RobotsDirective && $item->directive === 'disallow'
        );

        $results = [];

        if (! is_null($userAgent)) {
            // Find all user agents in the same group as the queried user agent
            $groupUserAgents = $this->getUserAgentGroup($userAgent);

            // Filter directives that belong to any user agent in the group
            // The directive is stored with the first user agent in its group, so we check
            // if the directive's user agent group intersects with the queried user agent's group
            foreach ($disallowed as $directive) {
                $directiveGroup = $this->getUserAgentGroup($directive->userAgent->userAgent);
                if (! empty(array_intersect($groupUserAgents, $directiveGroup))) {
                    // Expand directive for all user agents in the group if displayUserAgent is active
                    if ($this->displayUserAgent) {
                        foreach ($groupUserAgents as $ua) {
                            $results[] = [
                                'line' => $directive->line,
                                'directive' => $directive->directive,
                                'path' => $directive->path,
                                'info' => $directive->info,
                                'userAgent' => $ua,
                            ];
                        }
                    } else {
                        $results[] = [
                            'line' => $directive->line,
                            'directive' => $directive->directive,
                            'path' => $directive->path,
                            'info' => $directive->info,
                        ];
                    }
                }
            }
        } else {
            // No user agent specified - return unique directives
            $seen = [];
            foreach ($disallowed as $directive) {
                $key = $directive->line . '|' . $directive->path;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result = [
                        'line' => $directive->line,
                        'directive' => $directive->directive,
                        'path' => $directive->path,
                        'info' => $directive->info,
                    ];

                    if ($this->displayUserAgent) {
                        // Show all user agents in the group for this directive
                        $groupUserAgents = $this->getUserAgentGroup($directive->userAgent->userAgent);
                        $result['userAgent'] = $groupUserAgents;
                    }

                    $results[] = $result;
                }
            }
        }

        $displayUserAgent = $this->displayUserAgent;
        $this->displayUserAgent = false;

        $result = (new RobotsCollection($results))->values();

        // Restore the original displayUserAgent value
        $this->displayUserAgent = $displayUserAgent;

        return $result;
    }

    public function allowed(?string $userAgent = null): RobotsCollection
    {
        // Get all allow directives (stored once, not duplicated)
        $allowed = $this->filter(fn ($item) => $item instanceof RobotsDirective && $item->directive === 'allow');

        $results = [];

        if (! is_null($userAgent)) {
            // Find all user agents in the same group as the queried user agent
            $groupUserAgents = $this->getUserAgentGroup($userAgent);

            // Filter directives that belong to any user agent in the group
            // The directive is stored with the first user agent in its group, so we check
            // if the directive's user agent group intersects with the queried user agent's group
            foreach ($allowed as $directive) {
                $directiveGroup = $this->getUserAgentGroup($directive->userAgent->userAgent);
                if (! empty(array_intersect($groupUserAgents, $directiveGroup))) {
                    // Expand directive for all user agents in the group if displayUserAgent is active
                    if ($this->displayUserAgent) {
                        foreach ($groupUserAgents as $ua) {
                            $results[] = [
                                'line' => $directive->line,
                                'directive' => $directive->directive,
                                'path' => $directive->path,
                                'info' => $directive->info,
                                'userAgent' => $ua,
                            ];
                        }
                    } else {
                        $results[] = [
                            'line' => $directive->line,
                            'directive' => $directive->directive,
                            'path' => $directive->path,
                            'info' => $directive->info,
                        ];
                    }
                }
            }
        } else {
            // No user agent specified - return unique directives
            $seen = [];
            foreach ($allowed as $directive) {
                $key = $directive->line . '|' . $directive->path;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result = [
                        'line' => $directive->line,
                        'directive' => $directive->directive,
                        'path' => $directive->path,
                        'info' => $directive->info,
                    ];

                    if ($this->displayUserAgent) {
                        // Show all user agents in the group for this directive
                        $groupUserAgents = $this->getUserAgentGroup($directive->userAgent->userAgent);
                        $result['userAgent'] = $groupUserAgents;
                    }

                    $results[] = $result;
                }
            }
        }

        $displayUserAgent = $this->displayUserAgent;
        $this->displayUserAgent = false;

        $result = (new RobotsCollection($results))->values();

        // Restore the original displayUserAgent value
        $this->displayUserAgent = $displayUserAgent;

        return $result;
    }

    public function crawlDelay(?string $userAgent = null): RobotsCollection
    {
        // Get all crawl-delay directives (stored once, not duplicated)
        $crawlDelay = $this->filter(fn ($item) => $item instanceof RobotsDirective && $item->directive === 'crawl-delay');

        $results = [];

        if (! is_null($userAgent)) {
            // Find all user agents in the same group as the queried user agent
            $groupUserAgents = $this->getUserAgentGroup($userAgent);

            // Filter directives that belong to any user agent in the group
            // The directive is stored with the first user agent in its group, so we check
            // if the directive's user agent group intersects with the queried user agent's group
            foreach ($crawlDelay as $directive) {
                $directiveGroup = $this->getUserAgentGroup($directive->userAgent->userAgent);
                if (! empty(array_intersect($groupUserAgents, $directiveGroup))) {
                    // Expand directive for all user agents in the group if displayUserAgent is active
                    if ($this->displayUserAgent) {
                        foreach ($groupUserAgents as $ua) {
                            $results[] = [
                                'line' => $directive->line,
                                'directive' => $directive->directive,
                                'delay' => (int) $directive->path,
                                'userAgent' => $ua,
                            ];
                        }
                    } else {
                        $results[] = [
                            'line' => $directive->line,
                            'directive' => $directive->directive,
                            'delay' => (int) $directive->path,
                        ];
                    }
                }
            }
        } else {
            // No user agent specified - return unique directives
            $seen = [];
            foreach ($crawlDelay as $directive) {
                $key = $directive->line . '|' . $directive->path;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $result = [
                        'line' => $directive->line,
                        'directive' => $directive->directive,
                        'delay' => (int) $directive->path,
                    ];

                    if ($this->displayUserAgent) {
                        // Show all user agents in the group for this directive
                        $groupUserAgents = $this->getUserAgentGroup($directive->userAgent->userAgent);
                        $result['userAgent'] = $groupUserAgents;
                    }

                    $results[] = $result;
                }
            }
        }

        $displayUserAgent = $this->displayUserAgent;
        $this->displayUserAgent = false;

        $result = (new RobotsCollection($results))->values();

        // Restore the original displayUserAgent value
        $this->displayUserAgent = $displayUserAgent;

        return $result;
    }

    public function robotsTxtDirectives(): RobotsCollection
    {
        $collection = (new RobotsCollection(
            $this->filter(fn ($item) => $item instanceof RobotsDirective)->values()
        ))->displayUserAgent($this->displayUserAgent)
            ->map(function (RobotsDirective $item) {
                $response = [
                    'line' => $item->line,
                    'directive' => $item->directive,
                    'path' => $item->path,
                ];

                if ($this->displayUserAgent) {
                    $response['userAgent'] = $item->userAgent->userAgent;
                }

                return $response;
            })
            ->values();

        $this->displayUserAgent(false);

        return $collection;
    }

    public function displayUserAgent(bool $displayUserAgent = true): self
    {
        $this->displayUserAgent = $displayUserAgent;

        return $this;
    }

    public function headersDirectives(): RobotsCollection
    {
        return (new RobotsCollection($this->filter(fn ($item) => $item instanceof HeaderDirective)->values()))
            ->map(fn (HeaderDirective $item) => $item->directives)->values();
    }

    public function metaTagsDirectives(): RobotsCollection
    {
        return (new RobotsCollection($this->filter(fn ($item) => $item instanceof MetaDirective)->values()))
            ->map(fn (MetaDirective $item) => $item->directives)
            ->values();
    }

    public function combinedDirectives(): RobotsCollection
    {
        return new RobotsCollection(
            $this->filter(fn ($item) => $item instanceof HeaderDirective || $item instanceof MetaDirective)
            ->merge($this->filter(fn ($item) => $item instanceof RobotsDirective))
            ->values()
        );
    }

    public function syntaxErrors(): RobotsCollection
    {
        return (new RobotsCollection(
            $this->filter(fn ($item) => $item instanceof SyntaxError)->values()
        ))
            ->map(fn (SyntaxError $item) => [
                'line' => $item->line,
                'message' => $item->message,
            ])->values();
    }

    /**
     * Check if a user agent is allowed to access a specific path
     * Falls back to wildcard (*) rules if the user agent is not found
     *
     * @param string $userAgent The user agent name (e.g., 'GPTBot')
     * @param string $path The path to check (e.g., '/ja-jp/community/search?q=hello')
     * @return bool True if allowed, false if disallowed
     */
    public function uaAllowed(string $userAgent, string $path): bool
    {
        // Normalize path - ensure it starts with /
        // Empty path is kept as empty for $ pattern matching, but we'll also check against /
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        // Get all user agents to check if the specified one exists (case-insensitive)
        $userAgents = $this->filter(fn ($item) => $item instanceof UserAgent)->values();
        $foundUserAgent = $userAgents->first(
            fn (UserAgent $item) =>
            strtolower($item->userAgent) === strtolower($userAgent)
        );

        // If user agent exists, use its actual name (preserving case); otherwise fall back to wildcard
        $targetUserAgent = $foundUserAgent ? $foundUserAgent->userAgent : '*';

        // Get all allow and disallow rules for the target user agent
        $allowRules = $this->allowed($targetUserAgent)->toArray();
        $disallowRules = $this->disallowed($targetUserAgent)->toArray();

        // Combine all rules with their type and sort by specificity (longer paths first)
        $allRules = [];
        foreach ($allowRules as $rule) {
            $allRules[] = [
                'type' => 'allow',
                'path' => $rule['path'],
                'line' => $rule['line'],
            ];
        }
        foreach ($disallowRules as $rule) {
            $allRules[] = [
                'type' => 'disallow',
                'path' => $rule['path'],
                'line' => $rule['line'],
            ];
        }

        // Sort by path length (longer = more specific) and then by line number
        usort($allRules, function ($a, $b) {
            $lenA = strlen($a['path']);
            $lenB = strlen($b['path']);
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA; // Longer paths first
            }

            return $a['line'] <=> $b['line']; // Earlier lines first if same length
        });

        // Check each rule in order of specificity
        foreach ($allRules as $rule) {
            if ($this->pathMatches($rule['path'], $path)) {
                // First matching rule wins
                return $rule['type'] === 'allow';
            }
        }

        // If no rules match, default is allowed
        return true;
    }

    /**
     * Alias for uaAllowed() - Check if a user agent is allowed to access a specific path
     *
     * @param string $userAgent The user agent name (e.g., 'GPTBot')
     * @param string $path The path to check (e.g., '/ja-jp/community/search?q=hello')
     * @return bool True if allowed, false if disallowed
     */
    public function isAllowed(string $userAgent, string $path): bool
    {
        return $this->uaAllowed($userAgent, $path);
    }

    /**
     * Check if a path matches a robots.txt pattern
     * Supports * (wildcard) and $ (end anchor) operators
     *
     * @param string $pattern The pattern from robots.txt (e.g., '/make/$', '/exit?*')
     * @param string $path The path to check (e.g., '/ja-jp/community/search?q=hello')
     * @return bool True if the path matches the pattern
     */
    protected function pathMatches(string $pattern, string $path): bool
    {
        // Handle empty pattern (matches everything)
        if ($pattern === '') {
            return true;
        }

        // Check for end anchor ($) - it must be at the end
        $hasEndAnchor = str_ends_with($pattern, '$');
        if ($hasEndAnchor) {
            // Remove $ from pattern
            $pattern = rtrim($pattern, '$');
            if ($pattern === '') {
                // Pattern is just $, matches only empty path
                return $path === '';
            }
        }

        // Convert pattern to regex
        // Escape special regex characters except *
        $regex = preg_quote($pattern, '/');
        // Replace escaped \* with .* (match any sequence of characters)
        $regex = str_replace('\\*', '.*', $regex);
        // Anchor to start of path
        $regex = '^' . $regex;

        // If end anchor was specified, also anchor to end
        if ($hasEndAnchor) {
            $regex .= '$';
        }

        // Match the pattern against the path
        return preg_match('/' . $regex . '/', $path) === 1;
    }
}
