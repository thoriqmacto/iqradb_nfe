<?php

namespace Tests\Feature\Scraper;

use App\Models\ScraperSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The stored SCDB storage state is the most sensitive thing this feature
 * handles. These tests exist to make its confidentiality a regression-tested
 * property rather than a convention.
 */
class ScraperSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_COOKIE = 'super-secret-scdb-session-value';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scraper.base_url', 'https://chiyodanfe.ceccms.com');
        config()->set('scraper.allowed_hosts', ['chiyodanfe.ceccms.com']);
    }

    private function storageStateJson(): string
    {
        return json_encode([
            'cookies' => [[
                'name' => 'ASP.NET_SessionId',
                'value' => self::SECRET_COOKIE,
                'domain' => 'chiyodanfe.ceccms.com',
                'path' => '/',
            ]],
            'origins' => [],
        ]);
    }

    public function test_it_stores_an_uploaded_storage_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()])
            ->assertStatus(201)
            ->assertJsonPath('session.host', 'chiyodanfe.ceccms.com')
            ->assertJsonPath('session.status', ScraperSession::STATUS_UNKNOWN);

        $this->assertDatabaseCount('scraper_sessions', 1);
    }

    public function test_the_storage_state_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()]);

        // Read the raw column, bypassing the model cast entirely.
        $raw = DB::table('scraper_sessions')->value('storage_state');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::SECRET_COOKIE, $raw);
        $this->assertStringNotContainsString('ASP.NET_SessionId', $raw);

        // …but the application can still read it back.
        $this->assertSame(
            self::SECRET_COOKIE,
            ScraperSession::first()->storage_state['cookies'][0]['value'],
        );
    }

    public function test_the_api_never_returns_the_storage_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()]);

        foreach ([
            $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()]),
            $this->getJson('/api/v1/scrapper/session'),
        ] as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString(self::SECRET_COOKIE, $body);
            $this->assertStringNotContainsString('storage_state', $body);
            $this->assertStringNotContainsString('cookies', $body);
        }
    }

    public function test_model_serialisation_hides_the_storage_state(): void
    {
        $session = ScraperSession::factory()->create([
            'storage_state' => ['cookies' => [['name' => 'x', 'value' => self::SECRET_COOKIE]]],
        ]);

        $this->assertArrayNotHasKey('storage_state', $session->toArray());
        $this->assertStringNotContainsString(self::SECRET_COOKIE, $session->toJson());
    }

    public function test_it_rejects_a_file_that_is_not_json(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => 'not json at all'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storage_state']);
    }

    public function test_a_validation_error_does_not_echo_the_upload_back(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/scrapper/session', [
            'storage_state' => 'not json '.self::SECRET_COOKIE,
        ])->assertStatus(422);

        $this->assertStringNotContainsString(self::SECRET_COOKIE, $response->getContent());
    }

    public function test_it_rejects_json_that_is_not_a_storage_state(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => '{"hello":"world"}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storage_state']);
    }

    public function test_it_rejects_an_oversized_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config()->set('scraper.max_storage_state_bytes', 100);

        $this->postJson('/api/v1/scrapper/session', [
            'storage_state' => json_encode(['cookies' => array_fill(0, 200, ['name' => 'x', 'value' => 'y'])]),
        ])->assertStatus(422)->assertJsonValidationErrors(['storage_state']);
    }

    public function test_uploading_again_replaces_rather_than_duplicates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()]);
        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()]);

        $this->assertDatabaseCount('scraper_sessions', 1);
    }

    public function test_replacing_a_session_resets_its_validation_state(): void
    {
        $user = User::factory()->create();
        ScraperSession::factory()->create([
            'user_id' => $user->id,
            'host' => 'chiyodanfe.ceccms.com',
            'status' => ScraperSession::STATUS_EXPIRED,
            'last_validated_at' => now()->subDay(),
            'last_validation_error' => 'expired',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/scrapper/session', ['storage_state' => $this->storageStateJson()])
            ->assertStatus(201)
            ->assertJsonPath('session.status', ScraperSession::STATUS_UNKNOWN)
            ->assertJsonPath('session.last_validated_at', null)
            ->assertJsonPath('session.last_validation_error', null);
    }

    public function test_it_deletes_only_the_callers_session(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        ScraperSession::factory()->create(['user_id' => $mine->id, 'host' => 'chiyodanfe.ceccms.com']);
        ScraperSession::factory()->create(['user_id' => $theirs->id, 'host' => 'chiyodanfe.ceccms.com']);

        Sanctum::actingAs($mine);
        $this->deleteJson('/api/v1/scrapper/session')->assertOk();

        $this->assertDatabaseMissing('scraper_sessions', ['user_id' => $mine->id]);
        $this->assertDatabaseHas('scraper_sessions', ['user_id' => $theirs->id]);
    }

    public function test_validating_without_a_stored_session_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/scrapper/session/validate')->assertStatus(404);
    }
}
