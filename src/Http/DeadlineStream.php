<?php

declare(strict_types=1);

namespace Leopoletto\RobotsTxtParser\Http;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body that refuses to be read past a deadline.
 *
 * With a streamed response, Guzzle's timeout bounds each read, not the whole
 * request: a server that sends a byte every few seconds never trips it. This
 * checks the clock before every read and throws once the request has run out
 * of time, so a body that trickles is cut off rather than awaited.
 *
 * The check runs before a read, not during it, so one read already waiting
 * when the deadline passes can still take up to the transport's own timeout.
 */
final class DeadlineStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * @param float                $deadline When reading must stop, on the clock's scale.
     * @param float                $seconds  The time allowed, for the error message.
     * @param Closure(): float     $clock    The current time in seconds.
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly float $deadline,
        private readonly float $seconds,
        private readonly Closure $clock,
    ) {
    }

    public function read($length): string
    {
        if (($this->clock)() >= $this->deadline) {
            throw new RuntimeException(sprintf('Timed out after %g seconds while reading the response', $this->seconds));
        }

        return $this->stream->read($length);
    }
}
