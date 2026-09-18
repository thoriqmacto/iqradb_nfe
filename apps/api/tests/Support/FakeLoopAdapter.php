<?php

namespace Tests\Support;

use App\Models\ImportBatch;
use App\Services\Import\ImportCounts;
use App\Services\Import\ScdbImportAdapter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A stand-in target adapter for tests.
 *
 * The real Loop Index / SAT / Package / Milestone models do not exist yet, so
 * inventing one in app/ would mean shipping a guessed domain schema. This fake
 * writes to a table the test creates itself, which is enough to prove the
 * contract the real adapters will implement: idempotent upsert, accurate
 * counts, and rollback on failure.
 */
class FakeLoopAdapter implements ScdbImportAdapter
{
    public const TABLE = 'fake_loops';

    /** Set to a row key to make upsertRows blow up mid-chunk. */
    public ?string $explodeOnKey = null;

    public function datasetKey(): string
    {
        return 'loop_index';
    }

    public function requiredHeaders(): array
    {
        return ['Loop No', 'Train'];
    }

    public function uniqueKey(array $row): ?string
    {
        $loop = trim($row['Loop No'] ?? '');

        return $loop === '' ? null : $loop;
    }

    public function normalizeRow(array $row): array
    {
        return [
            'loop_no' => trim($row['Loop No'] ?? ''),
            'train' => trim($row['Train'] ?? ''),
            'status' => strtolower(trim($row['Status'] ?? '')),
        ];
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (($row['loop_no'] ?? '') === '') {
            $errors[] = 'Loop No is required.';
        }

        if (! in_array($row['train'] ?? '', ['Train-8', 'Train-9', 'Train-10', 'Train-11'], true)) {
            $errors[] = 'Train must be one of Train-8..Train-11.';
        }

        return $errors;
    }

    public function upsertRows(ImportBatch $batch, array $rows): ImportCounts
    {
        $counts = new ImportCounts;

        foreach ($rows as $row) {
            if ($this->explodeOnKey !== null && $row['key'] === $this->explodeOnKey) {
                throw new RuntimeException('Simulated adapter failure.');
            }

            $existing = DB::table(self::TABLE)->where('loop_no', $row['key'])->first();
            $data = $row['data'];

            if ($existing === null) {
                DB::table(self::TABLE)->insert([
                    'loop_no' => $data['loop_no'],
                    'train' => $data['train'],
                    'status' => $data['status'],
                ]);
                $counts->inserted++;

                continue;
            }

            if ($existing->train === $data['train'] && $existing->status === $data['status']) {
                $counts->unchanged++;

                continue;
            }

            DB::table(self::TABLE)->where('loop_no', $row['key'])->update([
                'train' => $data['train'],
                'status' => $data['status'],
            ]);
            $counts->updated++;
        }

        return $counts;
    }
}
