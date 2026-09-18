<?php

namespace App\Console\Commands;

use App\Models\ScraperRun;
use App\Services\Scraper\RunPaths;
use Illuminate\Console\Command;

/**
 * Artifact retention.
 *
 * Downloaded CSVs, failure screenshots and Playwright traces accumulate fast —
 * a daily report scraped for four trains is a lot of files by the end of a
 * project. This deletes the files for runs older than the configured window
 * while keeping the run rows themselves, so history and import counts survive.
 */
class PruneScraperArtifactsCommand extends Command
{
    protected $signature = 'scraper:prune {--days= : Override SCRAPER_ARTIFACT_RETENTION_DAYS} {--dry-run}';

    protected $description = 'Delete scraper run artifacts older than the retention window.';

    public function handle(RunPaths $paths): int
    {
        $days = (int) ($this->option('days') ?? config('scraper.artifact_retention_days', 14));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $pruned = 0;

        ScraperRun::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('artifact_path')
                    ->orWhereNotNull('screenshot_path')
                    ->orWhereNotNull('trace_path');
            })
            ->chunkById(100, function ($runs) use ($paths, $dryRun, &$pruned): void {
                foreach ($runs as $run) {
                    $this->line(($dryRun ? '[dry-run] ' : '').'Pruning artifacts for run '.$run->uuid);

                    if (! $dryRun) {
                        $paths->delete($run);

                        $run->forceFill([
                            'artifact_path' => null,
                            'screenshot_path' => null,
                            'trace_path' => null,
                        ])->save();
                    }

                    $pruned++;
                }
            });

        $this->info(sprintf(
            '%s %d run(s) older than %d day(s).',
            $dryRun ? 'Would prune' : 'Pruned',
            $pruned,
            $days
        ));

        return self::SUCCESS;
    }
}
