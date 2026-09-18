<?php

namespace App\Services\Scraper;

use App\Models\ScraperSession;

/**
 * Checks whether a stored SCDB session still authenticates.
 *
 * Loads the storage state into a throwaway browser context, visits the
 * configured landing page, and looks at where it ends up. A redirect to
 * Login.aspx means expired — a condition the user can fix — rather than a
 * broken recipe.
 */
class SessionValidator
{
    public function __construct(
        private readonly ScraperProcessRunner $runner,
        private readonly ScdbUrlGuard $urls,
    ) {}

    /**
     * Validate and persist the outcome on the session.
     *
     * @return array{status: string, message: string|null}
     */
    public function validate(ScraperSession $session): array
    {
        $checkUrl = rtrim((string) config('scraper.base_url'), '/')
            .'/'.ltrim((string) config('scraper.session_check_path', '/'), '/');

        try {
            $this->urls->assertAllowed($checkUrl);
        } catch (UnsafeUrlException $e) {
            return $this->persist($session, ScraperSession::STATUS_ERROR, $e->getMessage());
        }

        $result = $this->runner->run('validate-session', [
            'url' => $checkUrl,
            // The only place the decrypted state leaves the database — and it
            // travels over stdin, not argv.
            'storageState' => $session->storage_state,
            'navigationTimeoutMs' => (int) config('scraper.navigation_timeout_ms'),
            'loginMarkers' => (array) config('scraper.login_markers'),
        ], timeoutSeconds: 120);

        if (! $result->ok) {
            $status = $result->status === 'session_expired'
                ? ScraperSession::STATUS_EXPIRED
                : ScraperSession::STATUS_ERROR;

            return $this->persist($session, $status, $result->errorMessage);
        }

        $status = match ($result->get('sessionStatus')) {
            'valid' => ScraperSession::STATUS_VALID,
            'expired' => ScraperSession::STATUS_EXPIRED,
            'invalid' => ScraperSession::STATUS_INVALID,
            default => ScraperSession::STATUS_ERROR,
        };

        return $this->persist($session, $status, $result->get('message'));
    }

    /**
     * @return array{status: string, message: string|null}
     */
    private function persist(ScraperSession $session, string $status, ?string $message): array
    {
        $session->forceFill([
            'status' => $status,
            'last_validated_at' => now(),
            'last_validation_error' => $status === ScraperSession::STATUS_VALID
                ? null
                : ($message === null ? null : mb_substr($message, 0, 255)),
        ])->save();

        return ['status' => $status, 'message' => $message];
    }
}
