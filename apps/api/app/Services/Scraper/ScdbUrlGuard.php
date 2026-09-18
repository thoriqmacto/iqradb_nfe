<?php

namespace App\Services\Scraper;

/**
 * The SSRF / open-redirect boundary.
 *
 * Every URL that will ever be handed to Playwright — a recipe's start URL, a
 * `goto` action, a `waitForURL` pattern — passes through here first. The Node
 * worker re-checks independently, but this is the check that decides whether a
 * recipe can be saved at all, so a hostile URL never reaches storage.
 *
 * Rules, in order of paranoia:
 *   - must parse as an absolute URL
 *   - scheme must be in the configured allowlist (https only by default)
 *   - no userinfo (`https://user:pass@host/`) — credential smuggling
 *   - host must match the configured allowlist exactly, case-insensitively
 *
 * Subdomain matching is deliberately NOT supported: `evil-chiyodanfe.ceccms.com`
 * and `chiyodanfe.ceccms.com.attacker.test` must both fail.
 */
class ScdbUrlGuard
{
    /** @return list<string> */
    public function allowedHosts(): array
    {
        return array_map(
            static fn (string $host): string => strtolower(trim($host)),
            (array) config('scraper.allowed_hosts', [])
        );
    }

    /** @return list<string> */
    public function allowedSchemes(): array
    {
        return array_map(
            static fn (string $scheme): string => strtolower(trim($scheme)),
            (array) config('scraper.allowed_schemes', ['https'])
        );
    }

    public function isAllowed(string $url): bool
    {
        return $this->reject($url) === null;
    }

    /**
     * Validate and return the URL normalised, or throw.
     *
     * @throws UnsafeUrlException
     */
    public function assertAllowed(string $url): string
    {
        $reason = $this->reject($url);

        if ($reason !== null) {
            throw new UnsafeUrlException($reason);
        }

        return trim($url);
    }

    /**
     * @return string|null the reason the URL is rejected, or null when it is fine
     */
    public function reject(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'URL is empty.';
        }

        // Reject control characters and whitespace outright: they are how
        // header/URL smuggling usually starts.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return 'URL contains control characters or whitespace.';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'URL must be absolute, including scheme and host.';
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, $this->allowedSchemes(), true)) {
            return sprintf(
                'URL scheme "%s" is not allowed. Allowed: %s.',
                $scheme,
                implode(', ', $this->allowedSchemes())
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'URL must not embed credentials.';
        }

        $host = strtolower($parts['host']);

        if (! in_array($host, $this->allowedHosts(), true)) {
            return sprintf(
                'Host "%s" is not an allowed SCDB host. Allowed: %s.',
                $host,
                implode(', ', $this->allowedHosts())
            );
        }

        return null;
    }

    /**
     * Does this URL look like SCDB bounced us to the login page?
     *
     * Used to classify a failed run as `session_expired` — a condition the user
     * can fix by re-uploading auth state — rather than a broken recipe.
     */
    public function looksLikeLogin(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));

        foreach ((array) config('scraper.login_markers', []) as $marker) {
            if ($path !== '' && str_contains($path, strtolower((string) $marker))) {
                return true;
            }
        }

        return false;
    }
}
