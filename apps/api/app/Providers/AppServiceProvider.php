<?php

namespace App\Providers;

use App\Services\Import\ImportAdapterRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImportAdapterRegistry::class, function (): ImportAdapterRegistry {
            $registry = new ImportAdapterRegistry;

            // Register ScdbImportAdapter implementations here as the Loop
            // Index / SAT / Package / Milestone domain models land. Until then
            // every dataset stages its rows and stops at `ready_for_mapping`
            // rather than guessing at a schema.

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordResetUrl();
        $this->configureEmailVerificationUrl();
    }

    private function configureRateLimiters(): void
    {
        // Public auth endpoints (login, register, forgot/reset password).
        // Keyed by authenticated user (if any) else IP.
        RateLimiter::for('auth', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(
                (int) config('auth.throttle_per_minute')
            )->by((string) $key);
        });
    }

    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            $frontend = (string) config('app.frontend_url');
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);

            return "{$frontend}/reset-password?{$query}";
        });
    }

    private function configureEmailVerificationUrl(): void
    {
        // Email verification link points at the backend so signature
        // validation runs there. The backend redirects to the frontend
        // with ?status=verified after success.
        VerifyEmail::createUrlUsing(function ($user): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes((int) config('auth.verification_link_ttl_minutes')),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
            );
        });
    }
}
