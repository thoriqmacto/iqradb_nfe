/**
 * The stdout contract between this worker and Laravel.
 *
 * Exactly one JSON object is printed to stdout, and nothing else ever is —
 * diagnostics go to stderr. Laravel parses stdout as the result envelope, so a
 * stray console.log here would corrupt a run.
 */

/** Keys whose values must never be printed, at any nesting depth. */
const SECRET_KEYS = new Set([
    "storagestate",
    "storage_state",
    "cookies",
    "cookie",
    "token",
    "password",
    "authorization",
    "set-cookie",
]);

/**
 * How deep a reported structure may nest before it is cut off.
 *
 * The guard exists for values this worker does not control — an error object
 * thrown from inside Playwright can be arbitrarily deep or self-referential.
 * It must not cut off structures the worker builds deliberately: the failure
 * inventory reaches depth 7 at `pageInventory.frames[].roles.<role>.samples[]`,
 * which a limit of 6 replaced with "[truncated]" — losing the one field that
 * says what the page actually contained.
 */
const MAX_DEPTH = 12;

/** How many items of any one array are reported. */
const MAX_ITEMS = 100;

/**
 * Strip anything secret-shaped before it can reach stdout or stderr.
 * Defence in depth: the worker is written not to put secrets in messages, but
 * an error thrown from deep inside Playwright is not under our control.
 */
export function redact(value, depth = 0) {
    if (depth > MAX_DEPTH) return "[truncated]";
    if (value === null || value === undefined) return value;

    if (Array.isArray(value)) {
        return value.slice(0, MAX_ITEMS).map((item) => redact(item, depth + 1));
    }

    if (typeof value === "object") {
        const out = {};
        for (const [key, inner] of Object.entries(value)) {
            out[key] = SECRET_KEYS.has(key.toLowerCase())
                ? "[redacted]"
                : redact(inner, depth + 1);
        }
        return out;
    }

    if (typeof value === "string") {
        return value.length > 2000 ? `${value.slice(0, 2000)}…` : value;
    }

    return value;
}

export function success(status, data = {}) {
    return { ok: true, status, data: redact(data) };
}

export function failure(status, errorCode, errorMessage, extra = {}) {
    return {
        ok: false,
        status,
        errorCode,
        errorMessage: redact(String(errorMessage ?? "")),
        ...redact(extra),
    };
}

/** Print the envelope. Called exactly once per process. */
export function emit(envelope) {
    process.stdout.write(`${JSON.stringify(envelope)}\n`);
}
