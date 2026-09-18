import ScrapperClient from "./ScrapperClient";

export const metadata = { title: "Scrapper" };

/**
 * SCDB report automation.
 *
 * This page only configures, starts and monitors runs. The browser automation
 * itself runs on the VPS, in a Laravel queue worker driving headless Chromium —
 * never here, and never in the visitor's browser.
 */
export default function ScrapperPage() {
    return <ScrapperClient />;
}
