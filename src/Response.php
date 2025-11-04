<?php

namespace Leopoletto\RobotsTxtParser;

use Illuminate\Support\Collection;
use Leopoletto\RobotsTxtParser\Records\RobotsCustomCollection;

class Response
{
    /**
     * @param Collection $records
     * @param int $size
     */
    public function __construct(
        private readonly RobotsCustomCollection $records,
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
     * Get all records as a RobotsCustomCollection
     * 
     * @return RobotsCustomCollection
     */
    public function records(): RobotsCustomCollection
    {
        return $this->records;
    }
}

