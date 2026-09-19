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
import {
    assertAllowedUrl,
    isAllowedHost,
    looksLikeLogin,
    safeTarget,
    safeUrl,
    UnsafeUrlError,
} from "./urls.mjs";

export class SessionExpiredError extends Error {
    constructor(url) {
        super("SCDB redirected to the login page.");
        this.name = "SessionExpiredError";
        // origin + path only: an SSO callback carries OAuth material in its
        // query string, and this value is persisted and shown in the UI.
        this.finalUrl = safeUrl(url);
    }
}

export class StepError extends Error {
    constructor(message, index, action, diagnostics = null) {
        super(message);
        this.name = "StepError";
        this.index = index;
        this.action = action;
        this.diagnostics = diagnostics;
    }
}

/** Roles worth enumerating when a locator finds nothing. */
const DIAGNOSTIC_ROLES = [
    "grid",
    "table",
    "row",
    "link",
    "button",
    "textbox",
    "combobox",
    "listitem",
    "cell",
];

const MAX_PER_ROLE = 12;
const MAX_LINKS = 25;
const MAX_FRAMES = 6;
const MAX_LABEL = 120;

/**
 * Enumerate one frame: which roles it exposes, and what its hyperlinks point at.
 *
 * The link inventory is the DOM-level part. In a legacy ASP.NET grid the row a
 * recipe needs to click is an `<a>` whose href or id carries the record key, so
 * reporting those turns "no grid found" into a concrete `css` locator the user
 * can paste back into the step.
 */
async function inventoryFrame(frame, wantedRole) {
    const report = { url: safeUrl(frame.url()), roles: {} };

    try {
        const name = frame.name();
        if (name) report.name = name.slice(0, MAX_LABEL);
    } catch {
        // Detached frame; the URL alone is still worth reporting.
    }

    // Put the role the failing step was looking for first, so a zero count for
    // it is the first thing visible.
    const roles = wantedRole
        ? [wantedRole, ...DIAGNOSTIC_ROLES.filter((role) => role !== wantedRole)]
        : DIAGNOSTIC_ROLES;

    for (const role of roles) {
        try {
            const texts = await frame.getByRole(role).allInnerTexts();

            if (texts.length === 0) continue;

            report.roles[role] = {
                count: texts.length,
                samples: texts
                    .slice(0, MAX_PER_ROLE)
                    .map((text) => text.replace(/\s+/g, " ").trim().slice(0, MAX_LABEL))
                    .filter((text) => text !== ""),
            };
        } catch {
            // A role Playwright does not know, or a frame that just detached.
        }
    }

    try {
        const anchors = await frame.locator("a[href]").all();
        report.linkCount = anchors.length;
        report.links = [];

        for (const anchor of anchors.slice(0, MAX_LINKS)) {
            const [text, href, id] = await Promise.all([
                anchor.innerText().catch(() => ""),
                anchor.getAttribute("href").catch(() => null),
                anchor.getAttribute("id").catch(() => null),
            ]);

            report.links.push({
                text: String(text).replace(/\s+/g, " ").trim().slice(0, MAX_LABEL),
                // Keeps record ids, redacts anything credential-shaped.
                href: safeTarget(href),
                ...(id ? { id: id.slice(0, MAX_LABEL) } : {}),
            });
        }
    } catch {
        // Navigation mid-inventory. Keep the roles already gathered.
    }

    return report;
}

/**
 * Answer "what WAS on the page?" when a locator finds nothing.
 *
 * "Timed out waiting for role=grid" is unactionable on its own: it cannot
 * distinguish a postback that had not finished, a control that renders with a
 * different role than it did while recording, and content sitting inside an
 * iframe — `getByRole` does not descend into frames, so a recipe locator will
 * never see it. Walking every frame separates those three in one look.
 *
 * Playwright's own APIs are used here. That is not the recipe vocabulary —
 * this is runner code reacting to a failure, and nothing in a stored recipe can
 * reach it. No page script is evaluated: attributes come back through
 * `getAttribute`, so there is still no path from page content to execution.
 *
 * Never throws: a diagnostic that fails must not replace the real error.
 */
