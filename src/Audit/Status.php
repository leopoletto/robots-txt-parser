<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * How much attention a finding deserves.
 *
 * A robots.txt is a policy document, not code: most of what looks wrong may be
 * deliberate. So findings state what is true and why it matters, and only
 * claim something is broken when it cannot be anything else.
 */
enum Status: string
{
    /** Nothing to do. */
    case Pass = 'pass';

    /** Worth knowing; probably deliberate. */
    case Notice = 'notice';

    /** Likely costing visibility or leaking information. */
    case Warning = 'warning';

    /** Actively preventing indexing, or a rule no crawler can honour. */
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Notice => 1,
            self::Pass => 0,
        };
    }
}
