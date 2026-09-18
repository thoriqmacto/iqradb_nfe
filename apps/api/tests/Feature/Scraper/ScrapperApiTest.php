<?php

namespace Tests\Feature\Scraper;

use App\Models\ScraperRecipe;
use App\Models\ScraperRun;
use App\Models\ScraperSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScrapperApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scraper.base_url', 'https://chiyodanfe.ceccms.com');
        config()->set('scraper.allowed_hosts', ['chiyodanfe.ceccms.com']);
        config()->set('scraper.allowed_schemes', ['https']);
    }

    /** @return array<string, mixed> */
    private function recipePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Loop Index report',
            'start_url' => 'https://chiyodanfe.ceccms.com/Reports.aspx',
            'dataset_key' => 'loop_index',
            'actions' => [
                ['type' => 'click', 'locator' => ['strategy' => 'role', 'role' => 'link', 'name' => 'Reports']],
                ['type' => 'download', 'locator' => ['strategy' => 'role', 'role' => 'button', 'name' => 'Export']],
            ],
        ], $overrides);
    }

    /* ------------------------------------------------------------------ *
     * Authentication
     * ------------------------------------------------------------------ */

    public function test_every_scrapper_endpoint_rejects_unauthenticated_requests(): void
    {
        $recipe = ScraperRecipe::factory()->create();
        $run = ScraperRun::factory()->create();

        $calls = [
            ['getJson', '/api/v1/scrapper/session'],
            ['postJson', '/api/v1/scrapper/session'],
            ['postJson', '/api/v1/scrapper/session/validate'],
            ['deleteJson', '/api/v1/scrapper/session'],
            ['getJson', '/api/v1/scrapper/recipes'],
            ['postJson', '/api/v1/scrapper/recipes'],
            ['getJson', "/api/v1/scrapper/recipes/{$recipe->uuid}"],
            ['patchJson', "/api/v1/scrapper/recipes/{$recipe->uuid}"],
            ['deleteJson', "/api/v1/scrapper/recipes/{$recipe->uuid}"],
            ['postJson', "/api/v1/scrapper/recipes/{$recipe->uuid}/runs"],
            ['getJson', '/api/v1/scrapper/runs'],
            ['getJson', "/api/v1/scrapper/runs/{$run->uuid}"],
            ['getJson', "/api/v1/scrapper/runs/{$run->uuid}/preview"],
            ['postJson', '/api/v1/scrapper/codegen/parse'],
        ];

        foreach ($calls as [$method, $uri]) {
            $this->{$method}($uri)->assertStatus(401, "{$method} {$uri} should require auth");
        }
    }

    /* ------------------------------------------------------------------ *
     * User isolation — one user must never reach another's data.
     * ------------------------------------------------------------------ */

    public function test_a_user_cannot_read_another_users_recipe(): void
    {
        $recipe = ScraperRecipe::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/scrapper/recipes/{$recipe->uuid}")->assertStatus(403);
    }

    public function test_a_user_cannot_update_or_delete_another_users_recipe(): void
    {
        $recipe = ScraperRecipe::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/scrapper/recipes/{$recipe->uuid}", ['name' => 'Hijacked'])
            ->assertStatus(403);
        $this->deleteJson("/api/v1/scrapper/recipes/{$recipe->uuid}")->assertStatus(403);

        $this->assertSame('Loop Index report', $recipe->fresh()->name);
    }

    public function test_a_user_cannot_run_another_users_recipe(): void
    {
        $recipe = ScraperRecipe::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/scrapper/recipes/{$recipe->uuid}/runs", ['mode' => 'download'])
            ->assertStatus(403);

        $this->assertDatabaseCount('scraper_runs', 0);
    }

    public function test_a_user_cannot_read_another_users_run_or_preview(): void
    {
        $run = ScraperRun::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/scrapper/runs/{$run->uuid}")->assertStatus(403);
        $this->getJson("/api/v1/scrapper/runs/{$run->uuid}/preview")->assertStatus(403);
    }

    public function test_listings_only_contain_the_callers_own_rows(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        ScraperRecipe::factory()->create(['user_id' => $mine->id, 'name' => 'Mine']);
        ScraperRecipe::factory()->create(['user_id' => $theirs->id, 'name' => 'Theirs']);
        ScraperRun::factory()->create(['user_id' => $theirs->id]);

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/scrapper/recipes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');

        $this->getJson('/api/v1/scrapper/runs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_one_users_session_is_invisible_to_another(): void
    {
        ScraperSession::factory()->create(['host' => 'chiyodanfe.ceccms.com']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/scrapper/session')
            ->assertOk()
            ->assertJsonPath('session', null);
    }

    /* ------------------------------------------------------------------ *
     * Recipe validation
     * ------------------------------------------------------------------ */

    public function test_it_creates_a_recipe(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Loop Index report')
            ->assertJsonPath('data.dataset_key', 'loop_index');

        $this->assertDatabaseHas('scraper_recipes', ['user_id' => $user->id, 'name' => 'Loop Index report']);
    }

    public function test_it_rejects_a_start_url_outside_the_allowlist(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            'https://evil.test/steal',
            'https://chiyodanfe.ceccms.com.attacker.test/',
            'http://chiyodanfe.ceccms.com/',
            'file:///etc/passwd',
            '/relative/path',
        ] as $url) {
            $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload(['start_url' => $url]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['start_url']);
        }

        $this->assertDatabaseCount('scraper_recipes', 0);
    }

    public function test_it_rejects_an_unsupported_action_type(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload([
            'actions' => [['type' => 'evaluate', 'script' => 'alert(1)']],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('scraper_recipes', 0);
    }

    public function test_it_rejects_a_goto_action_outside_the_allowlist(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload([
            'actions' => [['type' => 'goto', 'url' => 'https://evil.test/']],
        ]))->assertStatus(422);
    }

    public function test_it_rejects_an_unsupported_locator_strategy(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload([
            'actions' => [['type' => 'click', 'locator' => ['strategy' => 'xpath', 'xpath' => '//a']]],
        ]))->assertStatus(422);
    }

    public function test_it_strips_unknown_keys_from_stored_actions(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload([
            'actions' => [[
                'type' => 'click',
                'locator' => ['strategy' => 'css', 'css' => '#export', 'onclick' => 'alert(1)'],
                'script' => 'rm -rf /',
            ]],
        ]))->assertStatus(201);

        $stored = ScraperRecipe::first()->actions;

        $this->assertSame(['type', 'locator'], array_keys($stored[0]));
        $this->assertSame(['strategy', 'css'], array_keys($stored[0]['locator']));
    }

    public function test_it_rejects_a_malformed_dataset_key(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/recipes', $this->recipePayload(['dataset_key' => 'Loop Index!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dataset_key']);
    }
}
