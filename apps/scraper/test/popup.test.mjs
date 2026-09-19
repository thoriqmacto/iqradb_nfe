/**
 * Drives the popup → popup → download chain that SCDB's export wizard uses,
 * against a local fixture. No credentials, no SCDB.
 *
 * Skipped when Chromium is not installed, so CI stays green without a browser.
 */
import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

import { buildLocator } from "../src/locators.mjs";
import { sanitizeFilename } from "../src/runner.mjs";

const FIXTURE = pathToFileURL(resolve(import.meta.dirname, "fixtures/wizard-1-switchboard.html")).href;

async function chromiumAvailable() {
    try {
        const { chromium } = await import("playwright-core");
        const b = await chromium.launch({
            headless: true,
            ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
                ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
                : {}),
        });
        await b.close();
        return true;
    } catch {
        return false;
    }
}

describe(
    "popup chain",
    { skip: (await chromiumAvailable()) ? false : "chromium not installed" },
    () => {
        it("follows two popups and captures the download in the last one", async () => {
            const { chromium } = await import("playwright-core");
            const directory = await mkdtemp(join(tmpdir(), "scraper-popup-"));
            const browser = await chromium.launch({
                headless: true,
                ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
                    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
                    : {}),
            });

            try {
                const context = await browser.newContext({ acceptDownloads: true });
                const page = await context.newPage();
                await page.goto(FIXTURE);

                let current = page;

                // Step: click "Exports", opensPopup -> switch windows.
                let popupPromise = current.waitForEvent("popup", { timeout: 15000 });
                await buildLocator(current, {
                    strategy: "role",
                    role: "button",
                    name: "Exports",
                }).click();
                current = await popupPromise;
                await current.waitForLoadState("domcontentloaded");
                assert.equal(await current.title(), "Export browser");

                // Step: click the grid row containing the report, opensPopup again.
                // hasText is what picks the right row out of two.
                popupPromise = current.waitForEvent("popup", { timeout: 15000 });
                await buildLocator(current, {
                    strategy: "role",
                    role: "row",
                    hasText: "COMP_RPT_Redline markup",
                }).click();
                current = await popupPromise;
                await current.waitForLoadState("domcontentloaded");
                assert.equal(await current.title(), "Wizard");

                // Step: download, in the second popup.
                const downloadPromise = current.waitForEvent("download", { timeout: 15000 });
                await buildLocator(current, {
                    strategy: "role",
                    role: "button",
                    name: "Finish",
                }).click();
                const download = await downloadPromise;

                const filename = sanitizeFilename(download.suggestedFilename());
                assert.equal(filename, "redline.csv");

                const target = join(directory, filename);
                await download.saveAs(target);
                assert.match(await readFile(target, "utf8"), /^Tag,Status/);

                await context.close();
            } finally {
                await browser.close();
                await rm(directory, { recursive: true, force: true });
            }
        });
    },
);
