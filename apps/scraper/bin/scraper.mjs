#!/usr/bin/env node
/**
 * SCDB scraper worker.
 *
 * Reads one JSON command from stdin, does the work, prints one JSON envelope to
 * stdout, exits. Invoked by Laravel's `scraper` queue jobs on the VPS.
 *
 * Why stdin rather than argv: the payload carries the decrypted SCDB storage
 * state. Command-line arguments are readable by any user on the box via `ps`.
 *
 * Nothing in this process ever evaluates caller-supplied code. Recipes are
 * data, validated against a closed schema in src/recipe.mjs before a browser
 * is launched.
 */
import { join } from "node:path";

import { emit, failure, success } from "../src/envelope.mjs";
import { runRecipe, SessionExpiredError, StepError } from "../src/runner.mjs";
import { validateSession } from "../src/session.mjs";
import { RecipeError } from "../src/recipe.mjs";
import { UnsafeUrlError } from "../src/urls.mjs";

async function readStdin() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }
    return Buffer.concat(chunks).toString("utf8");
}

/**
 * Best-effort failure artifacts. Never throws — a screenshot that fails to
 * save must not replace the real error with a confusing one.
 */
async function captureDiagnostics(error, outputDirectory) {
    const page = error?.page;
    if (!page || !outputDirectory) return {};

    try {
        await page.screenshot({ path: join(outputDirectory, "failure.png"), fullPage: true });
        return { screenshot: "failure.png" };
    } catch {
        return {};
    }
}

async function main() {
    const raw = await readStdin();

    let input;
    try {
        input = JSON.parse(raw);
    } catch {
        emit(failure("error", "bad_input", "Worker input was not valid JSON."));
        process.exitCode = 2;
        return;
    }

    const { command } = input;

    try {
        if (command === "validate-session") {
            const result = await validateSession(input);
            emit(success("ok", result));
            return;
        }

        if (command === "run-recipe") {
            const result = await runRecipe(input);
            emit(success("ok", result));
            return;
        }

        emit(failure("error", "unknown_command", `Unknown command "${command}".`));
        process.exitCode = 2;
    } catch (error) {
        process.exitCode = 1;

        if (error instanceof SessionExpiredError) {
            emit(
                failure(
                    "session_expired",
                    "session_expired",
                    "SCDB redirected to the login page. The stored authentication state has expired.",
                    { finalUrl: error.finalUrl },
                ),
            );
            return;
        }

        if (error instanceof StepError) {
            const diagnostics = await captureDiagnostics(error, input.outputDirectory);
            emit(
                failure("failed", "step_failed", error.message, {
                    failedStepIndex: error.index,
                    failedAction: error.action,
                    // What the page actually exposed, so a timeout says more
                    // than "not found".
                    pageInventory: error.diagnostics ?? null,
                    ...diagnostics,
                }),
            );
            return;
        }

        if (error instanceof RecipeError) {
            emit(failure("failed", "invalid_recipe", error.message, { failedStepIndex: error.index }));
            return;
        }

        if (error instanceof UnsafeUrlError) {
            emit(failure("failed", "blocked_url", error.message));
            return;
        }

        emit(failure("failed", "worker_error", String(error?.message ?? error)));
    }
}

// Only run when executed directly, so the lint pass can import this module.
if (process.argv[1] && import.meta.url === `file://${process.argv[1]}`) {
    main().catch((error) => {
        emit(failure("error", "unhandled", String(error?.message ?? error)));
        process.exit(1);
    });
}
