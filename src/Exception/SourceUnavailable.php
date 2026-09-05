<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Exception;

/**
 * The content to parse could not be reached or read.
 */
final class SourceUnavailable extends RobotsTxtParserException
{
    public static function fileNotFound(string $path): self
    {
        return new self("File not found: {$path}");
    }

    public static function fileNotReadable(string $path): self
    {
        return new self("File is not readable: {$path}");
    }

    public static function requestFailed(string $url, string $reason): self
    {
        return new self("Could not fetch {$url}: {$reason}");
    }
}
