<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * The result of auditing one robots.txt.
 *
 * Findings arrive ordered by severity, so the thing most worth fixing is the
 * thing read first.
 */
final readonly class Report
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(public array $findings)
    {
    }

    /**
     * @return list<Finding>
     */
    public function actionable(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->isActionable()));
    }

    /**
     * @return list<Finding>
     */
    public function withStatus(Status $status): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $f->status === $status));
    }

    public function has(string $id): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->id === $id) {
                return true;
            }
        }

        return false;
    }

    public function find(string $id): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * The worst status present, which is what a summary badge should show.
     */
    public function worst(): Status
    {
        $worst = Status::Pass;

        foreach ($this->findings as $finding) {
            if ($finding->status->weight() > $worst->weight()) {
                $worst = $finding->status;
            }
        }

        return $worst;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [
            Status::Critical->value => 0,
            Status::Warning->value => 0,
            Status::Notice->value => 0,
            Status::Pass->value => 0,
        ];

        foreach ($this->findings as $finding) {
            $counts[$finding->status->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->worst()->value,
            'counts' => $this->counts(),
            'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
