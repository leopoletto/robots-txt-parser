<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Model;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
