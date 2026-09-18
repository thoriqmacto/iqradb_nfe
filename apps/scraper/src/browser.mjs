/**
 * Chromium lifecycle.
 *
 * One isolated BrowserContext per run, authenticated storage state loaded at
 * creation, and everything closed in a `finally` regardless of outcome — a
 * leaked context on a long-lived queue worker is a slow memory leak and a
 * lingering authenticated session.
 */
/**
 * Load playwright-core only when a browser is actually wanted.
 *
 * A top-level import would make the whole CLI unusable without the dependency
 * installed — including the paths that reject a bad recipe or a disallowed host
 * and never launch anything. Those need to keep answering with a real diagnosis
 * (`blocked_url`, `invalid_recipe`) rather than a module-resolution crash, both
 * in CI and on a half-configured server.
 */
async function loadChromium() {
    try {
        const { chromium } = await import("playwright-core");
        return chromium;
    } catch (error) {
        const hint =
            "playwright-core is not installed. Run `npm ci`, then " +
            "`npm run -w apps/scraper install-browser` on the server.";
        throw new Error(`${hint} (${error?.message ?? error})`);
    }
}

/**
 * @param {{storageState?: object, acceptDownloads?: boolean, navigationTimeoutMs?: number, actionTimeoutMs?: number}} options
 */
export async function withBrowserContext(options, callback) {
    const {
        storageState,
        acceptDownloads = true,
        navigationTimeoutMs = 30000,
        actionTimeoutMs = 15000,
        executablePath,
    } = options;

    const chromium = await loadChromium();

    const browser = await chromium.launch({
        headless: true,
        ...(executablePath ? { executablePath } : {}),
    });

    let context;

    try {
        context = await browser.newContext({
            acceptDownloads,
            // A realistic desktop viewport: some ASP.NET report pages render a
            // different (mobile) control set at narrow widths.
            viewport: { width: 1440, height: 900 },
            ...(storageState ? { storageState } : {}),
        });

        context.setDefaultNavigationTimeout(navigationTimeoutMs);
        context.setDefaultTimeout(actionTimeoutMs);

        const page = await context.newPage();

        return await callback({ browser, context, page });
    } finally {
        // Close in order, swallowing teardown errors so they cannot mask the
        // real failure the caller is about to report.
        if (context) {
            await context.close().catch(() => {});
        }
        await browser.close().catch(() => {});
    }
}

/** Where Chromium lives, when the deployment pins it explicitly. */
export function executablePathFromEnv() {
    return process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined;
}
