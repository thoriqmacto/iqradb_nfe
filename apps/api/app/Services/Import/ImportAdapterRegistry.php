<?php

namespace App\Services\Import;

/**
 * Resolves the adapter for a dataset key, if one is registered.
 *
 * Nothing is registered today — Loop Index, SAT, Package and Milestone have no
 * domain tables yet, so inventing adapters for them would mean inventing a
 * schema. Register real ones here as those models land; the import pipeline
 * picks them up with no other change.
 */
class ImportAdapterRegistry
{
    /** @var array<string, ScdbImportAdapter> */
    private array $adapters = [];

    public function register(ScdbImportAdapter $adapter): void
    {
        $this->adapters[$adapter->datasetKey()] = $adapter;
    }

    public function has(string $datasetKey): bool
    {
        return isset($this->adapters[$datasetKey]);
    }

    public function get(string $datasetKey): ?ScdbImportAdapter
    {
        return $this->adapters[$datasetKey] ?? null;
    }

    /** @return list<string> */
    public function datasetKeys(): array
    {
        return array_keys($this->adapters);
    }
}
