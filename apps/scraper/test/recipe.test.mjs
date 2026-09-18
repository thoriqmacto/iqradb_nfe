import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { describeAction, RecipeError, validateRecipe } from "../src/recipe.mjs";
import { UnsafeUrlError } from "../src/urls.mjs";

const HOSTS = ["chiyodanfe.ceccms.com"];
const opts = { allowedHosts: HOSTS };

describe("validateRecipe", () => {
    it("accepts the supported action vocabulary", () => {
        const actions = [
            { type: "goto", url: "https://chiyodanfe.ceccms.com/Reports.aspx" },
            { type: "click", locator: { strategy: "role", role: "link", name: "Reports" } },
            { type: "fill", locator: { strategy: "label", label: "Search" }, value: "L-001" },
            { type: "selectOption", locator: { strategy: "label", label: "Train" }, value: "Train-8" },
            { type: "press", locator: { strategy: "css", css: "#q" }, value: "Enter" },
            { type: "waitForLoadState", state: "networkidle" },
            { type: "waitForURL", url: "**/Reports.aspx*" },
            { type: "waitForVisible", locator: { strategy: "text", text: "Loop Index" } },
            { type: "download", locator: { strategy: "role", role: "button", name: "Export" } },
        ];

        assert.equal(validateRecipe(actions, opts).length, actions.length);
    });

    it("rejects an unsupported action type", () => {
        assert.throws(
            () => validateRecipe([{ type: "evaluate", script: "alert(1)" }], opts),
            RecipeError,
        );
    });

    it("rejects an unsupported locator strategy", () => {
        assert.throws(
            () => validateRecipe([{ type: "click", locator: { strategy: "xpath", xpath: "//a" } }], opts),
            RecipeError,
        );
    });

    it("rejects a goto pointing off the allowlist", () => {
        assert.throws(
            () => validateRecipe([{ type: "goto", url: "https://example.com/" }], opts),
            UnsafeUrlError,
        );
    });

    it("rejects an absolute waitForURL pointing off the allowlist", () => {
        assert.throws(
            () => validateRecipe([{ type: "waitForURL", url: "https://example.com/*" }], opts),
            UnsafeUrlError,
        );
    });

    it("allows a relative waitForURL glob", () => {
        assert.equal(validateRecipe([{ type: "waitForURL", url: "**/Export.aspx" }], opts).length, 1);
    });

    it("requires a locator for element actions", () => {
        assert.throws(() => validateRecipe([{ type: "click" }], opts), RecipeError);
    });

    it("requires a value for fill, press and selectOption", () => {
        for (const type of ["fill", "press", "selectOption"]) {
            assert.throws(
                () => validateRecipe([{ type, locator: { strategy: "css", css: "#x" } }], opts),
                RecipeError,
                `${type} should require a value`,
            );
        }
    });

    it("rejects an empty recipe and one over the action cap", () => {
        assert.throws(() => validateRecipe([], opts), RecipeError);

        const many = Array.from({ length: 61 }, () => ({
            type: "click",
            locator: { strategy: "css", css: "#x" },
        }));
        assert.throws(() => validateRecipe(many, opts), RecipeError);
    });

    it("rejects an unsupported load state", () => {
        assert.throws(
            () => validateRecipe([{ type: "waitForLoadState", state: "idle" }], opts),
            RecipeError,
        );
    });

    it("strips keys that are not part of the schema", () => {
        const [action] = validateRecipe(
            [
                {
                    type: "click",
                    locator: { strategy: "css", css: "#x", onclick: "alert(1)" },
                    script: "rm -rf /",
                },
            ],
            opts,
        );

        assert.deepEqual(Object.keys(action), ["type", "locator"]);
        assert.deepEqual(Object.keys(action.locator), ["strategy", "css"]);
    });

    it("validates timeout bounds", () => {
        assert.throws(
            () => validateRecipe([{ type: "click", locator: { strategy: "css", css: "#x" }, timeoutMs: 1 }], opts),
            RecipeError,
        );
    });
});

describe("describeAction", () => {
    it("produces a human-readable step label", () => {
        assert.equal(
            describeAction({ type: "download", locator: { strategy: "role", role: "button", name: "Export" } }),
            'Download via role=button named "Export"',
        );
    });
});
