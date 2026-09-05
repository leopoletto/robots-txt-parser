<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Record;

use Leopoletto\RobotsTxtParser\Contract\Record;
use Leopoletto\RobotsTxtParser\Validation\ValidationResult;

/**
 * One `<meta name="robots" content="...">` tag found on the requested page.
 */
final readonly class MetaDirective implements Record
{
    public function __construct(
        public string $name,
        public string $raw,
        public ValidationResult $validation,
    ) {
    }

    public function line(): int
    {
        // Meta tags come from the HTML page, not the robots.txt document.
        return 0;
    }

    public function toArray(): array
    {
        return ['tag_name' => $this->name, 'raw' => $this->raw] + $this->validation->toArray();
    }
}
