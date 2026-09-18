"use client";

import { useRef, useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import { ShieldAlert, ShieldCheck, Upload } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    SESSION_KEY,
    deleteSession,
    fetchSession,
    readError,
    uploadSession,
    validateSession,
} from "@/lib/scrapper/api";
import type { ScrapperSession } from "@/lib/scrapper/types";

import { formatTimestamp, sessionStatusLabel, sessionStatusVariant } from "./status";

const CODEGEN_COMMAND =
    'npx playwright codegen --save-storage=scdb-auth.json "https://chiyodanfe.ceccms.com/Login.aspx?referrer"';

export function SessionCard() {
    const { data, isLoading, mutate } = useSWR<{ session: ScrapperSession | null }>(
        SESSION_KEY,
        fetchSession,
    );
    const [busy, setBusy] = useState<null | "upload" | "validate" | "remove">(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const session = data?.session ?? null;

    async function onFile(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        if (!file) return;

        setBusy("upload");
        try {
            // Read the file in the browser and POST its text. It is never
            // rendered, logged, or kept in component state afterwards.
            const text = await file.text();
            await uploadSession(text);
            await mutate();
            toast.success("Authentication state stored. Validate it to confirm SCDB accepts it.");
        } catch (err) {
            toast.error(readError(err, "Could not store that authentication file."));
        } finally {
            setBusy(null);
            if (fileInput.current) fileInput.current.value = "";
        }
    }

    async function onValidate() {
        setBusy("validate");
        try {
            const result = await validateSession();
            await mutate();
            if (result.result === "valid") {
                toast.success("SCDB accepted the stored session.");
            } else {
                toast.warning(result.message ?? "The stored session is no longer valid.");
            }
        } catch (err) {
            toast.error(readError(err, "Could not validate the session."));
        } finally {
            setBusy(null);
        }
    }

    async function onRemove() {
        setBusy("remove");
        try {
            await deleteSession();
            await mutate();
            toast.success("Authentication state removed.");
        } catch (err) {
            toast.error(readError(err, "Could not remove the session."));
        } finally {
            setBusy(null);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {session?.status === "valid" ? (
                        <ShieldCheck className="size-4 text-emerald-600" />
                    ) : (
                        <ShieldAlert className="size-4 text-muted-foreground" />
                    )}
                    SCDB Session
                </CardTitle>
                <CardDescription>
                    Scrapper drives SCDB with a browser session you authorise yourself. There is no
                    API access to this system.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            {isLoading ? (
                                <span className="text-muted-foreground">Loading…</span>
                            ) : session ? (
                                <Badge variant={sessionStatusVariant(session.status)}>
                                    {sessionStatusLabel(session.status)}
                                </Badge>
                            ) : (
                                <Badge variant="secondary">Not configured</Badge>
                            )}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="shrink-0 text-muted-foreground">SCDB host</dt>
                        <dd className="min-w-0 truncate font-mono text-xs">
                            {session?.host ?? "chiyodanfe.ceccms.com"}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="text-muted-foreground">Last validated</dt>
                        <dd>{formatTimestamp(session?.last_validated_at ?? null)}</dd>
                    </div>
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="shrink-0 text-muted-foreground">Last result</dt>
                        <dd className="min-w-0 truncate text-right">
                            {session?.last_validation_error ?? "—"}
                        </dd>
                    </div>
                </dl>

                {session?.status === "expired" && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                        Session expired — SCDB redirected to the login page. Record a fresh
                        authentication state and upload it again.
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    <input
                        ref={fileInput}
                        type="file"
                        accept="application/json,.json"
                        className="hidden"
                        onChange={onFile}
                    />
                    <Button
                        size="sm"
                        onClick={() => fileInput.current?.click()}
                        disabled={busy !== null}
                    >
                        <Upload className="size-4" />
                        {session ? "Replace authentication state" : "Upload authentication state"}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onValidate}
                        disabled={busy !== null || !session}
                    >
                        {busy === "validate" ? "Validating…" : "Validate session"}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onRemove}
                        disabled={busy !== null || !session}
                    >
                        Remove session
                    </Button>
                </div>

                <details className="rounded-md border bg-muted/30 px-3 py-2 text-sm">
                    <summary className="cursor-pointer font-medium">
                        How to create an authentication state
                    </summary>
                    <ol className="mt-3 flex list-decimal flex-col gap-2 pl-4 text-muted-foreground">
                        <li>
                            On a trusted machine, run:
                            <pre className="mt-1 overflow-x-auto rounded bg-background p-2 font-mono text-xs text-foreground">
                                {CODEGEN_COMMAND}
                            </pre>
                        </li>
                        <li>Sign in to SCDB in the browser window that opens.</li>
                        <li>Close the browser. Playwright writes <code>scdb-auth.json</code>.</li>
                        <li>Upload that file here.</li>
                    </ol>
                    <p className="mt-3 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-destructive">
                        <strong>scdb-auth.json contains authentication credentials and session
                        cookies.</strong> Never commit it to Git, attach it to a ticket, or share it.
                        Delete your local copy once it is uploaded. IqraDB stores it encrypted and
                        never returns it — not to this page, not to any API response, not to logs.
                    </p>
                </details>
            </CardContent>
        </Card>
    );
}
