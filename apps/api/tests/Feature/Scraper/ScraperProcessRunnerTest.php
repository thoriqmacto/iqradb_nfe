<?php

namespace Tests\Feature\Scraper;

use App\Services\Scraper\ScraperProcessRunner;
use Tests\TestCase;

/**
 * Exercises the real PHP → Node boundary by spawning the actual worker.
 *
 * No browser and no SCDB credentials are involved: the commands used here fail
 * before Chromium would ever launch. What is under test is the transport — that
 * the payload reaches the worker over stdin and a JSON envelope comes back.
 *
 * These also pin a property worth keeping: the worker must produce a real
 * diagnosis (`blocked_url`, `invalid_recipe`) even when playwright-core is not
 * installed. The API CI job runs no `npm ci`, and a half-configured server is a
 * realistic state — neither should turn a rejection into a module-resolution
 * crash. `src/browser.mjs` loads playwright-core lazily for exactly this reason.
 */
class ScraperProcessRunnerTest extends TestCase
{
    private function workerIsAvailable(): bool
    {
        $script = rtrim((string) config('scraper.app_path'), '/').'/bin/scraper.mjs';

        return is_file($script);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scraper.app_path', dirname(base_path()).'/scraper');

        if (! $this->workerIsAvailable()) {
            $this->markTestSkipped('apps/scraper is not present in this checkout.');
        }
    }

    public function test_it_round_trips_a_command_over_stdin(): void
    {
        $result = app(ScraperProcessRunner::class)->run('unknown-command', [], timeoutSeconds: 30);

        $this->assertFalse($result->ok);
        $this->assertSame('unknown_command', $result->errorCode);
        $this->assertStringContainsString('unknown-command', (string) $result->errorMessage);
    }

    /**
     * The worker must refuse a host outside the allowlist without launching a
     * browser — this is the second, independent check behind ScdbUrlGuard.
     */
    public function test_the_worker_independently_rejects_a_disallowed_host(): void
    {
        $result = app(ScraperProcessRunner::class)->run('run-recipe', [
            'startUrl' => 'https://evil.test/',
            'actions' => [['type' => 'click', 'locator' => ['strategy' => 'css', 'css' => '#x']]],
            'allowedHosts' => ['chiyodanfe.ceccms.com'],
            'outputDirectory' => sys_get_temp_dir().'/scraper-test',
            'mode' => 'download',
        ], timeoutSeconds: 30);

        $this->assertFalse($result->ok);
        $this->assertSame('blocked_url', $result->errorCode);
        $this->assertStringContainsString('evil.test', (string) $result->errorMessage);
    }

    public function test_the_worker_rejects_an_unsupported_action_type(): void
    {
        $result = app(ScraperProcessRunner::class)->run('run-recipe', [
            'startUrl' => 'https://chiyodanfe.ceccms.com/',
            'actions' => [['type' => 'evaluate', 'script' => 'alert(1)']],
            'allowedHosts' => ['chiyodanfe.ceccms.com'],
            'outputDirectory' => sys_get_temp_dir().'/scraper-test',
            'mode' => 'download',
        ], timeoutSeconds: 30);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_recipe', $result->errorCode);
    }

    public function test_a_missing_worker_is_reported_rather_than_thrown(): void
    {
        config()->set('scraper.app_path', '/nonexistent/scraper');

        $result = app(ScraperProcessRunner::class)->run('validate-session', []);

        $this->assertFalse($result->ok);
        $this->assertSame('worker_missing', $result->errorCode);
    }
}
