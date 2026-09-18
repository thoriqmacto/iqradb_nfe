/**
 * Executes a validated recipe against SCDB.
 *
 * Failure reporting is a first-class concern here: automating a legacy ASP.NET
 * app means markup changes will break recipes, and "500 Internal Server Error"
 * is useless to whoever has to fix it. Every failure carries the step index,
 * the action description, and the locator that could not be found.
 */
import { createHash } from "node:crypto";
import { createReadStream } from "node:fs";
import { mkdir, rename, stat } from "node:fs/promises";
import { basename, join } from "node:path";

import { withBrowserContext, executablePathFromEnv } from "./browser.mjs";
import { buildLocator } from "./locators.mjs";
import { describeAction, describeLocator, validateRecipe } from "./recipe.mjs";
import { assertAllowedUrl, looksLikeLogin, UnsafeUrlError } from "./urls.mjs";

export class SessionExpiredError extends Error {
    constructor(url) {
        super("SCDB redirected to the login page.");
        this.name = "SessionExpiredError";
        this.finalUrl = url;
    }
}

export class StepError extends Error {
    constructor(message, index, action) {
        super(message);
        this.name = "StepError";
        this.index = index;
        this.action = action;
    }
}

/** SCDB hands us the filename; treat it as untrusted input. */
export function sanitizeFilename(name, fallback = "report.csv") {
    const base = basename(String(name ?? "").replaceAll("\\", "/"));
    const cleaned = base.replace(/[^A-Za-z0-9._-]+/g, "_").replace(/^[._-]+|[._-]+$/g, "");
    return cleaned === "" ? fallback : cleaned.slice(0, 150);
}

export async function sha256File(path) {
    const hash = createHash("sha256");
    for await (const chunk of createReadStream(path)) {
        hash.update(chunk);
    }
    return hash.digest("hex");
}

/**
 * @param {object} input the Laravel payload
 */
export async function runRecipe(input) {
    const {
        startUrl,
        actions,
        mode,
        outputDirectory,
        storageState,
        allowedHosts,
        loginMarkers = [],
        navigationTimeoutMs = 30000,
        actionTimeoutMs = 15000,
        maxDownloadBytes = 67108864,
        expectedExtension = "csv",
    } = input;

    // Validate before launching anything: a bad recipe should not cost a browser.
    const validated = validateRecipe(actions, { allowedHosts });
    const entryUrl = assertAllowedUrl(startUrl, allowedHosts);

    await mkdir(outputDirectory, { recursive: true });

    return withBrowserContext(
        {
            storageState,
            acceptDownloads: mode !== "test_navigation",
            navigationTimeoutMs,
            actionTimeoutMs,
            executablePath: executablePathFromEnv(),
        },
        async ({ page }) => {
            await page.goto(entryUrl, { waitUntil: "domcontentloaded" });

            // Check immediately: if the stored state is stale, SCDB bounces to
            // Login.aspx and every subsequent step would fail confusingly.
            assertAuthenticated(page, loginMarkers);

            let download = null;

            for (const [index, action] of validated.entries()) {
                try {
                    const result = await executeAction(page, action, {
                        allowedHosts,
                        actionTimeoutMs,
                        total: validated.length,
                    });

                    if (result?.download) {
                        download = result.download;
                    }
                } catch (error) {
                    if (error instanceof SessionExpiredError) throw error;

                    // A mid-recipe redirect to login is an expired session, not
                    // a missing button — classify it before reporting a step error.
                    if (looksLikeLogin(page.url(), loginMarkers)) {
                        throw new SessionExpiredError(page.url());
                    }

                    throw new StepError(
                        `Step ${index + 1}/${validated.length} failed: ${describeAction(action)} — ${shortMessage(error, action)}`,
                        index,
                        action,
                    );
                }
            }

            assertAuthenticated(page, loginMarkers);

            if (mode === "test_navigation") {
                return { finalUrl: page.url(), steps: validated.length };
            }

            if (!download) {
                throw new StepError(
                    "The recipe completed but captured no download. Add a `download` step on the export button.",
                    validated.length - 1,
                    validated.at(-1) ?? null,
                );
            }

            const saved = await saveDownload(download, {
                outputDirectory,
                maxDownloadBytes,
                expectedExtension,
            });

            return { finalUrl: page.url(), steps: validated.length, ...saved };
        },
    );
}

