<?php

namespace Feeder\Core\Services\Courier;

/**
 * Aggregate counters returned by CourierLocationSyncService.
 */
final class CourierLocationSyncResult
{
    public function __construct(
        public int $statesCreated = 0,
        public int $statesUpdated = 0,
        public int $citiesCreated = 0,
        public int $citiesUpdated = 0,
        public int $citiesSkipped = 0,
        public int $rowsSkipped = 0,
        public int $errors = 0,
        /** @var list<string> */
        public array $errorMessages = [],
    ) {
    }

    public function merge(self $other): void
    {
        $this->statesCreated += $other->statesCreated;
        $this->statesUpdated += $other->statesUpdated;
        $this->citiesCreated += $other->citiesCreated;
        $this->citiesUpdated += $other->citiesUpdated;
        $this->citiesSkipped += $other->citiesSkipped;
        $this->rowsSkipped += $other->rowsSkipped;
        $this->errors += $other->errors;
        $this->errorMessages = array_values(array_merge($this->errorMessages, $other->errorMessages));
    }

    /**
     * @return array{
     *     states_created: int,
     *     states_updated: int,
     *     cities_created: int,
     *     cities_updated: int,
     *     cities_skipped: int,
     *     rows_skipped: int,
     *     errors: int,
     *     error_messages: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'states_created' => $this->statesCreated,
            'states_updated' => $this->statesUpdated,
            'cities_created' => $this->citiesCreated,
            'cities_updated' => $this->citiesUpdated,
            'cities_skipped' => $this->citiesSkipped,
            'rows_skipped' => $this->rowsSkipped,
            'errors' => $this->errors,
            'error_messages' => $this->errorMessages,
        ];
    }
}
