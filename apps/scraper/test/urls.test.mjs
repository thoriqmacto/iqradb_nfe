import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { assertAllowedUrl, looksLikeLogin, UnsafeUrlError } from "../src/urls.mjs";

const HOSTS = ["chiyodanfe.ceccms.com"];

describe("assertAllowedUrl", () => {
    it("accepts the configured SCDB host over https", () => {
        assert.equal(
            assertAllowedUrl("https://chiyodanfe.ceccms.com/Reports.aspx", HOSTS),
            "https://chiyodanfe.ceccms.com/Reports.aspx",
        );
    });

    it("is case-insensitive about the host", () => {
        assert.ok(assertAllowedUrl("https://CHIYODANFE.ceccms.com/x", HOSTS));
    });

    it("rejects a different host entirely", () => {
        assert.throws(() => assertAllowedUrl("https://example.com/", HOSTS), UnsafeUrlError);
    });

    it("rejects a prefix-matching lookalike host", () => {
        assert.throws(
            () => assertAllowedUrl("https://evil-chiyodanfe.ceccms.com/", HOSTS),
            UnsafeUrlError,
        );
    });

    it("rejects a suffix-matching lookalike host", () => {
        assert.throws(
            () => assertAllowedUrl("https://chiyodanfe.ceccms.com.attacker.test/", HOSTS),
            UnsafeUrlError,
        );
    });

    it("rejects non-https schemes", () => {
        assert.throws(() => assertAllowedUrl("http://chiyodanfe.ceccms.com/", HOSTS), UnsafeUrlError);
        assert.throws(() => assertAllowedUrl("file:///etc/passwd", HOSTS), UnsafeUrlError);
        assert.throws(
            () => assertAllowedUrl("javascript:alert(1)", HOSTS),
            UnsafeUrlError,
        );
    });

    it("rejects embedded credentials", () => {
        assert.throws(
            () => assertAllowedUrl("https://user:pass@chiyodanfe.ceccms.com/", HOSTS),
            UnsafeUrlError,
        );
    });

    it("rejects relative and empty URLs", () => {
        assert.throws(() => assertAllowedUrl("/Reports.aspx", HOSTS), UnsafeUrlError);
        assert.throws(() => assertAllowedUrl("", HOSTS), UnsafeUrlError);
    });
});

describe("looksLikeLogin", () => {
    const markers = ["/login.aspx"];

    it("detects the SCDB login page", () => {
        assert.equal(
            looksLikeLogin("https://chiyodanfe.ceccms.com/Login.aspx?referrer=x", markers),
            true,
        );
    });

    it("does not flag an ordinary report page", () => {
        assert.equal(looksLikeLogin("https://chiyodanfe.ceccms.com/Reports.aspx", markers), false);
    });

    it("handles null and malformed input", () => {
        assert.equal(looksLikeLogin(null, markers), false);
        assert.equal(looksLikeLogin("not a url", markers), false);
    });
});
