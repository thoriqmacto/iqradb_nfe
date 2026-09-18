#!/usr/bin/env node
/**
 * Dependency-free lint pass.
 *
 * Parses every source file with `node --check`. Parsing, not importing: a test
 * file imported here would actually run its tests, and a module with any
 * top-level side effect would execute it. The repo's ESLint config is
 * Next.js-specific and does not apply to this workspace.
 */
import { spawnSync } from "node:child_process";
import { readdir } from "node:fs/promises";
import { join } from "node:path";

const roots = ["bin", "src", "test", "scripts"];
const failures = [];
let checked = 0;

async function* walk(dir) {
    let entries;
    try {
        entries = await readdir(dir, { withFileTypes: true });
    } catch {
        return;
    }
    for (const entry of entries) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) yield* walk(full);
        else if (entry.name.endsWith(".mjs")) yield full;
    }
}

for (const root of roots) {
    for await (const file of walk(root)) {
        const result = spawnSync(process.execPath, ["--check", file], { encoding: "utf8" });
        if (result.status === 0) {
            checked++;
        } else {
            failures.push(`${file}\n${(result.stderr || "").trim()}`);
        }
    }
}

if (failures.length > 0) {
    console.error(`✗ ${failures.length} file(s) failed to parse:\n${failures.join("\n\n")}`);
    process.exit(1);
}

console.log(`✓ ${checked} module(s) parsed cleanly`);
