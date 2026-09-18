<?php

namespace App\Services\Scraper;

use App\Models\ScraperRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where a run's artifacts live.
 *
 * Everything sits under a private disk prefix keyed by run UUID, so one run can
 * never read another's files and nothing is reachable from a public web root.
 * Authentication state is deliberately NOT stored here — it belongs encrypted
 * in the database, not on disk beside downloadable artifacts.
 */
class RunPaths
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('scraper.disk', 'local'));
    }

    /** Relative path on the configured disk. */
    public function relativeDirectory(ScraperRun $run): string
    {
        return trim((string) config('scraper.storage_path', 'scraper'), '/')
            .'/runs/'.$run->uuid;
    }

    /** Absolute filesystem path the Node worker writes into. */
    public function absoluteDirectory(ScraperRun $run): string
    {
        return $this->disk()->path($this->relativeDirectory($run));
    }

    public function ensureDirectory(ScraperRun $run): string
    {
        $this->disk()->makeDirectory($this->relativeDirectory($run));

        return $this->absoluteDirectory($run);
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->disk()->path($relativePath);
    }

    public function delete(ScraperRun $run): void
    {
        $this->disk()->deleteDirectory($this->relativeDirectory($run));
    }

    /**
     * Strip anything that could escape the run directory or confuse a shell.
     *
     * SCDB supplies the suggested download filename, so it is untrusted input.
     */
    public function sanitizeFilename(string $filename, string $fallback = 'report.csv'): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        $name = trim($name, '._-');

        if ($name === '' || $name === '.' || $name === '..') {
            return $fallback;
        }

        return mb_substr($name, 0, 150);
    }
}
