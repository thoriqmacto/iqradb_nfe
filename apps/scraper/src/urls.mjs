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

/**
 * Strip a URL down to origin + path.
 *
 * SECURITY: an SSO round trip parks OAuth material in the query string
 * (`code=`, `id_token=`, `state=`), so a raw `page.url()` captured mid-chain is
 * a credential. Every URL this worker reports back — run diagnostics included —
 * goes through here first.
 */
export function safeUrl(raw) {
    try {
        const url = new URL(String(raw));
        return `${url.origin}${url.pathname}`;
    } catch {
        return "";
    }
}

/** Is this URL on one of the hosts the application owns? */
export function isAllowedHost(raw, allowedHosts) {
    try {
        const host = new URL(String(raw)).hostname.toLowerCase();
        return (allowedHosts ?? [])
            .map((h) => String(h).trim().toLowerCase())
            .includes(host);
    } catch {
        return false;
    }
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

/**
 * Query parameters whose values are never safe to report back.
 *
 * SCDB's own links carry record identifiers in the query string, which is
 * exactly what makes them useful in failure diagnostics — but the same
 * mechanism is how an SSO round trip hands back tokens. Keep the parameter
 * names (they tell the user what the link is keyed on) and drop the values of
 * anything that looks like credential material.
 */
const SECRET_PARAM = /(^|[_-])(code|token|state|secret|password|pwd|passwd|key|auth|sig|signature|session|jwt|assertion|nonce)([_-]|$)/i;

const MAX_TARGET_LENGTH = 300;

/**
 * Like `safeUrl`, but keeps the query string with secret-looking values
 * redacted. Used only for reporting what a page contained, never for
 * navigation — `assertAllowedUrl` remains the only gate on where we go.
 */
export function safeTarget(raw) {
    const text = String(raw ?? "").trim();

    if (text === "") return "";

    let url;
    try {
        url = new URL(text);
    } catch {
        // Relative hrefs are the common case inside a legacy ASP.NET grid, and
        // they carry no origin to leak. Report them as-is, minus any fragment.
        return text.split("#")[0].slice(0, MAX_TARGET_LENGTH);
    }

    if (url.protocol !== "http:" && url.protocol !== "https:") {
        // javascript: and data: hrefs say something about the page, but their
        // bodies are code. The scheme alone is the useful part.
        return `${url.protocol}…`;
    }

    for (const name of [...url.searchParams.keys()]) {
        if (SECRET_PARAM.test(name)) {
            url.searchParams.set(name, "[redacted]");
        }
    }

    return `${url.origin}${url.pathname}${url.search}`.slice(0, MAX_TARGET_LENGTH);
}
