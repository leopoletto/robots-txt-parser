<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Exception;

use RuntimeException;

/**
 * Base for every exception this package raises, so callers can catch one type.
 */
class RobotsTxtParserException extends RuntimeException
{
}
