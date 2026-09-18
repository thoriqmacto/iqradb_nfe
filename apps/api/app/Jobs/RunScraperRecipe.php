<?php

namespace App\Jobs;

use App\Enums\ScraperRunStatus;
use App\Models\ScraperRun;
use App\Models\ScraperSession;
use App\Services\Scraper\RunPaths;
use App\Services\Scraper\ScdbUrlGuard;
use App\Services\Scraper\ScraperProcessRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one recipe through Chromium on the VPS.
 *
 * Runs on the dedicated `scraper` queue and holds a per-user lock for its
 * duration: one SCDB session driven by two concurrent browser contexts is not
 * known to be safe, so overlapping runs wait rather than race.
 */
class RunScraperRecipe implements ShouldQueue
{
    use Queueable;

    /** Browser automation against a legacy app is flaky-by-nature; retrying blind wastes a session. */
    public int $tries = 1;

    public function __construct(public readonly string $runUuid)
    {
        $this->onQueue((string) config('scraper.queue', 'scraper'));
    }

    public function handle(
        ScraperProcessRunner $processRunner,
        RunPaths $paths,
        ScdbUrlGuard $urls,
    ): void {
        $run = ScraperRun::with('recipe')->where('uuid', $this->runUuid)->first();

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $lock = Cache::lock(
            'scraper:user:'.$run->user_id,
            (int) config('scraper.lock_seconds', 600)
        );

        // Wait rather than fail outright: the user queued this deliberately.
        try {
            $lock->block(30, fn () => $this->execute($run, $processRunner, $paths, $urls));
        } catch (LockTimeoutException) {
            $this->finishWithError(
                $run,
                'busy',
                'Another scraper run is already using this SCDB session. Try again when it finishes.'
            );
        }
    }

