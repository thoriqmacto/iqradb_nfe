/**
 * Exercises the real Playwright download-capture path against a local HTML
 * fixture, so `page.waitForEvent("download")` handling is covered without SCDB
 * credentials and without putting live authentication into CI.
 *
 * Skipped automatically when no Chromium is installed, which keeps the suite
 * green on a machine that has not run `npm run -w apps/scraper install-browser`.
 */
import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { describe, it } from "node:test";
import { pathToFileURL } from "node:url";

import { sanitizeFilename, sha256File } from "../src/runner.mjs";

const FIXTURE = pathToFileURL(resolve(import.meta.dirname, "fixtures/download.html")).href;

async function chromiumAvailable() {
    try {
        const { chromium } = await import("playwright-core");
        const browser = await chromium.launch({
            headless: true,
            ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
                ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
                : {}),
        });
        await browser.close();
        return true;
    } catch {
        return false;
    }
}

describe("sanitizeFilename", () => {
    it("keeps an ordinary report name", () => {
        assert.equal(sanitizeFilename("loop-index.csv"), "loop-index.csv");
    });

    it("strips directory traversal", () => {
        assert.equal(sanitizeFilename("../../../etc/passwd"), "passwd");
        assert.equal(sanitizeFilename("..\\..\\windows\\system32"), "system32");
    });

    it("neutralises shell metacharacters", () => {
        assert.equal(sanitizeFilename("report;rm -rf .csv"), "report_rm_-rf_.csv");
        assert.equal(sanitizeFilename("re$(whoami)port.csv"), "re_whoami_port.csv");
        assert.equal(sanitizeFilename("a`b`c.csv"), "a_b_c.csv");
    });

    it("strips the path before neutralising, so a slash wins", () => {
        // basename() runs first: everything up to the last slash is discarded.
        assert.equal(sanitizeFilename("report;rm -rf /.csv"), "csv");
    });

    it("falls back when nothing usable is left", () => {
        assert.equal(sanitizeFilename("///", "report.csv"), "report.csv");
        assert.equal(sanitizeFilename("", "report.csv"), "report.csv");
    });
});

describe("download capture", { skip: (await chromiumAvailable()) ? false : "chromium not installed" }, () => {
    it("captures a browser-initiated download and checksums it", async () => {
        const { chromium } = await import("playwright-core");
        const { buildLocator } = await import("../src/locators.mjs");

        const directory = await mkdtemp(join(tmpdir(), "scraper-test-"));
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

            // A `selectOption` step, exactly as a recipe would express it.
            await buildLocator(page, { strategy: "label", label: "Train" }).selectOption("Train-8");

            // The download path under test: arm the listener, then click.
            const waitForDownload = page.waitForEvent("download", { timeout: 15000 });
            await buildLocator(page, { strategy: "role", role: "button", name: "Export" }).click();
            const download = await waitForDownload;

            const filename = sanitizeFilename(download.suggestedFilename());
            assert.equal(filename, "loop-index.csv");

            const target = join(directory, filename);
            await download.saveAs(target);

            const contents = await readFile(target, "utf8");
            assert.match(contents, /^Loop No,Train,Status/);

            const checksum = await sha256File(target);
            assert.match(checksum, /^[0-9a-f]{64}$/);

            await context.close();
        } finally {
            await browser.close();
            await rm(directory, { recursive: true, force: true });
        }
    });
});
