<?php

namespace Leopoletto\RobotsTxtParser\Records;

use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use Leopoletto\RobotsTxtParser\Records\RobotsDirective;
use Leopoletto\RobotsTxtParser\Records\UserAgent;
use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use Leopoletto\RobotsTxtParser\Records\Comment; 
use Leopoletto\RobotsTxtParser\Records\Sitemap;

class RobotsCustomCollection extends Collection
{

    public static function build($data): self
    {
        return new static($data);
    }

    public function sitemaps(): RobotsCustomCollection
    {
        return new RobotsCustomCollection($this->filter(fn ($item) => $item instanceof Sitemap)->values())
            ->map(fn($item) => [
                'line' => $item->line,
                'url' => $item->url,
                'valid' => $item->valid
            ])->values();
    }

    public function userAgents(?string $userAgent = null): RobotsCustomCollection
    {
        $userAgents = $this->filter(fn($item) => $item instanceof UserAgent)->values();

        if (!is_null($userAgent)) {
            $userAgents = $userAgents->filter(fn($item) => $item->userAgent === $userAgent);
        }

        return new RobotsCustomCollection($userAgents)->map(fn($item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent,
            'allow' => $this->allowed($item->userAgent)->toArray(),
            'disallow' => $this->disallowed($item->userAgent)->toArray(),
            'crawlDelay' => $this->crawlDelay($item->userAgent)->toArray(),
        ])->keyBy('userAgent');
    }

    public function lines(): int
    {
        return $this->count();
    }

    public function comments(): RobotsCustomCollection
    {
        return new RobotsCustomCollection($this->filter(fn ($item) => $item instanceof Comment)->values())
            ->map(fn($item) => [
                'line' => $item->line,
                'comment' => $item->comment
            ])->values();
    }

    public function disallowed(?string $userAgent = null): RobotsCustomCollection
    {
        $disallowed = $this->filter(fn ($item) => $item instanceof RobotsDirective && $item->directive === 'disallow');

        if (!is_null($userAgent)) {
            $disallowed = $disallowed->filter(fn($item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCustomCollection($disallowed)->map(fn($item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent->userAgent,
            'directive' => $item->directive,
            'path' => $item->path
        ])->values();
    }

    public function allowed(?string $userAgent = null): RobotsCustomCollection
    {
        $allowed = $this->filter(fn($item) => $item instanceof RobotsDirective && $item->directive === 'allow');

        if (!is_null($userAgent)) {
            $allowed = $allowed->filter(fn($item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCustomCollection($allowed)->map(fn($item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent->userAgent,
            'directive' => $item->directive,
            'path' => $item->path
        ])->values();
    }

    public function crawlDelay(?string $userAgent = null): RobotsCustomCollection
    {
        $crawlDelay = $this->filter(fn($item) => $item instanceof RobotsDirective && $item->directive === 'crawl-delay');

        if (!is_null($userAgent)) {
            $crawlDelay = $crawlDelay->filter(fn($item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCustomCollection($crawlDelay)->map(fn($item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent->userAgent,
            'directive' => $item->directive,
            'delay' => (int) $item->path
        ])->values();
    }

    public function robotsTxtDirectives(): RobotsCustomCollection
    {
        return new RobotsCustomCollection($this->filter(fn ($item) => $item instanceof RobotsDirective)->values())
        ->map(fn($item) => [
            'line' => $item->line,
            'userAgent' => $item->userAgent->userAgent,
            'directive' => $item->directive,
            'path' => $item->path
        ])->values();
    }

    public function headersDirectives(): RobotsCustomCollection
    {
        return new RobotsCustomCollection($this->filter(fn ($item) => $item instanceof HeaderDirective)->values())
            ->map(fn(HeaderDirective $item) => $item->directives)->values();
    }

    public function metaTagsDirectives(): RobotsCustomCollection
    {
        return new RobotsCustomCollection($this->filter(fn ($item) => $item instanceof MetaDirective)->values())
            ->map(fn(MetaDirective $item) => $item->directives)->values();
    }

    public function combinedDirectives(): RobotsCustomCollection
    {
        return new RobotsCustomCollection(
            $this->filter(fn ($item) => $item instanceof HeaderDirective || $item instanceof MetaDirective)
            ->merge($this->filter(fn ($item) => $item instanceof RobotsDirective))
            ->values()
        );
    }
}