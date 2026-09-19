<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Enums\ScraperRunStatus;
use App\Models\ScraperRun;
use App\Services\Import\ReportImporter;
use App\Services\Import\ReportReadException;
use App\Services\Import\TabularReaderFactory;
use App\Services\Scraper\RunPaths;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Turns a downloaded report into staged rows and, when an adapter exists for
 * the dataset, an idempotent upsert.
 *
 * Split from RunScraperRecipe on purpose: browser automation and data import
 * fail for completely different reasons, and keeping them separate means a
 * parsing bug never costs a fresh SCDB round trip to retry.
 */
class ProcessScraperReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $runUuid,
        public readonly bool $force = false,
    ) {
        $this->onQueue((string) config('scraper.queue', 'scraper'));
    }

    public function handle(ReportImporter $importer, RunPaths $paths): void
    {
        $run = ScraperRun::with('recipe')->where('uuid', $this->runUuid)->first();

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $recipe = $run->recipe;

        if ($recipe === null || $run->artifact_path === null) {
            $this->fail($run, 'no_artifact', 'There is no downloaded file to import for this run.');

            return;
        }

        $absolute = $paths->absolutePath($run->artifact_path);

        try {
            $run->transitionTo(ScraperRunStatus::Parsing);

            $this->assertUsableFile($absolute, $recipe->expected_file_type);

            $run->transitionTo(ScraperRunStatus::Validating);

            $batch = $importer->import(
                run: $run,
                recipe: $recipe,
                absolutePath: $absolute,
                checksum: (string) $run->checksum,
                force: $this->force,
            );

            $run->transitionTo(ScraperRunStatus::Staged);

            if ($batch->status === ImportBatchStatus::Failed) {
                $this->fail($run, 'import_failed', (string) ($batch->error_message ?? 'The import failed.'));

                return;
            }

            if ($batch->status === ImportBatchStatus::Completed) {
                $run->transitionTo(ScraperRunStatus::Importing);
            }

            $run->transitionTo(ScraperRunStatus::Completed);
            $recipe->forceFill(['last_success_run_at' => now()])->save();
        } catch (ReportReadException $e) {
            $this->fail($run, 'bad_report', $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($run, 'import_exception', mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /**
     * Guard the file before handing it to the parser: SCDB is a legacy app and
     * an error page saved as "report.csv" is a realistic failure mode.
     *
     * @throws ReportReadException
     */
    private function assertUsableFile(string $path, string $expectedExtension): void
    {
        if (! is_file($path)) {
            throw new ReportReadException('The downloaded file is missing from storage.');
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            throw new ReportReadException('The downloaded file is empty.');
        }

        $max = (int) config('scraper.max_download_bytes');

        if ($max > 0 && $size > $max) {
            throw new ReportReadException(sprintf(
                'The downloaded file is %d bytes, larger than the %d byte limit.',
                $size,
                $max
            ));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== strtolower($expectedExtension)) {
            throw new ReportReadException(sprintf(
                'Expected a .%s file but SCDB returned .%s.',
                $expectedExtension,
                $extension === '' ? '(none)' : $extension
            ));
        }

        if (! TabularReaderFactory::supports($extension)) {
            throw new ReportReadException(sprintf(
                'No reader for .%s files. Importable formats are: %s.',
                $extension === '' ? '(none)' : $extension,
                implode(', ', TabularReaderFactory::IMPORTABLE),
            ));
        }
    }

    private function fail(ScraperRun $run, string $code, string $message): void
    {
        if ($run->status->isTerminal()) {
            return;
        }

        $run->transitionTo(ScraperRunStatus::Failed, [
            'error_code' => $code,
            'error_message' => $message,
        ]);

        $run->recipe?->forceFill(['last_failed_run_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        $run = ScraperRun::where('uuid', $this->runUuid)->first();

        if ($run !== null && ! $run->status->isTerminal()) {
            $this->fail($run, 'job_failed', mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['scraper', 'import', 'run:'.$this->runUuid];
    }
}
