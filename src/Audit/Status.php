<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Audit;

/**
 * How much attention a finding deserves.
 *
 * A robots.txt is a policy document, not code: blocking a crawler is a choice
 * until something proves otherwise. So severity answers "how confident are we
 * that this is unintended", never "how expensive would this be if it were a
 * mistake". What a rule costs belongs in the finding's prose, where the reader
 * can weigh it, rather than in a badge that decides for them.
 */
enum Status: string
{
    /** Nothing to do. */
    case Pass = 'pass';

    /** A policy readout: this is what the file says, with no judgement. */
    case Info = 'info';

    /** Deliberate-looking, but with a consequence worth stating. */
    case Notice = 'notice';

    /** Internally incoherent: the rule plainly did not hit its intended target. */
    case Warning = 'warning';

    /** Cannot be what anyone intended. */
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::Warning => 3,
            self::Notice => 2,
            self::Info => 1,
            self::Pass => 0,
        };
    }

    /**
     * Whether this status asks the reader to change something.
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::Critical, self::Warning, self::Notice => true,
            self::Info, self::Pass => false,
        };
    }
}
