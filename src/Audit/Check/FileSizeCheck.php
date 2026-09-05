<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit\Check;

use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Status;
use Leopoletto\RobotsTxtParser\Contract\AuditCheck;
use Leopoletto\RobotsTxtParser\Http\HttpConfiguration;
use Leopoletto\RobotsTxtParser\Response;

/**
 * Checks the file against the 500 KB ceiling Google enforces.
 *
 * Past that point the rest of the file is not read at all, so the rules most
 * likely to be dropped are the ones added most recently.
 */
final class FileSizeCheck implements AuditCheck
{
    private const LIMIT = HttpConfiguration::DEFAULT_MAX_BYTES;

    /** Point at which a file is close enough to the ceiling to mention. */
    private const CROWDED = 0.5;

    public function run(Response $response): array
    {
        $size = $response->size();
        if ($size === 0) {
            return [];
        }

        $readable = $this->format($size);

        if ($response->truncated() || $size >= self::LIMIT) {
            return [new Finding(
                id: 'size-over-limit',
                title: "The file exceeds the 500 KB crawl limit ({$readable})",
                status: Status::Critical,
                summary: 'Google reads at most 500 KB of a robots.txt and ignores everything after it.',
                impact: 'Rules beyond the cut-off are never applied. Because new rules are usually '
                    . 'appended, the ones being dropped are typically the most recent — and the '
                    . 'file gives no indication that anything was lost.',
                fix: 'Consolidate rules with wildcards, remove groups for agents that no longer '
                    . 'matter, and drop duplicate rules repeated per user agent. A group naming '
                    . 'several agents applies to all of them, so repeating a block per agent is '
                    . 'usually avoidable.',
            )];
        }

        if ($size >= self::LIMIT * self::CROWDED) {
            return [new Finding(
                id: 'size-approaching-limit',
                title: "The file is {$readable}, over half the crawl limit",
                status: Status::Notice,
                summary: 'Google stops reading a robots.txt at 500 KB.',
                impact: 'There is still headroom, but a file this size is usually repeating the same '
                    . 'rules across many user-agent groups, which is hard to keep consistent.',
                fix: 'Where several agents share a rule set, declare them as consecutive User-agent '
                    . 'lines above one group rather than repeating the block for each.',
            )];
        }

        return [new Finding(
            id: 'size-ok',
            title: "File size is {$readable}",
            status: Status::Pass,
            summary: 'Comfortably within the 500 KB that crawlers will read.',
            impact: 'The whole file is read, so every rule in it applies.',
        )];
    }

    private function format(int $bytes): string
    {
        return $bytes >= 1024
            ? sprintf('%.1f KB', $bytes / 1024)
            : "{$bytes} bytes";
    }
}
