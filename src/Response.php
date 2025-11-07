<?php

namespace Leopoletto\RobotsTxtParser;

use Leopoletto\RobotsTxtParser\Collection\RobotsCollection;

class Response
{
    /**
     * @param RobotsCollection $records
     * @param int $size
     */
    public function __construct(
        private readonly RobotsCollection $records,
        private readonly int $size
    ) {
    }

    /**
     * Get the size of the parsed content in bytes
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Get all records as a RobotsCollection
     *
     * @return RobotsCollection
     */
    public function records(): RobotsCollection
    {
        return $this->records;
    }
}
