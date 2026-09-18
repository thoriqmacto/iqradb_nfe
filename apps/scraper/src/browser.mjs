/**
 * Chromium lifecycle.
 *
 * One isolated BrowserContext per run, authenticated storage state loaded at
 * creation, and everything closed in a `finally` regardless of outcome — a
 * leaked context on a long-lived queue worker is a slow memory leak and a
 * lingering authenticated session.
 */
import { chromium } from "playwright-core";

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
