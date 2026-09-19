/**
 * Session validation.
 *
 * Loads stored state, visits the SCDB landing page, and reports where it ended
 * up. Deliberately thin — it must never echo the storage state back, only a
 * verdict.
 */
import { withBrowserContext, executablePathFromEnv } from "./browser.mjs";
import { assertAllowedUrl, isAllowedHost, looksLikeLogin, safeUrl } from "./urls.mjs";

/** How long to let an SSO redirect chain finish before judging it. */
const SSO_SETTLE_MS = 20000;

/**
 * Let a single-sign-on round trip complete before reading the URL.
 *
 * SCDB authenticates through Microsoft Entra ID, which makes `Login.aspx` a
 * step on the *successful* path, not only the failure state:
 *
 *   GET /  →  Login.aspx  →  login.microsoftonline.com  (silent SSO)
 *          →  POST back to SCDB  →  new ASP.NET session  →  landing page
 *
 * Parts of that chain are driven by auto-submitting forms and script, which
 * `goto({ waitUntil: "domcontentloaded" })` does not wait for. Sampling the URL
 * at that first load therefore catches the bounce in flight and reports a
 * perfectly good session as expired.
 */
async function settleAfterSso(page, { loginMarkers, allowedHosts, timeout = SSO_SETTLE_MS }) {
    const settled = (url) =>
        isAllowedHost(url, allowedHosts) && !looksLikeLogin(url, loginMarkers);

    if (settled(page.url())) {
        return;
    }

    // A timeout here is not an error: it means the chain never got back to the
    // application, which the caller classifies below.
    await page
        .waitForURL((url) => settled(url.toString()), { timeout })
        .catch(() => {});
}

/**
 * @returns {Promise<{sessionStatus: "valid"|"expired"|"invalid", message: string, finalUrl: string}>}
 */
export async function validateSession(input) {
    const {
        url,
        storageState,
        allowedHosts,
        loginMarkers = [],
        navigationTimeoutMs = 30000,
    } = input;

    // When Laravel did not pass an explicit allowlist, derive it from the URL
    // it already validated on its side.
    const hosts = allowedHosts ?? [new URL(url).hostname];
    const target = assertAllowedUrl(url, hosts);

    if (!storageState || typeof storageState !== "object") {
        return {
            sessionStatus: "invalid",
            message: "No authentication state is stored.",
            finalUrl: safeUrl(target),
        };
    }

    return withBrowserContext(
        {
            storageState,
            acceptDownloads: false,
            navigationTimeoutMs,
            executablePath: executablePathFromEnv(),
        },
        async ({ page }) => {
            await page.goto(target, { waitUntil: "domcontentloaded" });

            await settleAfterSso(page, { loginMarkers, allowedHosts: hosts });

            // origin + path only: an SSO callback carries OAuth material in the
            // query string, and this value is stored and shown in the UI.
            const finalUrl = safeUrl(page.url());

            // Still parked at an identity provider means silent SSO did not
            // complete — usually a Conditional Access policy or an MFA prompt
            // that needs a human. Distinct from an ordinary expiry, because the
            // fix is different.
            if (!isAllowedHost(page.url(), hosts)) {
                return {
                    sessionStatus: "expired",
                    message:
                        `Sign-in stopped at ${finalUrl} instead of returning to SCDB. ` +
                        "The identity provider wants an interactive sign-in — commonly a " +
                        "Conditional Access rule or MFA challenge triggered by the server's " +
                        "IP address. Re-recording the session will not get past this on its own.",
                    finalUrl,
                };
            }

            if (looksLikeLogin(page.url(), loginMarkers)) {
                return {
                    sessionStatus: "expired",
                    message:
                        "SCDB settled on its login page, so single sign-on did not re-establish " +
                        "a session. Record a fresh authentication state and upload it again.",
                    finalUrl,
                };
            }

            return {
                sessionStatus: "valid",
                message: `SCDB accepted the stored session (landed on ${finalUrl}).`,
                finalUrl,
            };
        },
    );
}
