"use client";

import useSWR from "swr";
import { AlertCircle, Camera, FileText } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { RUNS_KEY, fetchPreview, fetchRun } from "@/lib/scrapper/api";
import type { Preview, Run } from "@/lib/scrapper/types";

import { describeAction } from "./ActionList";
import { formatBytes, formatDuration, formatTimestamp, runStatusVariant } from "./status";

export function RunDetail({ runId }: { runId: string }) {
    const { data: run } = useSWR<Run>(`${RUNS_KEY}/${runId}`, () => fetchRun(runId), {
        refreshInterval: (latest) => (latest && !latest.is_terminal ? 2000 : 0),
    });

    // Only ask for a preview once there is something to preview.
    const canPreview = Boolean(run?.downloaded_filename) || Boolean(run?.import);
    const { data: preview } = useSWR<Preview>(
        canPreview ? `${RUNS_KEY}/${runId}/preview` : null,
        () => fetchPreview(runId),
    );

    if (!run) {
        return <p className="text-sm text-muted-foreground">Loading run…</p>;
    }

    return (
        <div className="flex flex-col gap-4 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-sm font-semibold">Run {run.id.slice(0, 8)}</h3>
                <Badge variant={runStatusVariant(run.status)}>{run.status_label}</Badge>
                <Badge variant="outline">{run.mode}</Badge>
            </div>

            <dl className="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <Field label="Recipe" value={run.recipe.name ?? "—"} />
                <Field label="Dataset" value={run.recipe.dataset_key ?? "—"} mono />
                <Field label="Started" value={formatTimestamp(run.started_at)} />
                <Field label="Finished" value={formatTimestamp(run.finished_at)} />
                <Field label="Duration" value={formatDuration(run.duration_ms)} />
                <Field label="File" value={run.downloaded_filename ?? "—"} mono />
                <Field label="Size" value={formatBytes(run.file_size)} />
                <Field
                    label="Checksum"
                    value={run.checksum ? `${run.checksum.slice(0, 16)}…` : "—"}
                    mono
                />
                <Field label="Final URL" value={run.final_url ?? "—"} mono />
            </dl>

            {run.error_message && (
                <div className="flex flex-col gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                    <p className="flex items-center gap-2 font-medium text-destructive">
                        <AlertCircle className="size-4" />
                        {run.error_code === "session_expired" ? "Session expired" : "Run failed"}
                    </p>
                    <p>{run.error_message}</p>
                    {run.failed_action && (
                        <p className="text-muted-foreground">
                            Failing step: <code>{describeAction(run.failed_action)}</code>
                        </p>
                    )}
                    {(run.has_screenshot || run.has_trace) && (
                        <p className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                            {run.has_screenshot && (
                                <span className="flex items-center gap-1">
                                    <Camera className="size-3" /> Screenshot saved on the server
                                </span>
                            )}
                            {run.has_trace && (
                                <span className="flex items-center gap-1">
                                    <FileText className="size-3" /> Playwright trace saved on the
                                    server
                                </span>
                            )}
                        </p>
                    )}
                </div>
            )}

            {run.import && (
                <div className="rounded-md border p-3 text-sm">
                    <p className="mb-2 font-medium">
                        Import — <Badge variant="outline">{run.import.status}</Badge>
                    </p>
                    {run.import.status === "ready_for_mapping" && (
                        <p className="mb-2 text-muted-foreground">
                            Rows are staged. No target model exists for{" "}
                            <code className="font-mono">{run.import.dataset_key}</code> yet, so
                            nothing was written to a domain table.
                        </p>
                    )}
                    {run.import.error_message && (
                        <p className="mb-2 text-destructive">{run.import.error_message}</p>
                    )}
                    <dl className="grid grid-cols-3 gap-x-6 gap-y-1 sm:grid-cols-6">
                        <Field label="Source rows" value={String(run.import.total_rows)} />
                        <Field label="Valid" value={String(run.import.valid_rows)} />
                        <Field label="Inserted" value={String(run.import.inserted)} />
                        <Field label="Updated" value={String(run.import.updated)} />
                        <Field label="Unchanged" value={String(run.import.unchanged)} />
                        <Field label="Rejected" value={String(run.import.rejected)} />
                    </dl>
                </div>
            )}

            {preview && preview.headers.length > 0 && (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <h4 className="text-sm font-medium">CSV preview</h4>
                        <span className="text-xs text-muted-foreground">
                            {preview.headers.length} column(s)
                            {preview.total_rows !== null && `, ${preview.total_rows} row(s)`} —
                            showing first {preview.rows.length}
                        </span>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">#</TableHead>
                                {preview.headers.map((header) => (
                                    <TableHead key={header} className="whitespace-nowrap">
                                        {header}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {preview.rows.map((row) => (
                                <TableRow
                                    key={row.row_number}
                                    className={row.errors.length > 0 ? "bg-destructive/5" : ""}
                                >
                                    <TableCell className="text-xs text-muted-foreground tabular-nums">
                                        {row.row_number}
                                    </TableCell>
                                    {preview.headers.map((header) => (
                                        <TableCell key={header} className="whitespace-nowrap text-xs">
                                            {row.values[header] ?? ""}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    {preview.rows.some((row) => row.errors.length > 0) && (
                        <ul className="flex flex-col gap-1 text-xs text-destructive">
                            {preview.rows
                                .filter((row) => row.errors.length > 0)
                                .map((row) => (
                                    <li key={row.row_number}>
                                        Row {row.row_number}: {row.errors.join(" ")}
                                    </li>
                                ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

function Field({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex justify-between gap-2 border-b py-1 sm:border-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={`truncate text-right ${mono ? "font-mono text-xs" : ""}`}>{value}</dd>
        </div>
    );
}
