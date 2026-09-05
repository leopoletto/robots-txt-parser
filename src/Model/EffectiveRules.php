<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Model;

use Leopoletto\RobotsTxtParser\Record\HeaderDirective;
use Leopoletto\RobotsTxtParser\Record\MetaDirective;
use Leopoletto\RobotsTxtParser\Validation\ParsedDirective;
use Leopoletto\RobotsTxtParser\Validation\ValidationResult;

/**
 * What a page's meta tags and X-Robots-Tag headers add up to.
 *
 * Where the two disagree Google takes the more restrictive reading, so that is
 * what this resolves to — a later `index` never re-opens an earlier `noindex`.
 */
final class EffectiveRules
{
    private bool $index = true;

    private bool $follow = true;

    private bool $archive = true;

    private bool $translate = true;

    private bool $imageIndex = true;

    private int $maxSnippet = -1;

    private string $maxImagePreview = 'standard';

    private int $maxVideoPreview = -1;

    private int $metaCount = 0;

    private int $headerCount = 0;

    /**
     * @param list<MetaDirective>   $meta
     * @param list<HeaderDirective> $headers
     */
    public static function from(array $meta, array $headers): self
    {
        $rules = new self();

        foreach ($meta as $directive) {
            $rules->metaCount++;
            $rules->apply($directive->validation);
        }

        foreach ($headers as $directive) {
            $rules->headerCount++;
            $rules->apply($directive->validation);
        }

        return $rules;
    }

    private function apply(ValidationResult $validation): void
    {
        foreach ($validation->directives as $directive) {
            $this->applyOne($directive);
        }
    }

    private function applyOne(ParsedDirective $directive): void
    {
        switch ($directive->name) {
            case 'none':
                // Restrictions only ever accumulate.
                $this->index = false;
                $this->follow = false;

                break;

            case 'noindex':
                $this->index = false;

                break;

            case 'nofollow':
                $this->follow = false;

                break;

            case 'noarchive':
                $this->archive = false;

                break;

            case 'notranslate':
                $this->translate = false;

                break;

            case 'noimageindex':
                $this->imageIndex = false;

                break;

            case 'nosnippet':
                $this->maxSnippet = 0;

                break;

            case 'max-snippet':
                $this->maxSnippet = $this->tighterLength($this->maxSnippet, (int) $directive->value);

                break;

            case 'max-video-preview':
                $this->maxVideoPreview = $this->tighterLength($this->maxVideoPreview, (int) $directive->value);

                break;

            case 'max-image-preview':
                $this->maxImagePreview = $this->tighterPreview($directive->value);

                break;
        }
    }

    /**
     * For length limits, -1 means unlimited, so it is the loosest value rather
     * than the smallest.
     */
    private function tighterLength(int $current, int $candidate): int
    {
        if ($current === -1) {
            return $candidate;
        }

        if ($candidate === -1) {
            return $current;
        }

        return min($current, $candidate);
    }

    private function tighterPreview(?string $candidate): string
    {
        $order = ['none' => 0, 'standard' => 1, 'large' => 2];
        $candidate = strtolower((string) $candidate);

        if (! isset($order[$candidate])) {
            return $this->maxImagePreview;
        }

        return $order[$candidate] < $order[$this->maxImagePreview] ? $candidate : $this->maxImagePreview;
    }

    public function indexable(): bool
    {
        return $this->index;
    }

    public function followable(): bool
    {
        return $this->follow;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'effective_rules' => [
                'index' => $this->index,
                'follow' => $this->follow,
                'archive' => $this->archive,
                'translate' => $this->translate,
                'image_index' => $this->imageIndex,
                'max_snippet' => $this->maxSnippet,
                'max_image_preview' => $this->maxImagePreview,
                'max_video_preview' => $this->maxVideoPreview,
            ],
            'sources' => [
                'meta_count' => $this->metaCount,
                'header_count' => $this->headerCount,
            ],
        ];
    }
}
