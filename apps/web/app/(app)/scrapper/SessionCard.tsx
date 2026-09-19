"use client";

import { useRef, useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import { CalendarClock, ShieldAlert, ShieldCheck, Upload } from "lucide-react";

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

import {
    RENEW_WARNING_DAYS,
    expiryUrgency,
    formatExpiry,
    formatTimestamp,
    sessionStatusLabel,
    sessionStatusVariant,
} from "./status";

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
    const expiresAt = session?.cookies?.expires_at ?? null;
    const urgency = expiryUrgency(expiresAt);

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
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="flex shrink-0 items-center gap-1.5 text-muted-foreground">
                            <CalendarClock className="size-3.5" />
                            Renew by
                        </dt>
                        <dd
                            className={
                                urgency === "gone"
                                    ? "font-medium text-destructive"
                                    : urgency === "soon"
                                      ? "font-medium text-amber-700 dark:text-amber-400"
                                      : ""
                            }
                        >
                            {session ? formatExpiry(expiresAt) : "—"}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-2 border-b py-1.5">
                        <dt className="shrink-0 text-muted-foreground">Cookies in file</dt>
                        <dd className="text-right text-xs text-muted-foreground">
                            {session?.cookies
                                ? `${session.cookies.persistent_cookies} dated, ${session.cookies.session_cookies} browser-session`
                                : "—"}
                        </dd>
                    </div>
                </dl>

                {session?.status === "expired" && (
                    <Notice tone="warning">
                        <strong>Session expired.</strong> SCDB redirected to the login page. Record a
                        fresh <code className="font-mono">scdb-auth.json</code> on a trusted device
                        and upload it again — the steps are below.
                    </Notice>
                )}

                {session && session.status !== "expired" && urgency === "gone" && (
                    <Notice tone="destructive">
                        <strong>This authentication file has run out.</strong> Its last dated cookie
                        expired on {formatExpiry(expiresAt)}, so nothing in it can sign in to SCDB any
                        more. Record a fresh one and upload it.
                    </Notice>
                )}

                {session && session.status !== "expired" && urgency === "soon" && (
                    <Notice tone="warning">
                        <strong>Renew soon.</strong> This file stops working{" "}
                        {formatExpiry(expiresAt)} — under {RENEW_WARNING_DAYS} days away. Re-record it
                        before then so scheduled runs do not start failing.
                    </Notice>
                )}

                {session && session.cookies && session.cookies.persistent_cookies === 0 && (
                    <Notice tone="warning">
                        <strong>No dated cookies in this file.</strong> Everything in it dies with the
                        browser that recorded it, so there is nothing to renew against — it will
                        likely stop working as soon as that sign-in lapses. Re-record with the browser
                        closed properly, as step 3 below describes.
                    </Notice>
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
                    <p className="mt-3 text-xs text-muted-foreground">
                        <strong>About &ldquo;Renew by&rdquo;.</strong> It is the last expiry date
                        carried by any cookie in the file — an upper bound, not a guarantee. SCDB or
                        your identity provider can end the session earlier (a password change or a
                        policy update will do it), which is what <em>Validate session</em> checks.
                        The short-lived SCDB cookie is re-minted automatically for as long as the
                        dated sign-in cookies hold, which is why a file recorded days ago still
                        works.
                    </p>
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

/** The card's inline banners, so tone stays consistent between them. */
function Notice({
    tone,
    children,
}: {
    tone: "warning" | "destructive";
    children: React.ReactNode;
}) {
    const className =
        tone === "destructive"
            ? "border-destructive/40 bg-destructive/5 text-destructive"
            : "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200";

    return <p className={`rounded-md border px-3 py-2 text-sm ${className}`}>{children}</p>;
}
