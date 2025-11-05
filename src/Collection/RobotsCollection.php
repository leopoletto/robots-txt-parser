<?php

namespace Leopoletto\RobotsTxtParser\Collection;

use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Records\HeaderDirective;
use Leopoletto\RobotsTxtParser\Records\RobotsDirective;
use Leopoletto\RobotsTxtParser\Records\UserAgent;
use Leopoletto\RobotsTxtParser\Records\MetaDirective;
use Leopoletto\RobotsTxtParser\Records\Comment; 
use Leopoletto\RobotsTxtParser\Records\Sitemap;

class RobotsCollection extends Collection
{
    protected $displayUserAgent = false;
    
    public static function build($items = []): self
    {
        return new self($items);
    }

    public function sitemaps(): RobotsCollection
    {
        return new RobotsCollection($this->filter(fn ($item) => $item instanceof Sitemap)->values())
            ->map(fn(Sitemap $item) => [
                'line' => $item->line,
                'url' => $item->url,
                'valid' => $item->valid
            ])->values();
    }

    public function userAgents(?string $userAgent = null): RobotsCollection
    {
        $userAgents = $this->filter(fn($item) => $item instanceof UserAgent)->values();

        if (!is_null($userAgent)) {
            $userAgents = $userAgents->filter(fn(UserAgent $item) => $item->userAgent === $userAgent);
        }

        return new RobotsCollection($userAgents)->map(fn(UserAgent $item) => [
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

    public function comments(): RobotsCollection
    {
        return new RobotsCollection($this->filter(fn ($item) => $item instanceof Comment)->values())
            ->map(fn(Comment $item) => [
                'line' => $item->line,
                'comment' => $item->comment
            ])->values();
    }

    public function disallowed(?string $userAgent = null): RobotsCollection
    {
        $disallowed = $this->filter(fn ($item) => $item instanceof RobotsDirective && $item->directive === 'disallow');

        if (!is_null($userAgent)) {
            $disallowed = $disallowed->filter(fn(RobotsDirective $item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCollection($disallowed)
            ->displayUserAgent($this->displayUserAgent)
            ->map(function (RobotsDirective $item) {
                $response = [
                    'line' => $item->line,
                    'directive' => $item->directive,
                    'path' => $item->path
                ];

                if ($this->displayUserAgent) {
                    $response['userAgent'] = $item->userAgent->userAgent;
                }

                return $response;
        })->values();

        $this->displayUserAgent(false);
    }


    public function allowed(?string $userAgent = null): RobotsCollection
    {
        $allowed = $this->filter(fn($item) => $item instanceof RobotsDirective && $item->directive === 'allow');

        if (!is_null($userAgent)) {
            $allowed = $allowed->filter(fn(RobotsDirective $item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCollection($allowed)
            ->displayUserAgent($this->displayUserAgent)
            ->map(function(RobotsDirective $item) {
                $response = [
                    'line' => $item->line,
                    'directive' => $item->directive,
                    'path' => $item->path
                ];

                if ($this->displayUserAgent) {
                    $response['userAgent'] = $item->userAgent->userAgent;
                }

                return $response;
            })
            ->values();

        $this->displayUserAgent(false);
    }

    public function crawlDelay(?string $userAgent = null): RobotsCollection
    {
        $crawlDelay = $this->filter(fn($item) => $item instanceof RobotsDirective && $item->directive === 'crawl-delay');

        if (!is_null($userAgent)) {
            $crawlDelay = $crawlDelay->filter(fn(RobotsDirective $item) => $item->userAgent->userAgent === $userAgent);
        }

        return new RobotsCollection($crawlDelay)
            ->displayUserAgent($this->displayUserAgent)
            ->map(function(RobotsDirective $item) {
                $response = [
                    'line' => $item->line,
                    'directive' => $item->directive,
                    'delay' => (int) $item->path
                ];

                if ($this->displayUserAgent) {
                    $response['userAgent'] = $item->userAgent->userAgent;
                }

                return $response;
            })
            ->values();

        $this->displayUserAgent(false);
    }

    public function robotsTxtDirectives(): RobotsCollection
    {
        return new RobotsCollection($this->filter(fn ($item) => $item instanceof RobotsDirective)->values())
            ->displayUserAgent($this->displayUserAgent)
            ->map(function (RobotsDirective $item) {
                $response = [
                    'line' => $item->line,
                    'directive' => $item->directive,
                    'path' => $item->path
                ];

                if ($this->displayUserAgent) {
                    $response['userAgent'] = $item->userAgent->userAgent;
                }
                
                return $response;
            })
            ->values();

        $this->displayUserAgent(false);
    }

    public function displayUserAgent(bool $displayUserAgent = true): self
    {
        $this->displayUserAgent = $displayUserAgent;
        return $this;
    }

    public function headersDirectives(): RobotsCollection
    {
        return new RobotsCollection($this->filter(fn ($item) => $item instanceof HeaderDirective)->values())
            ->map(fn(HeaderDirective $item) => $item->directives)->values();
    }

    public function metaTagsDirectives(): RobotsCollection
    {
        return new RobotsCollection($this->filter(fn ($item) => $item instanceof MetaDirective)->values())
            ->map(fn(MetaDirective $item) => $item->directives)->values();
    }

    public function combinedDirectives(): RobotsCollection
    {
        return new RobotsCollection(
            $this->filter(fn ($item) => $item instanceof HeaderDirective || $item instanceof MetaDirective)
            ->merge($this->filter(fn ($item) => $item instanceof RobotsDirective))
            ->values()
        );
    }
}