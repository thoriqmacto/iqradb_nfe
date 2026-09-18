<?php

namespace App\Services\Import;

/** Result tally from an adapter's upsert pass. */
class ImportCounts
{
    public function __construct(
        public int $inserted = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $rejected = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            $this->inserted + $other->inserted,
            $this->updated + $other->updated,
            $this->unchanged + $other->unchanged,
            $this->rejected + $other->rejected,
        );
    }

    /** @return array{inserted: int, updated: int, unchanged: int, rejected: int} */
    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'rejected' => $this->rejected,
        ];
    }
}