async function captureFailureDiagnostics(page, action) {
    const diagnostics = {};
    const wantedRole = action?.locator?.strategy === "role" ? action.locator.role : null;

    try {
        diagnostics.url = safeUrl(page.url());
        diagnostics.title = (await page.title()).slice(0, MAX_LABEL);
    } catch {
        // Page may already be closed; keep whatever was gathered.
    }

    let frames = [];
    try {
        frames = page.frames();
    } catch {
        frames = [];
    }

    diagnostics.frameCount = frames.length;
    diagnostics.frames = [];

    for (const frame of frames.slice(0, MAX_FRAMES)) {
        try {
            diagnostics.frames.push(await inventoryFrame(frame, wantedRole));
        } catch {
            // Frame detached between the list and the walk.
        }
    }

    return diagnostics;
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

            // Check up front: if the stored state is stale, SCDB bounces to
            // Login.aspx and every subsequent step would fail confusingly.
            await assertAuthenticated(page, loginMarkers, allowedHosts);

            let download = null;

            // SCDB's export wizard hands off between windows: the switchboard
            // opens an export browser, which opens a wizard, and the file
            // arrives in that last one. `current` is whichever window the
            // recipe is driving right now.
            let current = page;

            for (const [index, action] of validated.entries()) {
                try {
                    const result = await executeAction(current, action, {
                        allowedHosts,
                        actionTimeoutMs,
                        total: validated.length,
                    });

                    if (result?.download) {
                        download = result.download;
                    }

                    if (result?.page) {
                        current = result.page;
                    }
                } catch (error) {
                    if (error instanceof SessionExpiredError) throw error;

                    // A mid-recipe redirect to login is an expired session, not
                    // a missing button — classify it before reporting a step error.
                    if (
                        looksLikeLogin(current.url(), loginMarkers) ||
                        !isAllowedHost(current.url(), allowedHosts)
                    ) {
                        throw new SessionExpiredError(current.url());
                    }

                    throw new StepError(
                        `Step ${index + 1}/${validated.length} failed: ${describeAction(action)} — ${shortMessage(error, action)}`,
                        index,
                        action,
                        await captureFailureDiagnostics(current, action),
                    );
                }
            }

            // Only the login check at the end: a wizard popup legitimately
            // lives on a different path, and the up-front check already proved
            // the session itself.
            if (looksLikeLogin(current.url(), loginMarkers)) {
                throw new SessionExpiredError(current.url());
            }

            if (mode === "test_navigation") {
                return { finalUrl: safeUrl(current.url()), steps: validated.length };
            }

            if (!download) {
                throw new StepError(
                    "The recipe completed but captured no download. Mark the step that triggers the export as a download step.",
                    validated.length - 1,
                    validated.at(-1) ?? null,
                    await captureFailureDiagnostics(current, validated.at(-1) ?? null),
                );
            }

            const saved = await saveDownload(download, {
                outputDirectory,
                maxDownloadBytes,
                expectedExtension,
            });

            return { finalUrl: safeUrl(current.url()), steps: validated.length, ...saved };
        },
    );
}

/**
 * Confirm we are on an authenticated SCDB page, allowing an SSO round trip to
 * finish first.
 *
 * SCDB signs in through Microsoft Entra ID, so `Login.aspx` appears on the
 * *successful* path as well as the failed one — the chain passes through it on
 * the way to the identity provider and back. Checking the URL the instant a
 * navigation reports `domcontentloaded` catches that bounce mid-flight and
 * mistakes a healthy session for an expired one.
 */
async function assertAuthenticated(page, loginMarkers, allowedHosts) {
    const settled = (url) =>
        isAllowedHost(url, allowedHosts) && !looksLikeLogin(url, loginMarkers);

    if (!settled(page.url())) {
        await page
            .waitForURL((url) => settled(url.toString()), { timeout: 20000 })
            .catch(() => {});
    }

    if (!settled(page.url())) {
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

        case "click": {
            if (!action.opensPopup) {
                await buildLocator(page, action.locator).click({ timeout });
                return null;
            }

            // Arm the listener before clicking: the window can open and finish
            // loading faster than the click call returns.
            const popupPromise = page.waitForEvent("popup", { timeout });
            await buildLocator(page, action.locator).click({ timeout });
            const popup = await popupPromise;

            // Without this the next action can race the popup's first paint.
            await popup.waitForLoadState("domcontentloaded").catch(() => {});

            return { page: popup };
        }

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
