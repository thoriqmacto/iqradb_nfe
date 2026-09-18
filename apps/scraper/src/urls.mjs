/**
 * Navigation allowlist — the worker's own copy.
 *
 * Laravel validates URLs before storing a recipe, but this process is what
 * actually drives the browser, so it re-checks independently. A bug or a stale
 * recipe row must not be enough to make Chromium visit an arbitrary host.
 */

export class UnsafeUrlError extends Error {
    constructor(message) {
        super(message);
        this.name = "UnsafeUrlError";
    }
}

/**
 * @param {string} rawUrl
 * @param {string[]} allowedHosts
 * @param {string[]} allowedSchemes
 */
export function assertAllowedUrl(rawUrl, allowedHosts, allowedSchemes = ["https:"]) {
    const url = String(rawUrl ?? "").trim();

    if (url === "") {
        throw new UnsafeUrlError("URL is empty.");
    }

    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        throw new UnsafeUrlError("URL must be absolute, including scheme and host.");
    }

    const schemes = allowedSchemes.map((s) => (s.endsWith(":") ? s : `${s}:`));

    if (!schemes.includes(parsed.protocol)) {
        throw new UnsafeUrlError(`URL scheme "${parsed.protocol}" is not allowed.`);
    }

    if (parsed.username !== "" || parsed.password !== "") {
        throw new UnsafeUrlError("URL must not embed credentials.");
    }

    const host = parsed.hostname.toLowerCase();
    const allowed = allowedHosts.map((h) => String(h).trim().toLowerCase());

    // Exact match only. "evil-chiyodanfe.ceccms.com" and
    // "chiyodanfe.ceccms.com.attacker.test" must both fail.
    if (!allowed.includes(host)) {
        throw new UnsafeUrlError(`Host "${host}" is not an allowed SCDB host.`);
    }

    return parsed.toString();
}

/** Did SCDB bounce us to its login page? */
export function looksLikeLogin(url, markers) {
    if (!url) return false;

    let path;
    try {
        path = new URL(url).pathname.toLowerCase();
    } catch {
        return false;
    }

    return (markers ?? []).some((marker) => path.includes(String(marker).toLowerCase()));
}
