<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Matching;

/**
 * The structural facts about a path pattern, plus the prose describing them.
 *
 * Callers that want to render their own wording — or translate it — can read
 * the flags and ignore every string on this object.
 */
final readonly class PathExplanation
{
    /**
     * @param string $pattern      The pattern as written, including operators.
     * @param string $literal      The pattern with the end anchor stripped.
     * @param int    $specificity  Pattern length; longer wins on conflict.
     * @param int    $wildcards    Count of `*` operators.
     * @param bool   $endAnchor    Whether the pattern ends with `$`.
     * @param bool   $matchesAll   Whether an empty pattern makes this match everything.
     */
    public function __construct(
        public string $pattern,
        public string $literal,
        public int $specificity,
        public int $wildcards,
        public bool $endAnchor,
        public bool $matchesAll,
        public string $pathToMatch,
        public ?string $endAnchorNote,
        public ?string $wildcardNote,
        public string $specificityNote,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path_to_match' => $this->pathToMatch,
            'end_anchor' => $this->endAnchorNote,
            'wildcards' => $this->wildcardNote,
            'specificity' => [
                'value' => $this->specificity,
                'description' => $this->specificityNote,
            ],
        ];
    }
}
