/**
 * Recipe schema — the worker's independent validation pass.
 *
 * This is the last gate before a structured action becomes a browser command.
 * The action vocabulary is a closed set: an unknown `type` is an error, never
 * something passed through to Playwright. That is what makes it impossible for
 * a stored recipe to describe `page.evaluate` or any other escape hatch.
 */
import { assertAllowedUrl } from "./urls.mjs";

export const ACTION_TYPES = [
    "goto",
    "click",
    "fill",
    "selectOption",
    "press",
    "waitForURL",
    "waitForLoadState",
    "waitForVisible",
    "download",
];

export const LOCATOR_STRATEGIES = ["role", "label", "text", "placeholder", "testId", "css"];

export const LOAD_STATES = ["load", "domcontentloaded", "networkidle"];

const NEEDS_LOCATOR = new Set(["click", "fill", "selectOption", "press", "waitForVisible", "download"]);
const NEEDS_VALUE = new Set(["fill", "selectOption", "press"]);

export class RecipeError extends Error {
    constructor(message, index = null) {
        super(message);
        this.name = "RecipeError";
        this.index = index;
    }
}

/**
 * @param {unknown} actions
 * @param {{allowedHosts: string[], maxActions?: number}} options
 * @returns {Array<object>} the validated actions
 */
export function validateRecipe(actions, options) {
    const { allowedHosts, maxActions = 60 } = options;

    if (!Array.isArray(actions) || actions.length === 0) {
        throw new RecipeError("A recipe needs at least one action.");
    }

    if (actions.length > maxActions) {
        throw new RecipeError(`A recipe may not have more than ${maxActions} actions.`);
    }

    return actions.map((action, index) => validateAction(action, index, allowedHosts));
}

function validateAction(action, index, allowedHosts) {
    if (typeof action !== "object" || action === null || Array.isArray(action)) {
        throw new RecipeError(`Action ${index} must be an object.`, index);
    }

    const { type } = action;

    if (!ACTION_TYPES.includes(type)) {
        throw new RecipeError(`Action ${index} has unsupported type "${type}".`, index);
    }

    const out = { type };

    if (type === "goto") {
        // Throws UnsafeUrlError, which the runner reports as a blocked navigation.
        out.url = assertAllowedUrl(action.url, allowedHosts);
        return out;
    }

    if (type === "waitForURL") {
        if (typeof action.url !== "string" || action.url.trim() === "") {
            throw new RecipeError(`Action ${index} (waitForURL) needs a url.`, index);
        }
        // A wait pattern may be a glob; validate it only when it is absolute.
        if (/^[a-z][a-z0-9+.-]*:\/\//i.test(action.url)) {
            assertAllowedUrl(action.url.replaceAll("*", "x"), allowedHosts);
        }
        out.url = action.url.trim();
        return out;
    }

    if (type === "waitForLoadState") {
        const state = action.state ?? "load";
        if (!LOAD_STATES.includes(state)) {
            throw new RecipeError(`Action ${index} has unsupported load state "${state}".`, index);
        }
        out.state = state;
        return out;
    }

    if (NEEDS_LOCATOR.has(type)) {
        out.locator = validateLocator(action.locator, index);
    }

    if (NEEDS_VALUE.has(type)) {
        if (typeof action.value !== "string" || action.value === "") {
            throw new RecipeError(`Action ${index} (${type}) needs a non-empty value.`, index);
        }
        out.value = action.value;
    }

    if (action.timeoutMs !== undefined) {
        const timeout = action.timeoutMs;
        if (!Number.isInteger(timeout) || timeout < 100 || timeout > 120000) {
            throw new RecipeError(`Action ${index} timeoutMs must be between 100 and 120000.`, index);
        }
        out.timeoutMs = timeout;
    }

    return out;
}

function validateLocator(locator, index) {
    if (typeof locator !== "object" || locator === null || Array.isArray(locator)) {
        throw new RecipeError(`Action ${index} needs a locator object.`, index);
    }

    const { strategy } = locator;

    if (!LOCATOR_STRATEGIES.includes(strategy)) {
        throw new RecipeError(`Action ${index} has unsupported locator strategy "${strategy}".`, index);
    }

    const key = strategy === "role" ? "role" : strategy;
    const value = locator[key];

    if (typeof value !== "string" || value.trim() === "") {
        throw new RecipeError(`Action ${index} locator needs a non-empty "${key}".`, index);
    }

    const out = { strategy, [key]: value };

    if (strategy === "role" && typeof locator.name === "string") {
        out.name = locator.name;
    }

    if (typeof locator.exact === "boolean") {
        out.exact = locator.exact;
    }

    if (Number.isInteger(locator.nth) && locator.nth >= 0) {
        out.nth = locator.nth;
    }

    return out;
}

/** Human-readable locator description for a failure message. */
export function describeLocator(locator) {
    if (!locator) return "page";

    switch (locator.strategy) {
        case "role":
            return locator.name
                ? `role=${locator.role} named "${locator.name}"`
                : `role=${locator.role}`;
        case "label":
            return `label "${locator.label}"`;
        case "text":
            return `text "${locator.text}"`;
        case "placeholder":
            return `placeholder "${locator.placeholder}"`;
        case "testId":
            return `test id "${locator.testId}"`;
        case "css":
            return `css "${locator.css}"`;
        default:
            return "element";
    }
}

/** A short label for a step, used in "Step 5/8 failed: …" messages. */
export function describeAction(action) {
    switch (action?.type) {
        case "goto":
            return `Navigate to ${action.url}`;
        case "click":
            return `Click ${describeLocator(action.locator)}`;
        case "fill":
            return `Fill ${describeLocator(action.locator)}`;
        case "selectOption":
            return `Select "${action.value}" in ${describeLocator(action.locator)}`;
        case "press":
            return `Press "${action.value}" on ${describeLocator(action.locator)}`;
        case "waitForURL":
            return `Wait for URL ${action.url}`;
        case "waitForLoadState":
            return `Wait for load state "${action.state}"`;
        case "waitForVisible":
            return `Wait for ${describeLocator(action.locator)} to be visible`;
        case "download":
            return `Download via ${describeLocator(action.locator)}`;
        default:
            return "Unknown step";
    }
}
