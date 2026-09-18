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
 * Strip anything secret-shaped before it can reach stdout or stderr.
 * Defence in depth: the worker is written not to put secrets in messages, but
 * an error thrown from deep inside Playwright is not under our control.
 */
export function redact(value, depth = 0) {
    if (depth > 6) return "[truncated]";
    if (value === null || value === undefined) return value;

    if (Array.isArray(value)) {
        return value.slice(0, 50).map((item) => redact(item, depth + 1));
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
