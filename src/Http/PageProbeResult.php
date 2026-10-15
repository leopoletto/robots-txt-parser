<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Leopoletto\RobotsTxtParser\Extraction\HeaderExtractor;
use Leopoletto\RobotsTxtParser\Record\HeaderDirective;
use Leopoletto\RobotsTxtParser\Record\MetaDirective;
use Leopoletto\RobotsTxtParser\Validation\DirectiveValidator;

/**
 * What one request for a page found: the values of its X-Robots-Tag headers
 * and the robots meta tags in its head, as they were sent.
 *
 * Only what came from the network is kept. The directives are validated when
 * records are built from it, so a result written out and read back later is
 * judged by the rules in force then, not by those in force when it was fetched.
 */
final readonly class PageProbeResult
{
    /**
     * @param list<string>                               $headers  X-Robots-Tag header values.
     * @param list<array{name: string, content: string}> $metaTags Robots meta tags, by name and content.
     */
    public function __construct(
        public string $url,
        public ?int $statusCode,
        public array $headers = [],
        public array $metaTags = [],
        public ?string $error = null,
        /** Whether reading stopped at the size limit before </head> arrived. */
        public bool $truncated = false,
    ) {
    }

    /**
     * Whether the page itself was actually read: it answered 2xx and its body
     * arrived without failing partway. Only then do its directives, or the
     * absence of any, say something about the page.
     */
    public function observed(): bool
    {
        return $this->error === null
            && $this->statusCode !== null
            && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * The page's directives as records a document can carry.
     *
     * @return list<HeaderDirective|MetaDirective>
     */
    public function records(DirectiveValidator $validator = new DirectiveValidator()): array
    {
        $records = (new HeaderExtractor($validator))->extract($this->headers, origin: 'page');

        foreach ($this->metaTags as $tag) {
            $records[] = new MetaDirective(
                name: $tag['name'],
                raw: $tag['content'],
                validation: $validator->validate($tag['content']),
            );
        }

        return $records;
    }

    /**
     * @return array{url: string, statusCode: int|null, headers: list<string>, metaTags: list<array{name: string, content: string}>, error: string|null, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'statusCode' => $this->statusCode,
            'headers' => $this->headers,
            'metaTags' => $this->metaTags,
            'error' => $this->error,
            'truncated' => $this->truncated,
        ];
    }

    /**
     * @param array<string, mixed> $data The output of toArray(). Fields added since a
     *                                   result was stored take their defaults, so a stored
     *                                   clean 2xx reads back as observed and anything else not.
     */
    public static function fromArray(array $data): self
    {
        $headers = [];
        foreach ((array) ($data['headers'] ?? []) as $header) {
            $headers[] = (string) $header;
        }

        $metaTags = [];
        foreach ((array) ($data['metaTags'] ?? []) as $tag) {
            if (is_array($tag)) {
                $metaTags[] = ['name' => (string) ($tag['name'] ?? ''), 'content' => (string) ($tag['content'] ?? '')];
            }
        }

        return new self(
            url: (string) ($data['url'] ?? ''),
            statusCode: isset($data['statusCode']) ? (int) $data['statusCode'] : null,
            headers: $headers,
            metaTags: $metaTags,
            error: isset($data['error']) ? (string) $data['error'] : null,
            truncated: (bool) ($data['truncated'] ?? false),
        );
    }
}
