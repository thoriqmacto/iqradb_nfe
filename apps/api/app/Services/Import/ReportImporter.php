<?php

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Downloaded report → staged rows → (optional) transactional upsert.
 *
 * The pipeline always stages. Staging is what makes an import auditable and
 * replayable, and it is the honest stopping point for a dataset that has no
 * target model yet.
 */
class ReportImporter
{
    /** Rows per upsert transaction chunk. */
    private const CHUNK = 500;

    public function __construct(
        private readonly TabularReaderFactory $readers,
        private readonly ImportAdapterRegistry $adapters,
    ) {}

    /**
     * Has this exact file already been imported successfully for this dataset?
     *
     * Re-downloading an unchanged report is normal; re-importing it is usually
     * not what the user wants, so the caller can skip unless asked to force.
     */
    public function findPreviousSuccessfulBatch(int $userId, string $datasetKey, string $checksum): ?ImportBatch
    {
        return ImportBatch::query()
            ->where('user_id', $userId)
            ->where('dataset_key', $datasetKey)
            ->where('checksum', $checksum)
            ->whereIn('status', [ImportBatchStatus::Completed, ImportBatchStatus::ReadyForMapping])
            ->latest('id')
            ->first();
    }

    /**
     * Parse, stage, and import a downloaded report.
     *
     * @throws ReportReadException when the file is unusable
     */
    public function import(
        ScraperRun $run,
        ScraperRecipe $recipe,
        string $absolutePath,
        string $checksum,
        bool $force = false,
    ): ImportBatch {
        $datasetKey = $recipe->dataset_key;

        if (! $force) {
            $previous = $this->findPreviousSuccessfulBatch($run->user_id, $datasetKey, $checksum);

            if ($previous !== null) {
                return $this->createBatch($run, $recipe, $absolutePath, $checksum, [
                    'status' => ImportBatchStatus::Skipped,
                    'headers' => $previous->headers,
                    'error_message' => sprintf(
                        'Identical file already imported as batch %s on %s. Re-run with force to import again.',
                        $previous->uuid,
                        optional($previous->imported_at ?? $previous->created_at)->toDateTimeString(),
                    ),
                ]);
            }
        }

        $headers = $this->readers->for($absolutePath)->headers($absolutePath);
        $adapter = $this->adapters->get($datasetKey);

        $batch = $this->createBatch($run, $recipe, $absolutePath, $checksum, [
            'status' => ImportBatchStatus::Parsing,
            'headers' => $headers,
        ]);

        // A missing required column means the SCDB report changed shape. Fail
        // loudly — silently importing nulls is how bad data gets trusted.
        if ($adapter !== null) {
            $missing = array_values(array_diff($adapter->requiredHeaders(), $headers));

            if ($missing !== []) {
                $batch->forceFill([
                    'status' => ImportBatchStatus::Failed,
                    'error_message' => 'Required column(s) missing from the report: '.implode(', ', $missing).'.',
                ])->save();

                return $batch;
            }
        }

        $this->stageRows($batch, $absolutePath, $adapter);

        if ($adapter === null) {
            // No target model for this dataset yet — staged is as far as we go.
            $batch->forceFill([
                'status' => ImportBatchStatus::ReadyForMapping,
                'imported_at' => now(),
            ])->save();

            return $batch;
        }

        return $this->upsert($batch, $adapter);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBatch(
        ScraperRun $run,
        ScraperRecipe $recipe,
        string $path,
        string $checksum,
        array $attributes,
    ): ImportBatch {
        $batch = new ImportBatch([
            'dataset_key' => $recipe->dataset_key,
            'source_filename' => $run->downloaded_filename ?? basename($path),
            'checksum' => $checksum,
            'headers' => $attributes['headers'] ?? [],
            'mapping_version' => (int) data_get($recipe->import_config, 'mapping_version', 1),
        ]);

        $batch->user_id = $run->user_id;
        $batch->scraper_run_id = $run->id;
        $batch->scraper_recipe_id = $recipe->id;
        $batch->forceFill($attributes);
        $batch->save();

        return $batch;
    }

    /**
     * Stage every source row verbatim, with normalisation and validation
     * results alongside when an adapter is available.
     */
    private function stageRows(ImportBatch $batch, string $path, ?ScdbImportAdapter $adapter): void
    {
        $total = $valid = $invalid = 0;
        $buffer = [];
        $now = now();

        foreach ($this->readers->for($path)->rows($path) as [$rowNumber, $row, $rowErrors]) {
            $total++;

            $normalized = null;
            $uniqueKey = null;
            $errors = $rowErrors;

            if ($adapter !== null) {
                $normalized = $adapter->normalizeRow($row);
                $errors = [...$errors, ...$adapter->validateRow($normalized)];
                $uniqueKey = $adapter->uniqueKey($row);

                if ($uniqueKey === null || $uniqueKey === '') {
                    $errors[] = 'Row is missing the identifier this report is keyed on.';
                }
            }

            $isValid = $errors === [];
            $isValid ? $valid++ : $invalid++;

            $buffer[] = [
                'import_batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'status' => $isValid ? ImportRow::STATUS_VALID : ImportRow::STATUS_INVALID,
                'unique_key' => $uniqueKey,
                'payload' => json_encode($row, JSON_UNESCAPED_SLASHES),
                'normalized' => $normalized === null ? null : json_encode($normalized, JSON_UNESCAPED_SLASHES),
                'errors' => $errors === [] ? null : json_encode(array_values($errors), JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::CHUNK) {
                ImportRow::insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            ImportRow::insert($buffer);
        }

        $batch->forceFill([
            'status' => ImportBatchStatus::Staged,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'rejected' => $invalid,
        ])->save();
    }

    /**
     * Hand staged rows to the adapter in chunks, each in its own transaction.
     *
     * A failure rolls back the chunk in flight and marks the batch failed —
     * the staged rows survive, so the problem can be inspected and the import
     * retried without going back to SCDB.
     */
    private function upsert(ImportBatch $batch, ScdbImportAdapter $adapter): ImportBatch
    {
        $batch->forceFill(['status' => ImportBatchStatus::Importing])->save();

        $counts = new ImportCounts;

        try {
            $batch->rows()
                ->where('status', ImportRow::STATUS_VALID)
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($rows) use ($adapter, $batch, &$counts): void {
                    $payload = $rows->map(static fn (ImportRow $row): array => [
                        'key' => (string) $row->unique_key,
                        'data' => $row->normalized ?? $row->payload,
                    ])->values()->all();

                    DB::transaction(function () use ($adapter, $batch, $payload, $rows, &$counts): void {
                        $counts = $counts->add($adapter->upsertRows($batch, $payload));

                        ImportRow::whereIn('id', $rows->pluck('id'))
                            ->update(['status' => ImportRow::STATUS_IMPORTED]);
                    });
                });
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => ImportBatchStatus::Failed,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ...$counts->toArray(),
            ])->save();

            return $batch;
        }

        $batch->forceFill([
            'status' => ImportBatchStatus::Completed,
            'imported_at' => now(),
            'inserted' => $counts->inserted,
            'updated' => $counts->updated,
            'unchanged' => $counts->unchanged,
            // Rows rejected at validation time plus any the adapter rejected.
            'rejected' => $batch->invalid_rows + $counts->rejected,
        ])->save();

        return $batch;
    }
}
