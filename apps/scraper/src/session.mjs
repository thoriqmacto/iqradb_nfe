/**
 * Session validation.
 *
 * Loads stored state, visits the SCDB landing page, and reports where it ended
 * up. Deliberately thin — it must never echo the storage state back, only a
 * verdict.
 */
import { withBrowserContext, executablePathFromEnv } from "./browser.mjs";
import { assertAllowedUrl, looksLikeLogin } from "./urls.mjs";

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
            finalUrl: target,
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

            const finalUrl = page.url();

            if (looksLikeLogin(finalUrl, loginMarkers)) {
                return {
                    sessionStatus: "expired",
                    message: "SCDB redirected to the login page — the stored session is no longer valid.",
                    finalUrl,
                };
            }

            return {
                sessionStatus: "valid",
                message: "SCDB accepted the stored session.",
                finalUrl,
            };
        },
    );
}
