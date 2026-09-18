<?php

namespace Tests\Unit\Scraper;

use App\Services\Scraper\ScdbUrlGuard;
use App\Services\Scraper\UnsafeUrlException;
use Tests\TestCase;

class ScdbUrlGuardTest extends TestCase
{
    private ScdbUrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scraper.allowed_hosts', ['chiyodanfe.ceccms.com']);
        config()->set('scraper.allowed_schemes', ['https']);
        config()->set('scraper.login_markers', ['/login.aspx']);

        $this->guard = new ScdbUrlGuard;
    }

    public function test_it_allows_the_configured_scdb_host(): void
    {
        $this->assertTrue($this->guard->isAllowed('https://chiyodanfe.ceccms.com/Reports.aspx'));
    }

    public function test_it_ignores_host_casing(): void
    {
        $this->assertTrue($this->guard->isAllowed('https://CHIYODANFE.CECCMS.COM/x'));
    }

    public function test_it_rejects_an_unrelated_host(): void
    {
        $this->assertFalse($this->guard->isAllowed('https://example.com/'));
    }

    /**
     * Substring matching would accept both of these. Exact matching must not.
     */
    public function test_it_rejects_lookalike_hosts(): void
    {
        $this->assertFalse($this->guard->isAllowed('https://evil-chiyodanfe.ceccms.com/'));
        $this->assertFalse($this->guard->isAllowed('https://chiyodanfe.ceccms.com.attacker.test/'));
        $this->assertFalse($this->guard->isAllowed('https://sub.chiyodanfe.ceccms.com/'));
    }

    public function test_it_rejects_non_https_schemes(): void
    {
        $this->assertFalse($this->guard->isAllowed('http://chiyodanfe.ceccms.com/'));
        $this->assertFalse($this->guard->isAllowed('file:///etc/passwd'));
        $this->assertFalse($this->guard->isAllowed('gopher://chiyodanfe.ceccms.com/'));
    }

    public function test_it_rejects_embedded_credentials(): void
    {
        $this->assertFalse($this->guard->isAllowed('https://user:pass@chiyodanfe.ceccms.com/'));
    }

    public function test_it_rejects_relative_and_malformed_urls(): void
    {
        $this->assertFalse($this->guard->isAllowed('/Reports.aspx'));
        $this->assertFalse($this->guard->isAllowed(''));
        $this->assertFalse($this->guard->isAllowed('not a url'));
    }

    public function test_it_rejects_urls_containing_control_characters(): void
    {
        // CRLF injection and null-byte truncation, embedded mid-URL where they
        // would actually do damage. (A purely trailing control character is
        // stripped by trim() first, which leaves an ordinary valid URL.)
        $this->assertFalse($this->guard->isAllowed("https://chiyodanfe.ceccms.com/\r\nHost: evil.test"));
        $this->assertFalse($this->guard->isAllowed("https://chiyodanfe.ceccms.com/a\0b"));
        $this->assertFalse($this->guard->isAllowed('https://chiyodanfe.ceccms.com/a b'));
        $this->assertFalse($this->guard->isAllowed("https://chiyodanfe.ceccms.com/a\tb"));
    }

    public function test_assert_allowed_throws_with_a_reason(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->guard->assertAllowed('https://example.com/');
    }

    public function test_it_detects_the_login_page(): void
    {
        $this->assertTrue($this->guard->looksLikeLogin('https://chiyodanfe.ceccms.com/Login.aspx?referrer=x'));
        $this->assertFalse($this->guard->looksLikeLogin('https://chiyodanfe.ceccms.com/Reports.aspx'));
        $this->assertFalse($this->guard->looksLikeLogin(null));
    }
}
