<?php

namespace Leopoletto\RobotsTxtParser;

use Illuminate\Support\Collection;

class Response
{
    /**
     * @param Collection $records
     * @param int $size
     */
    public function __construct(
        private readonly Collection $records,
        private readonly int $size
    ) {}

    /**
     * Get the size of the parsed content in bytes
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Get all records as a Laravel Collection
     * 
     * @return Collection
     */
    public function records(): Collection
    {
        return $this->records;
    }

    /**
     * Get all comment records as a Laravel Collection
     * 
     * @return Collection
     */
    public function comments(): Collection
    {
        return $this->records->filter(function ($record) {
            return $record instanceof \Leopoletto\RobotsTxtParser\Records\Comment;
        });
    }
}

