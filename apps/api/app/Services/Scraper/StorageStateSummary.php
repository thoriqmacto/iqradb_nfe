<?php

namespace App\Services\Scraper;

use Carbon\CarbonImmutable;

/**
 * Describes a stored Playwright storage state without revealing any of it.
 *
 * The question this answers is "when do I have to re-record scdb-auth.json?".
 * A storage state is a bag of cookies with mixed lifetimes:
 *
 *  - Session cookies (`expires` of -1) — SCDB's own `ASP.NET_SessionId` is one.
 *    They die with the browser that recorded them, which is why validating an
 *    hour-old file can still work: the persistent SSO cookies silently re-mint
 *    the short-lived one.
 *  - Persistent cookies — the Entra refresh material. These carry a real
 *    expiry, and once the last of them is gone nothing in the file can
 *    authenticate again.
 *
 * So the file's own hard deadline is the LATEST persistent expiry. That is an
 * upper bound, not a promise: SCDB or Entra can revoke earlier (a password
 * change, a conditional-access policy), which is what "Validate session"
 * is for.
 *
 * SECURITY: the output is counts and timestamps only. No cookie name, value,
 * or domain crosses this boundary — this feeds an API response.
 */
class StorageStateSummary
{
    /**
     * @param  array<string, mixed>|null  $storageState
     * @return array{
     *     expires_at: ?string,
     *     first_expiry_at: ?string,
     *     cookies: int,
     *     persistent_cookies: int,
     *     session_cookies: int
     * }
     */
    public function summarize(?array $storageState): array
    {
        $cookies = $storageState['cookies'] ?? [];

        if (! is_array($cookies)) {
            $cookies = [];
        }

        $expiries = [];
        $sessionOnly = 0;

        foreach ($cookies as $cookie) {
            if (! is_array($cookie)) {
                continue;
            }

            $expires = $cookie['expires'] ?? -1;

            if (! is_numeric($expires) || (float) $expires <= 0) {
                $sessionOnly++;

                continue;
            }

            $expiries[] = (int) $expires;
        }

        sort($expiries);

        return [
            // The outer bound: after this, no cookie in the file is alive.
            'expires_at' => $this->iso(end($expiries) ?: null),
            // The first one to go. Useful context when the two are far apart.
            'first_expiry_at' => $this->iso($expiries[0] ?? null),
            'cookies' => count($cookies),
            'persistent_cookies' => count($expiries),
            'session_cookies' => $sessionOnly,
        ];
    }

    private function iso(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String();
    }
}
