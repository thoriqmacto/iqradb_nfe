import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
    assertAllowedUrl,
    isAllowedHost,
    looksLikeLogin,
    safeTarget,
    safeUrl,
    UnsafeUrlError,
} from "../src/urls.mjs";

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

describe("safeUrl", () => {
    it("keeps origin and path", () => {
        assert.equal(
            safeUrl("https://chiyodanfe.ceccms.com/Reports.aspx"),
            "https://chiyodanfe.ceccms.com/Reports.aspx",
        );
    });

    /**
     * The reason this function exists. An SSO callback parks OAuth material in
     * the query string, and the caller stores and displays whatever it gets.
     */
    it("strips OAuth material from an SSO callback", () => {
        const withSecrets =
            "https://chiyodanfe.ceccms.com/signin-oidc" +
            "?code=0.AVQAsecret-authorization-code" +
            "&id_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.payload.signature" +
            "&state=abc123&session_state=def456";

        const cleaned = safeUrl(withSecrets);

        assert.equal(cleaned, "https://chiyodanfe.ceccms.com/signin-oidc");
        for (const secret of ["code=", "id_token=", "eyJ0eXAi", "state=", "secret"]) {
            assert.ok(!cleaned.includes(secret), `leaked ${secret}`);
        }
    });

    it("strips the fragment, where implicit-flow tokens live", () => {
        assert.equal(
            safeUrl("https://login.microsoftonline.com/common/oauth2/authorize#access_token=xyz"),
            "https://login.microsoftonline.com/common/oauth2/authorize",
        );
    });

    it("returns an empty string for junk rather than echoing it", () => {
        assert.equal(safeUrl("not a url"), "");
        assert.equal(safeUrl(null), "");
        assert.equal(safeUrl(undefined), "");
    });
});

describe("isAllowedHost", () => {
    it("recognises the application's own host", () => {
        assert.equal(isAllowedHost("https://chiyodanfe.ceccms.com/x", HOSTS), true);
        assert.equal(isAllowedHost("https://CHIYODANFE.ceccms.com/x", HOSTS), true);
    });

    /**
     * Sitting on the identity provider after the chain settles is how a
     * Conditional Access or MFA challenge shows up — a different problem from
     * an ordinary expiry, so it must be distinguishable.
     */
    it("recognises an identity provider as somewhere else", () => {
        assert.equal(
            isAllowedHost("https://login.microsoftonline.com/common/oauth2/authorize", HOSTS),
            false,
        );
    });

    it("is false for junk", () => {
        assert.equal(isAllowedHost("not a url", HOSTS), false);
        assert.equal(isAllowedHost("", HOSTS), false);
    });
});

describe("safeTarget", () => {
    it("keeps the query, because record ids live there", () => {
        assert.equal(
            safeTarget("https://chiyodanfe.ceccms.com/Report.aspx?docId=44821&rev=B"),
            "https://chiyodanfe.ceccms.com/Report.aspx?docId=44821&rev=B",
        );
    });

    it("redacts values whose parameter name looks like credential material", () => {
        const target = safeTarget(
            "https://chiyodanfe.ceccms.com/cb?code=abc&id_token=xyz&state=s&docId=7",
        );

        assert.ok(!target.includes("abc"), target);
        assert.ok(!target.includes("xyz"), target);
        assert.ok(target.includes("docId=7"), target);
        // The parameter names survive — they say what the link is keyed on.
        assert.ok(target.includes("code=%5Bredacted%5D"), target);
    });

    it("drops the fragment but keeps a relative href", () => {
        assert.equal(safeTarget("../Detail.aspx?id=9#top"), "../Detail.aspx?id=9");
    });

    it("reduces a javascript: href to its scheme", () => {
        assert.equal(safeTarget("javascript:__doPostBack('grid','sel$3')"), "javascript:\u2026");
    });

    it("is empty for nothing", () => {
        assert.equal(safeTarget(null), "");
        assert.equal(safeTarget("   "), "");
    });
});
