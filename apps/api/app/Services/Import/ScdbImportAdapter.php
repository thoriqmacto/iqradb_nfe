<?php

namespace App\Services\Import;

use App\Models\ImportBatch;

/**
 * Maps one SCDB report into IqraDB.
 *
 * The split that matters: a *recipe* says WHAT report to fetch from SCDB; an
 * *adapter* says HOW those columns become IqraDB rows. Neither knows about the
 * other, so SCDB markup can change without touching import logic, and the
 * domain schema can change without re-recording a browser recipe.
 *
 * Datasets with no registered adapter are not an error — their batches settle
 * at `ready_for_mapping` with rows staged, which is the honest outcome until a
 * domain table actually exists.
 */
interface ScdbImportAdapter
{
    /** The `dataset_key` this adapter claims, e.g. "loop_index". */
    public function datasetKey(): string;

    /**
     * Headers that must be present for the file to be importable at all.
     * A missing required header fails the batch loudly instead of writing nulls.
     *
     * @return list<string>
     */
    public function requiredHeaders(): array;

    /**
     * The natural/external key identifying this row in SCDB, used to make
     * imports idempotent. Return null when the row cannot be identified.
     *
     * @param  array<string, string>  $row
     */
    public function uniqueKey(array $row): ?string;

    /**
     * Coerce raw CSV strings into typed values.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row): array;

    /**
     * Validate a normalised row.
     *
     * @param  array<string, mixed>  $row
     * @return list<string> validation errors; empty means valid
     */
    public function validateRow(array $row): array;

    /**
     * Upsert a chunk of normalised, valid rows.
     *
     * Called inside a database transaction owned by the importer, once per
     * chunk. Implementations must be idempotent: importing the same file twice
     * must report `unchanged`, not duplicate rows.
     *
     * @param  list<array{key: string, data: array<string, mixed>}>  $rows
     */
    public function upsertRows(ImportBatch $batch, array $rows): ImportCounts;
}