function assertAuthenticated(page, loginMarkers) {
    if (looksLikeLogin(page.url(), loginMarkers)) {
        throw new SessionExpiredError(page.url());
    }
}

/**
 * Trim a Playwright error down to something a person can act on. Playwright's
 * messages carry a full call log that is noise in a UI.
 */
function shortMessage(error, action) {
    if (error instanceof UnsafeUrlError) {
        return `blocked navigation (${error.message})`;
    }

    const raw = String(error?.message ?? error);

    if (/Timeout .* exceeded/i.test(raw)) {
        return action?.locator
            ? `timed out waiting for ${describeLocator(action.locator)}`
            : "timed out";
    }

    if (/strict mode violation/i.test(raw)) {
        return `${describeLocator(action?.locator)} matched more than one element — make the locator more specific`;
    }

    return raw.split("\n")[0].slice(0, 300);
}

async function executeAction(page, action, { allowedHosts, actionTimeoutMs }) {
    const timeout = action.timeoutMs ?? actionTimeoutMs;

    switch (action.type) {
        case "goto":
            // Re-check at execution time, not just validation time.
            await page.goto(assertAllowedUrl(action.url, allowedHosts), {
                waitUntil: "domcontentloaded",
            });
            return null;

        case "waitForLoadState":
            await page.waitForLoadState(action.state, { timeout });
            return null;

        case "waitForURL":
            await page.waitForURL(action.url, { timeout });
            return null;

        case "waitForVisible":
            await buildLocator(page, action.locator).waitFor({ state: "visible", timeout });
            return null;

        case "click":
            await buildLocator(page, action.locator).click({ timeout });
            return null;

        case "fill":
            await buildLocator(page, action.locator).fill(action.value, { timeout });
            return null;

        case "selectOption":
            await buildLocator(page, action.locator).selectOption(action.value, { timeout });
            return null;

        case "press":
            await buildLocator(page, action.locator).press(action.value, { timeout });
            return null;

        case "download": {
            // The browser's download event is the only reliable signal here.
            // Guessing the export URL breaks the moment SCDB changes how it
            // builds report links, and misses server-rendered postback exports
            // entirely.
            const waitForDownload = page.waitForEvent("download", { timeout });
            await buildLocator(page, action.locator).click({ timeout });
            return { download: await waitForDownload };
        }

        default:
            throw new Error(`Unsupported action "${action.type}".`);
    }
}

async function saveDownload(download, { outputDirectory, maxDownloadBytes, expectedExtension }) {
    const failure = await download.failure();

    if (failure) {
        throw new Error(`The download did not complete: ${failure}`);
    }

    const filename = sanitizeFilename(download.suggestedFilename(), `report.${expectedExtension}`);
    const target = join(outputDirectory, filename);

    // Playwright stages the file in a temp location; move it into the run dir.
    const temporary = await download.path();
    await rename(temporary, target).catch(async () => {
        // rename() fails across filesystems; fall back to Playwright's copy.
        await download.saveAs(target);
    });

    const stats = await stat(target);

    if (stats.size === 0) {
        throw new Error("SCDB returned an empty file.");
    }

    if (stats.size > maxDownloadBytes) {
        throw new Error(
            `The downloaded file is ${stats.size} bytes, over the ${maxDownloadBytes} byte limit.`,
        );
    }

    return {
        filename,
        size: stats.size,
        checksum: await sha256File(target),
    };
}