    private function execute(
        ScraperRun $run,
        ScraperProcessRunner $processRunner,
        RunPaths $paths,
        ScdbUrlGuard $urls,
    ): void {
        $recipe = $run->recipe;

        if ($recipe === null) {
            $this->finishWithError($run, 'recipe_missing', 'The recipe for this run no longer exists.');

            return;
        }

        $session = ScraperSession::where('user_id', $run->user_id)
            ->where('host', parse_url((string) config('scraper.base_url'), PHP_URL_HOST))
            ->first();

        if ($session === null) {
            $this->finishWithError(
                $run,
                'no_session',
                'No SCDB authentication state is configured. Upload one on the Scrapper page first.',
                ScraperRunStatus::SessionExpired,
            );

            return;
        }

        try {
            $run->transitionTo(ScraperRunStatus::StartingBrowser, ['started_at' => now()]);

            // Re-validate the start URL at launch time: the allowlist may have
            // been tightened since the recipe was saved.
            $startUrl = $urls->assertAllowed($recipe->start_url);

            $directory = $paths->ensureDirectory($run);

            $run->transitionTo(ScraperRunStatus::ValidatingSession);

            $result = $processRunner->run('run-recipe', [
                'runId' => $run->uuid,
                'startUrl' => $startUrl,
                'actions' => $recipe->actions,
                'mode' => $run->mode->value,
                'outputDirectory' => $directory,
                'storageState' => $session->storage_state,
                'allowedHosts' => $urls->allowedHosts(),
                'loginMarkers' => (array) config('scraper.login_markers'),
                'navigationTimeoutMs' => (int) config('scraper.navigation_timeout_ms'),
                'actionTimeoutMs' => (int) config('scraper.action_timeout_ms'),
                'maxDownloadBytes' => (int) config('scraper.max_download_bytes'),
                'expectedExtension' => $recipe->expected_file_type,
            ]);

            if ($result->status === 'session_expired') {
                $session->forceFill([
                    'status' => ScraperSession::STATUS_EXPIRED,
                    'last_validated_at' => now(),
                    'last_validation_error' => 'SCDB redirected to the login page during a run.',
                ])->save();

                $this->finishWithError(
                    $run,
                    'session_expired',
                    'SCDB redirected to the login page. Upload a fresh authentication state.',
                    ScraperRunStatus::SessionExpired,
                    $result->finalUrl,
                );

                return;
            }

            if (! $result->ok) {
                $this->finishWithError(
                    $run,
                    $result->errorCode ?? 'scraper_failed',
                    $result->errorMessage ?? 'The scraper run failed.',
                    ScraperRunStatus::Failed,
                    $result->finalUrl,
                    $result->failedStepIndex,
                    $result->failedAction,
                    $this->relativeArtifact($paths, $run, $result->get('screenshot')),
                    $this->relativeArtifact($paths, $run, $result->get('trace')),
                );

                return;
            }

            // The worker succeeded; the session is demonstrably good.
            $session->forceFill([
                'status' => ScraperSession::STATUS_VALID,
                'last_validated_at' => now(),
                'last_validation_error' => null,
            ])->save();

            $run->transitionTo(ScraperRunStatus::Navigating, ['final_url' => $result->finalUrl]);

            if (! $run->mode->capturesDownload()) {
                $run->transitionTo(ScraperRunStatus::Completed);
                $recipe->forceFill(['last_success_run_at' => now()])->save();

                return;
            }

            $this->recordDownload($run, $paths, $result->data);

            if (! $run->mode->importsCsv()) {
                $run->transitionTo(ScraperRunStatus::Completed);
                $recipe->forceFill(['last_success_run_at' => now()])->save();

                return;
            }

            ProcessScraperCsv::dispatch($run->uuid);
        } catch (Throwable $e) {
            Log::error('scraper.run.failed', [
                'run' => $run->uuid,
                'exception' => $e::class,
                // Message only — a trace on this path can carry the payload.
                'message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            $this->finishWithError($run, 'exception', mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordDownload(ScraperRun $run, RunPaths $paths, array $data): void
    {
        $run->transitionTo(ScraperRunStatus::WaitingForReport);
        $run->transitionTo(ScraperRunStatus::Downloading);

        $filename = $paths->sanitizeFilename((string) ($data['filename'] ?? 'report.csv'));
        $relative = $paths->relativeDirectory($run).'/'.$filename;

        $run->transitionTo(ScraperRunStatus::Downloaded, [
            'downloaded_filename' => $filename,
            'artifact_path' => $relative,
            'checksum' => $data['checksum'] ?? null,
            'file_size' => isset($data['size']) ? (int) $data['size'] : null,
        ]);
    }

    private function relativeArtifact(RunPaths $paths, ScraperRun $run, mixed $filename): ?string
    {
        if (! is_string($filename) || $filename === '') {
            return null;
        }

        return $paths->relativeDirectory($run).'/'.$paths->sanitizeFilename($filename, 'artifact');
    }

    /**
     * @param  array<string, mixed>|null  $failedAction
     */
    private function finishWithError(
        ScraperRun $run,
        string $code,
        string $message,
        ScraperRunStatus $status = ScraperRunStatus::Failed,
        ?string $finalUrl = null,
        ?int $failedStepIndex = null,
        ?array $failedAction = null,
        ?string $screenshot = null,
        ?string $trace = null,
    ): void {
        if ($run->status->isTerminal()) {
            return;
        }

        $run->transitionTo($status, array_filter([
            'error_code' => $code,
            'error_message' => $message,
            'failed_step_index' => $failedStepIndex,
            'failed_action' => $failedAction,
            'final_url' => $finalUrl,
            'screenshot_path' => $screenshot,
            'trace_path' => $trace,
        ], static fn ($v): bool => $v !== null));

        $run->recipe?->forceFill(['last_failed_run_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        $run = ScraperRun::where('uuid', $this->runUuid)->first();

        if ($run !== null && ! $run->status->isTerminal()) {
            $this->finishWithError($run, 'job_failed', mb_substr($e->getMessage(), 0, 1000));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['scraper', 'run:'.$this->runUuid];
    }
}
