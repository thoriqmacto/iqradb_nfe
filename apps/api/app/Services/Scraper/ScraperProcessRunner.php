<?php

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Bridges Laravel to the Node/Playwright worker.
 *
 * Two rules shape this class:
 *
 *  1. The command line is a fixed array — `[node, script]`. No user input is
 *     ever concatenated into a shell string, and `Process` is constructed from
 *     an array so no shell is involved at all.
 *
 *  2. The payload (recipe, paths, and crucially the SCDB storage state) goes in
 *     over STDIN. Command-line arguments are world-readable via `ps`, so
 *     cookies must never travel that way.
 *
 * The worker answers with a single JSON envelope on stdout. Diagnostics go to
 * stderr, which we capture for logging but never trust as structured data.
 */
class ScraperProcessRunner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(string $command, array $payload, ?int $timeoutSeconds = null): ScraperProcessResult
    {
        $node = (string) config('scraper.node_binary', 'node');
        $appPath = (string) config('scraper.app_path');
        $script = rtrim($appPath, '/').'/bin/scraper.mjs';

        if (! is_file($script)) {
            return new ScraperProcessResult(
                ok: false,
                status: 'error',
                errorCode: 'worker_missing',
                errorMessage: "Scraper worker not found at {$script}. Check SCRAPER_APP_PATH.",
            );
        }

        $timeout = $timeoutSeconds ?? (int) config('scraper.timeout_seconds', 300);

        $input = json_encode(
            ['command' => $command, ...$payload],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $process = new Process(
            command: [$node, $script],
            cwd: $appPath,
            env: $this->childEnvironment(),
            input: $input,
            timeout: $timeout,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ScraperProcessResult(
                ok: false,
                status: 'error',
                errorCode: 'timeout',
                errorMessage: "Scraper timed out after {$timeout}s.",
                exitCode: -1,
            );
        } catch (RuntimeException $e) {
            return new ScraperProcessResult(
                ok: false,
                status: 'error',
                errorCode: 'spawn_failed',
                errorMessage: $this->sanitize($e->getMessage()),
                exitCode: -1,
            );
        }

        $stderr = trim($process->getErrorOutput());

        if ($stderr !== '') {
            // The worker is written never to print secrets, but this is the one
            // place raw child output reaches our logs, so truncate and sanitize.
            Log::debug('scraper.worker.stderr', ['output' => $this->sanitize(mb_substr($stderr, 0, 4000))]);
        }

        $envelope = $this->decodeEnvelope($process->getOutput());

        if ($envelope === null) {
            return new ScraperProcessResult(
                ok: false,
                status: 'error',
                errorCode: 'bad_envelope',
                errorMessage: 'Scraper worker did not return a readable result.',
                exitCode: $process->getExitCode() ?? -1,
            );
        }

        return new ScraperProcessResult(
            ok: (bool) ($envelope['ok'] ?? false),
            status: (string) ($envelope['status'] ?? 'error'),
            data: is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            errorCode: $envelope['errorCode'] ?? null,
            errorMessage: isset($envelope['errorMessage'])
                ? $this->sanitize((string) $envelope['errorMessage'])
                : null,
            failedStepIndex: isset($envelope['failedStepIndex']) ? (int) $envelope['failedStepIndex'] : null,
            failedAction: is_array($envelope['failedAction'] ?? null) ? $envelope['failedAction'] : null,
            finalUrl: isset($envelope['finalUrl']) ? (string) $envelope['finalUrl'] : null,
            exitCode: $process->getExitCode() ?? 0,
        );
    }

    /**
     * A deliberately small environment. The child needs a PATH, a HOME for
     * Playwright's cache, and the browser location — nothing else from the
     * web process's environment (which holds APP_KEY and database credentials)
     * should cross the boundary.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            'NODE_ENV' => 'production',
        ];

        // Config first, real process environment second.
        //
        // getenv() alone is not enough: `php artisan config:cache` stops
        // Laravel loading .env at all, so a PLAYWRIGHT_BROWSERS_PATH set there
        // would vanish in production and the worker would report "Executable
        // doesn't exist" for a browser that is installed. Config survives
        // caching; the getenv() fallback still honours a variable exported by
        // systemd or the php-fpm pool.
        $paths = [
            'PLAYWRIGHT_BROWSERS_PATH' => config('scraper.browsers_path'),
            'PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH' => config('scraper.chromium_executable'),
        ];

        foreach ($paths as $key => $configured) {
            $value = is_string($configured) && $configured !== ''
                ? $configured
                : getenv($key);

            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * The worker prints exactly one JSON envelope, but be forgiving: take the
     * last line that parses as a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(string $stdout): ?array
    {
        $stdout = trim($stdout);

        if ($stdout === '') {
            return null;
        }

        $decoded = json_decode($stdout, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = array_reverse(preg_split('/\R/', $stdout) ?: []);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Last-resort redaction for anything that reaches a log or a stored error.
     * The worker already avoids printing secrets; this catches mistakes.
     */
    private function sanitize(string $message): string
    {
        $patterns = [
            '/("?(?:cookie|cookies|storageState|storage_state|token|password|authorization)"?\s*[:=]\s*)("(?:[^"\\\\]|\\\\.)*"|\S+)/i' => '$1"[redacted]"',
            '/\b[A-Za-z0-9_-]{24,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => '[redacted-token]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? $message;
        }

        return mb_substr($message, 0, 2000);
    }
}
