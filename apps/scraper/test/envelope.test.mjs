import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { failure, redact, success } from "../src/envelope.mjs";

describe("redact", () => {
    it("blanks secret-shaped keys at any depth", () => {
        const out = redact({ a: { b: { storageState: { cookies: ["secret"] } } } });

        assert.equal(out.a.b.storageState, "[redacted]");
    });

    it("carries the failure inventory through without truncating its samples", () => {
        // This is the exact shape captureFailureDiagnostics builds. Its sample
        // text sits at depth 7, which an earlier limit of 6 replaced with
        // "[truncated]" — losing the only field that said what the page held.
        const envelope = failure("failed", "step_failed", "timed out", {
            pageInventory: {
                frames: [
                    {
                        url: "https://chiyodanfe.ceccms.com/ISC/tools/vExports/index.htm",
                        roles: {
                            grid: { count: 4, samples: ["COMP_RPT_Redline markup"] },
                        },
                        links: [{ text: "", href: "/GetBlob.aspx?ExportID=1549", row: "Redline" }],
                    },
                ],
            },
        });

        assert.equal(
            envelope.pageInventory.frames[0].roles.grid.samples[0],
            "COMP_RPT_Redline markup",
        );
        assert.equal(envelope.pageInventory.frames[0].links[0].row, "Redline");
    });

    it("still cuts off a structure deeper than anything the worker builds", () => {
        let deep = "bottom";
        for (let i = 0; i < 20; i++) deep = { deep };

        assert.ok(JSON.stringify(redact(deep)).includes("[truncated]"));
    });

    it("does not let a self-referential error object loop forever", () => {
        const cyclic = { name: "Error" };
        cyclic.self = cyclic;

        // Terminates and is serialisable — that is the whole requirement.
        assert.ok(JSON.stringify(redact(cyclic)).length > 0);
    });

    it("caps a long array rather than printing all of it", () => {
        const out = redact(Array.from({ length: 500 }, (_, i) => i));

        assert.equal(out.length, 100);
    });

    it("keeps success envelopes intact", () => {
        const out = success("completed", { filename: "report.xlsx", size: 12 });

        assert.equal(out.ok, true);
        assert.equal(out.data.filename, "report.xlsx");
    });
});
