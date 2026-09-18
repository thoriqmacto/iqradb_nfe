<?php

namespace App\Http\Controllers\Api\V1\Scraper;

use App\Enums\ScraperRunMode;
use App\Enums\ScraperRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScraperRunResource;
use App\Jobs\RunScraperRecipe;
use App\Models\ImportRow;
use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use App\Services\Import\CsvReader;
use App\Services\Scraper\RunPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RunController extends Controller
{
    /** Rows shown in the CSV preview. Enough to eyeball a mapping, not enough to ship a whole report over JSON. */
    private const PREVIEW_ROWS = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = ScraperRun::with(['recipe', 'importBatch'])
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(25);

        return ScraperRunResource::collection($runs);
    }

    public function show(Request $request, ScraperRun $run): ScraperRunResource
    {
        $this->authorize('view', $run);

        return new ScraperRunResource($run->load(['recipe', 'importBatch']));
    }

    /**
     * Queue a run and return immediately.
     *
     * 202 with a run id, never a held-open connection: a Chromium round trip
     * against a legacy ASP.NET app can take minutes, and the frontend polls
     * the run resource instead.
     */
    public function store(Request $request, ScraperRecipe $recipe): JsonResponse
    {
        $this->authorize('run', $recipe);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:test_navigation,download,import'],
        ]);

        if (! $recipe->enabled) {
            return response()->json([
                'message' => 'This recipe is disabled. Enable it before running.',
            ], 422);
        }

        // One in-flight run per user: the browser lock would serialise them
        // anyway, and a queue of stale runs helps nobody.
        $active = ScraperRun::where('user_id', $request->user()->id)
            ->whereIn('status', $this->activeStatuses())
            ->exists();

        if ($active) {
            return response()->json([
                'message' => 'A scraper run is already in progress. Wait for it to finish.',
            ], 409);
        }

        $run = new ScraperRun([
            'mode' => ScraperRunMode::from($validated['mode']),
            'status' => ScraperRunStatus::Queued,
        ]);

        $run->user_id = $request->user()->id;
        $run->scraper_recipe_id = $recipe->id;
        $run->save();

        RunScraperRecipe::dispatch($run->uuid);

        return (new ScraperRunResource($run->load('recipe')))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Preview the downloaded CSV: detected headers plus the first rows.
     *
     * Reads staged rows when they exist (they are already parsed and carry
     * validation results), and falls back to reading the file directly for a
     * download-only run that was never imported.
     */
    public function preview(Request $request, ScraperRun $run, CsvReader $reader, RunPaths $paths): JsonResponse
    {
        $this->authorize('view', $run);

        $batch = $run->importBatch()->first();

        if ($batch !== null) {
            $rows = ImportRow::where('import_batch_id', $batch->id)
                ->orderBy('row_number')
                ->limit(self::PREVIEW_ROWS)
                ->get(['row_number', 'status', 'unique_key', 'payload', 'errors']);

            return response()->json([
                'source' => 'staging',
                'headers' => $batch->headers,
                'total_rows' => $batch->total_rows,
                'valid_rows' => $batch->valid_rows,
                'invalid_rows' => $batch->invalid_rows,
                'status' => $batch->status->value,
                'rows' => $rows->map(static fn (ImportRow $row): array => [
                    'row_number' => $row->row_number,
                    'status' => $row->status,
                    'unique_key' => $row->unique_key,
                    'values' => $row->payload,
                    'errors' => $row->errors ?? [],
                ])->all(),
            ]);
        }

        if ($run->artifact_path === null) {
            return response()->json(['message' => 'This run has no downloaded file.'], 404);
        }

        $absolute = $paths->absolutePath($run->artifact_path);

        if (! is_file($absolute)) {
            return response()->json(['message' => 'The downloaded file is no longer in storage.'], 404);
        }

        $headers = $reader->headers($absolute);
        $rows = [];

        foreach ($reader->rows($absolute) as [$number, $values, $errors]) {
            $rows[] = [
                'row_number' => $number,
                'status' => $errors === [] ? 'valid' : 'invalid',
                'unique_key' => null,
                'values' => $values,
                'errors' => $errors,
            ];

            if (count($rows) >= self::PREVIEW_ROWS) {
                break;
            }
        }

        return response()->json([
            'source' => 'file',
            'headers' => $headers,
            'total_rows' => null,
            'valid_rows' => null,
            'invalid_rows' => null,
            'status' => null,
            'rows' => $rows,
        ]);
    }

    /** @return list<string> */
    private function activeStatuses(): array
    {
        return array_values(array_map(
            static fn (ScraperRunStatus $status): string => $status->value,
            array_filter(
                ScraperRunStatus::cases(),
                static fn (ScraperRunStatus $status): bool => ! $status->isTerminal(),
            )
        ));
    }
}
