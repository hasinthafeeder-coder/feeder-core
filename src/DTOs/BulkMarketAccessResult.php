<?php

namespace Feeder\Core\DTOs;

class BulkMarketAccessResult
{
    /**
     * @param  list<string>  $skipReasons
     */
    public function __construct(
        public int $selected,
        public int $changed,
        public int $skipped,
        public int $failed,
        public array $skipReasons = [],
    ) {}

    public function summaryMessage(): string
    {
        return sprintf(
            'Selected: %d. Successfully changed: %d. Skipped: %d. Failed: %d.',
            $this->selected,
            $this->changed,
            $this->skipped,
            $this->failed
        );
    }
}
