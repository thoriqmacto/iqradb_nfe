"use client";

import { useState } from "react";

import { SHELL_X } from "@/lib/nav";
import { cn } from "@/lib/utils";

import { RecipeList } from "./RecipeList";
import { RunHistory } from "./RunHistory";
import { SessionCard } from "./SessionCard";

export default function ScrapperClient() {
    // Set when a run is queued so the history opens straight onto it.
    const [focusRunId, setFocusRunId] = useState<string | null>(null);

    return (
        <section className={cn("flex flex-col gap-4 py-6", SHELL_X)}>
            <div className="flex flex-col gap-1">
                <h1 className="text-lg font-semibold tracking-tight">Scrapper</h1>
                <p className="max-w-3xl text-sm text-muted-foreground">
                    Automates retrieving CSV reports from SCDB. Browser automation runs on the
                    application server, not in this page — start a run here and watch its progress
                    below.
                </p>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <SessionCard />
                <RecipeList onRunStarted={setFocusRunId} />
            </div>

            <RunHistory focusRunId={focusRunId} />
        </section>
    );
}
