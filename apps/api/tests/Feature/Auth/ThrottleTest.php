<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts from a clean rate-limiter state.
        RateLimiter::clear('auth');
    }

    /**
     * Drives the limiter AppServiceProvider registers, not a stand-in.
     *
     * The limit is read from config('auth.throttle_per_minute') on each
     * request, so setting it here exercises the real wiring — including the
     * part that broke in production, where the limit used to come from an
     * env() call that a config cache silently reduces to its default.
     */
    public function test_login_is_throttled_at_the_configured_limit(): void
    {
        config(['auth.throttle_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => 'nobody@example.com',
                'password' => 'whatever',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(429);
    }
}
