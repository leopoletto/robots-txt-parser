<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Leopoletto\RobotsTxtParser\Exception\SourceUnavailable;

/**
 * Locates the robots.txt that governs a URL.
 *
 * robots.txt is per-origin (scheme + host + port), so the path, query and
 * fragment are dropped — but the port is not. Dropping it would send an
 * analysis of http://localhost:8000/app to the wrong server entirely.
 */
final class RobotsUrl
{
    public static function forOrigin(string $url): string
    {
        $parts = parse_url(self::withScheme($url));

        if ($parts === false || ! isset($parts['host'])) {
            throw SourceUnavailable::requestFailed($url, 'the URL has no host');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $authority = $parts['host'];

        if (isset($parts['port']) && ! self::isDefaultPort($scheme, $parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $scheme . '://' . $authority . '/robots.txt';
    }

    /**
     * Whether the URL already points at a robots.txt, in which case there is no
     * separate page to inspect for meta tags.
     */
    public static function isRobotsTxt(string $url): bool
    {
        $path = parse_url(self::withScheme($url), PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower(rtrim($path, '/')), '/robots.txt');
    }

    /**
     * The path-and-query a robots.txt rule is matched against.
     */
    public static function targetPath(string $url): string
    {
        $normalized = self::withScheme($url);
        $path = parse_url($normalized, PHP_URL_PATH);
        $query = parse_url($normalized, PHP_URL_QUERY);

        $target = is_string($path) && $path !== '' ? $path : '/';

        return is_string($query) && $query !== '' ? $target . '?' . $query : $target;
    }

    /**
     * Accept "example.com/page" as well as a fully-qualified URL.
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 ? $url : 'https://' . $url;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
