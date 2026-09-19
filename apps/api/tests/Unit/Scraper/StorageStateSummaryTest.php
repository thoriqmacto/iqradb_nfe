<?php

namespace Tests\Unit\Scraper;

use App\Services\Scraper\StorageStateSummary;
use PHPUnit\Framework\TestCase;

class StorageStateSummaryTest extends TestCase
{
    private function summarize(array $cookies): array
    {
        return (new StorageStateSummary)->summarize(['cookies' => $cookies]);
    }

    public function test_the_deadline_is_the_last_dated_cookie_to_expire(): void
    {
        $soon = time() + 3600;
        $later = time() + 86400 * 30;

        $summary = $this->summarize([
            ['name' => 'a', 'expires' => $soon],
            ['name' => 'b', 'expires' => $later],
        ]);

        $this->assertSame($later, strtotime((string) $summary['expires_at']));
        $this->assertSame($soon, strtotime((string) $summary['first_expiry_at']));
        $this->assertSame(2, $summary['persistent_cookies']);
        $this->assertSame(0, $summary['session_cookies']);
    }

    public function test_session_cookies_are_counted_but_never_dated(): void
    {
        // Playwright writes -1 for a cookie that dies with the browser; SCDB's
        // own ASP.NET_SessionId is one of these.
        $summary = $this->summarize([
            ['name' => 'ASP.NET_SessionId', 'expires' => -1],
            ['name' => 'other'],
        ]);

        $this->assertNull($summary['expires_at']);
        $this->assertNull($summary['first_expiry_at']);
        $this->assertSame(0, $summary['persistent_cookies']);
        $this->assertSame(2, $summary['session_cookies']);
        $this->assertSame(2, $summary['cookies']);
    }

    public function test_it_reports_no_cookie_name_value_or_domain(): void
    {
        $summary = (new StorageStateSummary)->summarize([
            'cookies' => [[
                'name' => 'ESTSAUTHPERSISTENT',
                'value' => 'super-secret-value',
                'domain' => 'login.microsoftonline.com',
                'expires' => time() + 600,
            ]],
        ]);

        $flat = json_encode($summary);

        $this->assertStringNotContainsString('super-secret-value', (string) $flat);
        $this->assertStringNotContainsString('ESTSAUTHPERSISTENT', (string) $flat);
        $this->assertStringNotContainsString('microsoftonline', (string) $flat);
    }

    public function test_it_survives_a_state_with_no_cookies_at_all(): void
    {
        $this->assertSame(
            ['expires_at' => null, 'first_expiry_at' => null, 'cookies' => 0, 'persistent_cookies' => 0, 'session_cookies' => 0],
            (new StorageStateSummary)->summarize(null)
        );

        $this->assertSame(0, (new StorageStateSummary)->summarize(['cookies' => 'nope'])['cookies']);
    }
}
