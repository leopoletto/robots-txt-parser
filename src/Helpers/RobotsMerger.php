<?php

namespace Leopoletto\RobotsTxtParser\Helpers;

class RobotsMerger
{
    /**
     * Merge meta tag and header directives (most restrictive wins)
     *
     * @param array $metaDirectives From MetaDirective
     * @param array $headerDirectives From HeaderDirective
     * @return array Effective rules
     */
    public static function merge(array $metaDirectives, array $headerDirectives): array
    {
        $effective = [
            'index' => true,
            'follow' => true,
            'max_snippet' => -1,
            'max_image_preview' => 'standard',
            'max_video_preview' => -1,
            'archive' => true,
            'translate' => true,
            'image_index' => true,
        ];

        // Apply meta directives
        foreach ($metaDirectives as $meta) {
            self::applyDirectives($effective, $meta['directives']);
        }

        // Apply header directives (can override meta)
        foreach ($headerDirectives as $header) {
            self::applyDirectives($effective, $header['directives']);
        }

        return [
            'effective_rules' => $effective,
            'sources' => [
                'meta_count' => count($metaDirectives),
                'header_count' => count($headerDirectives),
            ],
        ];
    }

    private static function applyDirectives(array &$effective, array $directives): void
    {
        foreach ($directives as $directive) {
            $name = $directive['name'];

            switch ($name) {
                case 'index':
                case 'all':
                    $effective['index'] = true;

                    break;

                case 'noindex':
                case 'none':
                    $effective['index'] = false;

                    break;

                case 'follow':
                    $effective['follow'] = true;

                    break;

                case 'nofollow':
                    $effective['follow'] = false;

                    break;

                case 'max-snippet':
                    $value = (int)$directive['value'];
                    $effective['max_snippet'] = $value;

                    break;

                case 'nosnippet':
                    $effective['max_snippet'] = 0;

                    break;

                case 'max-image-preview':
                    $validValues = ['none', 'standard', 'large'];
                    if (in_array($directive['value'], $validValues)) {
                        $effective['max_image_preview'] = $directive['value'];
                    }

                    break;

                case 'max-video-preview':
                    $value = (int)$directive['value'];
                    $effective['max_video_preview'] = $value;

                    break;

                case 'noarchive':
                    $effective['archive'] = false;

                    break;

                case 'archive':
                    $effective['archive'] = true;

                    break;

                case 'notranslate':
                    $effective['translate'] = false;

                    break;

                case 'translate':
                    $effective['translate'] = true;

                    break;

                case 'noimageindex':
                    $effective['image_index'] = false;

                    break;
            }
        }
    }
}
