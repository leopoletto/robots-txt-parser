<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Tests\Unit;

use Leopoletto\RobotsTxtParser\Audit\Category;
use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Audit\Report;
use Leopoletto\RobotsTxtParser\Audit\Status;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    private function finding(Status $status): Finding
    {
        return new Finding(
            id: 'example-' . $status->value,
            title: 'Example',
            status: $status,
            category: Category::Indexing,
            summary: 'Example summary.',
            impact: 'Example impact.',
        );
    }

    #[Test]
    public function not_checked_weighs_nothing_and_asks_for_nothing(): void
    {
        $this->assertSame('not_checked', Status::NotChecked->value);
        $this->assertSame(0, Status::NotChecked->weight());
        $this->assertFalse(Status::NotChecked->isActionable());
    }

    #[Test]
    public function the_worst_status_ignores_what_was_not_checked(): void
    {
        $report = new Report([$this->finding(Status::NotChecked)]);
        $this->assertSame(Status::Pass, $report->worst());

        $report = new Report([$this->finding(Status::NotChecked), $this->finding(Status::Notice)]);
        $this->assertSame(Status::Notice, $report->worst());
    }

    #[Test]
    public function counts_include_what_was_not_checked(): void
    {
        $counts = (new Report([$this->finding(Status::NotChecked), $this->finding(Status::Pass)]))->counts();

        $this->assertSame(1, $counts['not_checked']);
        $this->assertSame(1, $counts['pass']);
    }
}
