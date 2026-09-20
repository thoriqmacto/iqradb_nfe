"use client";

import { useState } from "react";
import { ChevronDown, ChevronRight, Layers } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import type { DiagnosticFrame, FailureDiagnostics as Diagnostics, RecipeAction } from "@/lib/scrapper/types";

type Props = {
    diagnostics: Diagnostics;
    /** The step that timed out, so its role can be called out by name. */
    failedAction: RecipeAction | null;
};

/**
 * Render what the page contained when a locator found nothing.
 *
 * The point is to turn a timeout into a next action, so the panel leads with
 * the verdict — postback race, wrong role, or content inside an iframe — and
 * only then lists the raw inventory.
 */
export function FailureDiagnostics({ diagnostics, failedAction }: Props) {
    const [open, setOpen] = useState(false);

    const frames = diagnostics.frames ?? [];
    const wantedRole =
        failedAction?.locator?.strategy === "role" ? failedAction.locator.role : undefined;

    return (
        <div className="flex flex-col gap-2 rounded-md border bg-background p-3 text-sm">
            <button
                type="button"
                className="flex items-center gap-2 text-left font-medium"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
            >
                {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                What the page contained
                <Badge variant="outline" className="font-normal">
                    {frames.length === 1 ? "1 frame" : `${frames.length} frames`}
                </Badge>
            </button>

            <Verdict diagnostics={diagnostics} wantedRole={wantedRole} />

            {open && (
                <div className="flex flex-col gap-3">
                    {diagnostics.title && (
                        <p className="text-xs text-muted-foreground">
                            <span className="font-medium">{diagnostics.title}</span>
                            {diagnostics.url && <> — <code className="font-mono">{diagnostics.url}</code></>}
                        </p>
                    )}
                    {frames.map((frame, index) => (
                        <FrameReport
                            key={`${frame.url}-${index}`}
                            frame={frame}
                            index={index}
                            wantedRole={wantedRole}
                        />
                    ))}
                    {frames.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            The page was already gone when the inventory ran — nothing to report.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

/** One-line reading of the inventory, so the user does not have to interpret it. */
function Verdict({ diagnostics, wantedRole }: { diagnostics: Diagnostics; wantedRole?: string }) {
    const frames = diagnostics.frames ?? [];
    const probe = diagnostics.filterText;

    if (frames.length === 0) return null;

    // The filter-text probe answers the question outright when there is one:
    // `.filter({ hasText })` and getByText match identically, so "present but
    // the filter failed" and "not on the page" are different bugs.
    if (probe) {
        if (!probe.foundAnywhere) {
            return (
                <Hint>
                    <strong>
                        &ldquo;{probe.text}&rdquo; was nowhere on the page — not in any frame, under
                        any role.
                    </strong>{" "}
                    So this is not a locator problem. Either the search that produces this row had
                    not run or had not finished rendering when the step fired, or the text differs
                    from what was recorded. Add a wait before this step, then check the row text in
                    the hyperlink list below for the exact wording.
                </Hint>
            );
        }

        const where = probe.matches
            .filter((match) => match.count > 0)
            .map((match) => (match.frame === 0 ? "the main frame" : `frame ${match.frame}`))
            .join(" and ");

        return (
            <Hint>
                <strong>&ldquo;{probe.text}&rdquo; is on the page</strong> ({where}), but not inside
                an element this step could match
                {wantedRole && (
                    <>
                        {" "}
                        — nothing with <code className="font-mono">role={wantedRole}</code> contains
                        it
                    </>
                )}
                . Target the row or its link directly instead: find it in the hyperlink list below
                and use a <code className="font-mono">css</code> selector on its{" "}
                <code className="font-mono">href</code> or <code className="font-mono">id</code>.
            </Hint>
        );
    }

    const main = frames[0];
    const others = frames.slice(1);

    if (wantedRole) {
        const inMain = main.roles[wantedRole]?.count ?? 0;
        const elsewhere = others.find((frame) => (frame.roles[wantedRole]?.count ?? 0) > 0);

        if (inMain === 0 && elsewhere) {
            return (
                <Hint>
                    <code className="font-mono">role={wantedRole}</code> exists, but inside a nested
                    frame (<code className="font-mono">{elsewhere.url}</code>). A recipe locator only
                    searches the top frame, so this step can never match. Target the row by a{" "}
                    <code className="font-mono">css</code> selector on a link instead, or start the
                    recipe at the frame&apos;s own URL.
                </Hint>
            );
        }

        if (inMain === 0) {
            const present = Object.keys(main.roles).filter((role) => role !== wantedRole);
            return (
                <Hint>
                    No <code className="font-mono">role={wantedRole}</code> anywhere on the page. It
                    exposed {present.length > 0 ? present.join(", ") : "no recognisable roles"}. Either
                    the results had not rendered yet — add a wait before this step or raise its timeout
                    — or the control renders with a different role than it did while recording.
                </Hint>
            );
        }

        return (
            <Hint>
                Found {inMain} <code className="font-mono">role={wantedRole}</code> element(s), so the
                role is right. Compare the samples below with what this step is looking for.
            </Hint>
        );
    }

    if (frames.length > 1) {
        return (
            <Hint>
                The page has {frames.length} frames. A recipe locator only searches the top one — if
                the target lives in a nested frame it will never be found.
            </Hint>
        );
    }

    return null;
}

function Hint({ children }: { children: React.ReactNode }) {
    return <p className="rounded-md bg-muted px-2 py-1.5 text-xs leading-relaxed">{children}</p>;
}

function FrameReport({
    frame,
    index,
    wantedRole,
}: {
    frame: DiagnosticFrame;
    index: number;
    wantedRole?: string;
}) {
    const [showLinks, setShowLinks] = useState(false);
    const roles = Object.entries(frame.roles);
    const links = frame.links ?? [];

    return (
        <div className="flex flex-col gap-2 rounded-md border px-2 py-2">
            <p className="flex flex-wrap items-center gap-2 text-xs">
                <Layers className="size-3.5 shrink-0 text-muted-foreground" />
                <span className="font-medium">{index === 0 ? "Main frame" : `Frame ${index}`}</span>
                {frame.name && <Badge variant="outline">{frame.name}</Badge>}
                <code className="min-w-0 truncate font-mono text-muted-foreground">{frame.url}</code>
            </p>

            {roles.length === 0 ? (
                <p className="text-xs text-muted-foreground">No recognisable roles in this frame.</p>
            ) : (
                <ul className="flex flex-col gap-1">
                    {roles.map(([role, info]) => (
                        <li key={role} className="text-xs">
                            <span
                                className={`font-mono ${role === wantedRole ? "font-semibold text-destructive" : ""}`}
                            >
                                role={role}
                            </span>{" "}
                            <span className="text-muted-foreground">× {info.count}</span>
                            {info.samples.length > 0 && (
                                <span className="text-muted-foreground">
                                    {" "}
                                    — {info.samples.map((sample) => `“${sample}”`).join(", ")}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {links.length > 0 && (
                <div className="flex flex-col gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-6 w-fit px-1.5 text-xs"
                        onClick={() => setShowLinks((value) => !value)}
                    >
                        {showLinks ? "Hide" : "Show"} {frame.linkCount ?? links.length} hyperlink(s)
                    </Button>
                    {showLinks && (
                        <ul className="flex flex-col gap-1 font-mono text-[11px] leading-tight">
                            {links.map((link, i) => (
                                <li key={i} className="min-w-0">
                                    <p className="truncate">
                                        {link.id && (
                                            <span className="text-muted-foreground">#{link.id} </span>
                                        )}
                                        <span>
                                            {link.text || link.title || link.label || "(no text)"}
                                        </span>
                                        {link.href && (
                                            <span className="text-muted-foreground">
                                                {" "}
                                                → {link.href}
                                            </span>
                                        )}
                                    </p>
                                    {link.row && (
                                        <p className="truncate pl-3 text-muted-foreground">
                                            in row: {link.row}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
