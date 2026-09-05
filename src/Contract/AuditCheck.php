<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Contract;

use Leopoletto\RobotsTxtParser\Audit\Finding;
use Leopoletto\RobotsTxtParser\Response;

/**
 * One question asked of a parsed robots.txt.
 *
 * A check returns no findings when it has nothing to say, so adding a question
 * never means editing the report.
 *
 * @method list<Finding> run(Response $response)
 */
interface AuditCheck
{
    /**
     * @return list<Finding>
     */
    public function run(Response $response): array;
}
