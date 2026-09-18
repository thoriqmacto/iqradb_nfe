<?php

namespace Tests\Feature\Scraper;

use App\Enums\ScraperRunMode;
use App\Enums\ScraperRunStatus;
use App\Jobs\RunScraperRecipe;
use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ScraperRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_run_queues_it_and_returns_202(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'import']);

        // 202, not 200: the browser work has not happened yet and the request
        // must not be held open while Chromium works.
        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.mode', 'import');

        Queue::assertPushed(RunScraperRecipe::class);
        $this->assertDatabaseCount('scraper_runs', 1);
    }

    public function test_the_run_is_dispatched_to_the_dedicated_scraper_queue(): void
    {
        Queue::fake();
        config()->set('scraper.queue', 'scraper');

        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'download']);

        Queue::assertPushed(
            RunScraperRecipe::class,
            fn (RunScraperRecipe $job): bool => $job->queue === 'scraper',
        );
    }

    public function test_it_rejects_an_unknown_run_mode(): void
    {
        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'rm -rf /'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_it_refuses_to_run_a_disabled_recipe(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id, 'enabled' => false]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'download'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_it_refuses_a_second_concurrent_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id]);
        ScraperRun::factory()->create([
            'user_id' => $user->id,
            'scraper_recipe_id' => $recipe->id,
            'status' => ScraperRunStatus::Navigating,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'download'])
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_a_finished_run_does_not_block_a_new_one(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $recipe = ScraperRecipe::factory()->create(['user_id' => $user->id]);
        ScraperRun::factory()->create([
            'user_id' => $user->id,
            'scraper_recipe_id' => $recipe->id,
            'status' => ScraperRunStatus::Completed,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'download'])
            ->assertStatus(202);
    }

    public function test_a_run_can_be_polled_for_status(): void
    {
        $user = User::factory()->create();
        $run = ScraperRun::factory()->create([
            'user_id' => $user->id,
            'status' => ScraperRunStatus::Downloading,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/scrapper/runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'downloading')
            ->assertJsonPath('data.status_label', 'Downloading')
            ->assertJsonPath('data.is_terminal', false);
    }

    /* ------------------------------------------------------------------ *
     * State machine
     * ------------------------------------------------------------------ */

    public function test_it_follows_the_happy_path_transitions(): void
    {
        $run = ScraperRun::factory()->create(['status' => ScraperRunStatus::Queued]);

        foreach ([
            ScraperRunStatus::StartingBrowser,
            ScraperRunStatus::ValidatingSession,
            ScraperRunStatus::Navigating,
            ScraperRunStatus::WaitingForReport,
            ScraperRunStatus::Downloading,
            ScraperRunStatus::Downloaded,
            ScraperRunStatus::Parsing,
            ScraperRunStatus::Validating,
            ScraperRunStatus::Staged,
            ScraperRunStatus::Importing,
            ScraperRunStatus::Completed,
        ] as $status) {
            $run->transitionTo($status);
        }

        $this->assertSame(ScraperRunStatus::Completed, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_it_refuses_to_skip_the_pipeline(): void
    {
        $run = ScraperRun::factory()->create(['status' => ScraperRunStatus::Queued]);

        $this->expectException(RuntimeException::class);
        $run->transitionTo(ScraperRunStatus::Completed);
    }

    public function test_it_refuses_to_move_out_of_a_terminal_state(): void
    {
        $run = ScraperRun::factory()->create(['status' => ScraperRunStatus::Failed]);

        $this->expectException(RuntimeException::class);
        $run->transitionTo(ScraperRunStatus::Navigating);
    }

    public function test_any_running_state_may_abort(): void
    {
        foreach ([
            ScraperRunStatus::Navigating,
            ScraperRunStatus::Downloading,
            ScraperRunStatus::Parsing,
        ] as $from) {
            foreach ([
                ScraperRunStatus::Failed,
                ScraperRunStatus::SessionExpired,
                ScraperRunStatus::Cancelled,
            ] as $to) {
                $this->assertTrue(
                    $from->canTransitionTo($to),
                    "{$from->value} should be able to abort to {$to->value}",
                );
            }
        }
    }

    public function test_session_expired_is_distinct_from_a_generic_failure(): void
    {
        $run = ScraperRun::factory()->create(['status' => ScraperRunStatus::ValidatingSession]);

        $run->transitionTo(ScraperRunStatus::SessionExpired, [
            'error_code' => 'session_expired',
            'error_message' => 'SCDB redirected to the login page.',
        ]);

        $run = $run->fresh();
        $this->assertSame(ScraperRunStatus::SessionExpired, $run->status);
        $this->assertTrue($run->status->isFailure());
        $this->assertTrue($run->status->isTerminal());
        $this->assertSame('Session expired', $run->status->label());
    }

    public function test_a_download_only_run_may_complete_without_importing(): void
    {
        $this->assertTrue(ScraperRunStatus::Downloaded->canTransitionTo(ScraperRunStatus::Completed));
        $this->assertFalse(ScraperRunMode::Download->importsCsv());
        $this->assertTrue(ScraperRunMode::Download->capturesDownload());
        $this->assertFalse(ScraperRunMode::TestNavigation->capturesDownload());
    }
}
