<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

/**
 * Recognises a bot challenge or block page answered in place of the page.
 *
 * Bot-protection layers answer an unrecognised client with their own page,
 * sometimes with status 200, and that page carries its own robots meta tag.
 * Read as the site's, it would report a noindex the site never set.
 *
 * Only specific markers count. A false negative leaves a challenge to be read
 * as a page, which is today's behaviour; a false positive would discard a
 * real page, so nothing is inferred from a CDN's name alone.
 */
final class ChallengeDetector
{
    /**
     * Response headers that only a challenge carries, by lower-case name.
     */
    private const HEADER_MARKERS = [
        // Cloudflare sets this on every challenge it serves, managed or JS.
        'cf-mitigated' => 'challenge',
    ];

    /**
     * Strings in the HTML that only a challenge page contains.
     */
    private const BODY_MARKERS = [
        // Cloudflare: the challenge configuration object and its script path.
        '_cf_chl_opt',
        '/cdn-cgi/challenge-platform/',
        // Cloudflare: the legacy "Checking your browser" interstitial.
        'cf-browser-verification',
        // Akamai Bot Manager: the crypto challenge's script path.
        '/_sec/cp_challenge/',
        // HUMAN (PerimeterX): the press-and-hold captcha container and app id.
        'id="px-captcha"',
        'window._pxAppId',
        // DataDome: the captcha is served from this host.
        'captcha-delivery.com',
    ];

    /**
     * Cloudflare's interstitial title. Generic enough that a real page could
     * use it, so it counts only on a response Cloudflare served.
     */
    private const CLOUDFLARE_TITLE = '<title>just a moment...</title>';

    /**
     * Whether the response headers alone name it a challenge, as on a 403
     * whose body is never read.
     */
    public function inHeaders(FetchResult $fetch): bool
    {
        foreach (self::HEADER_MARKERS as $name => $value) {
            foreach ($fetch->header($name) as $sent) {
                if (strcasecmp(trim($sent), $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a response with the given HTML is a challenge rather than the page.
     */
    public function isChallenge(FetchResult $fetch, string $html): bool
    {
        if ($this->inHeaders($fetch)) {
            return true;
        }

        foreach (self::BODY_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        $servedByCloudflare = in_array('cloudflare', array_map(
            static fn (string $server): string => strtolower(trim($server)),
            $fetch->header('Server'),
        ), true);

        return $servedByCloudflare && stripos($html, self::CLOUDFLARE_TITLE) !== false;
    }
}
