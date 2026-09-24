<?php

namespace Tests\Feature\Config;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Links that leave the API for the frontend must follow config, not env().
 *
 * Production runs `php artisan config:cache`, after which .env is never loaded
 * and env() outside config/ returns its default. Reset and verification links
 * used to be built from env('FRONTEND_URL'), so every one of them pointed at
 * http://localhost:3000 regardless of what the server's .env said.
 *
 * config()->set() changes what config() returns but has no effect on env(),
 * so these tests fail if either call site regresses to reading env directly.
 */
class FrontendUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_reset_link_follows_the_configured_frontend(): void
    {
        config()->set('app.frontend_url', 'https://iqradb.com');

        $user = User::factory()->make(['email' => 'someone@example.com']);
        $url = (new ResetPassword('a-token'))->toMail($user)->actionUrl;

        $this->assertStringStartsWith('https://iqradb.com/reset-password?', $url);
    }

    public function test_the_verification_redirect_follows_the_configured_frontend(): void
    {
        config()->set('app.frontend_url', 'https://iqradb.com');

        $user = User::factory()->unverified()->create();

        // Validly signed, so the `signed` middleware lets it through to the
        // controller; the wrong hash then takes the redirect-to-frontend path.
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'id' => $user->getKey(),
            'hash' => 'not-the-right-hash',
        ]);

        $this->get($url)->assertRedirect('https://iqradb.com/verify-email?status=invalid');
    }
}
