"use client";

import { useState } from "react";
import useSWR from "swr";

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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { RUNS_KEY, fetchRuns } from "@/lib/scrapper/api";
import type { Run } from "@/lib/scrapper/types";

import { RunDetail } from "./RunDetail";
import { formatDuration, formatTimestamp, runStatusVariant } from "./status";

/**
 * Poll while anything is in flight, stop when everything has settled.
 * A finished list does not need a request every two seconds.
 */
function refreshInterval(runs: Run[] | undefined): number {
    if (!runs) return 0;
    return runs.some((run) => !run.is_terminal) ? 2000 : 0;
}

export function RunHistory({ focusRunId }: { focusRunId: string | null }) {
    const { data: runs, isLoading } = useSWR<Run[]>(RUNS_KEY, fetchRuns, {
        refreshInterval,
    });
    const [openRunId, setOpenRunId] = useState<string | null>(null);

    const selected = openRunId ?? focusRunId;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Run History</CardTitle>
                <CardDescription>
                    Progress updates while a run is in flight. Import counts appear once a CSV has
                    been staged.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {isLoading && <p className="text-sm text-muted-foreground">Loading runs…</p>}

                {!isLoading && (runs?.length ?? 0) === 0 && (
                    <p className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
                        No runs yet.
                    </p>
                )}

                {(runs?.length ?? 0) > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Run</TableHead>
                                <TableHead>Recipe</TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>File</TableHead>
                                <TableHead className="text-right">Rows</TableHead>
                                <TableHead className="text-right">Ins</TableHead>
                                <TableHead className="text-right">Upd</TableHead>
                                <TableHead className="text-right">Unch</TableHead>
                                <TableHead className="text-right">Rej</TableHead>
                                <TableHead className="text-right">Duration</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs?.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell className="font-mono text-xs">
                                        {run.id.slice(0, 8)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {run.recipe.name ?? "—"}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-xs">
                                        {formatTimestamp(run.started_at ?? run.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={runStatusVariant(run.status)}>
                                            {run.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="max-w-40 truncate font-mono text-xs">
                                        {run.downloaded_filename ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.import?.total_rows ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.import?.inserted ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.import?.updated ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.import?.unchanged ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.import?.rejected ?? "—"}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums text-xs">
                                        {formatDuration(run.duration_ms)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 text-xs"
                                            onClick={() =>
                                                setOpenRunId(selected === run.id ? null : run.id)
                                            }
                                        >
                                            {selected === run.id ? "Hide" : "View"}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                {selected && <RunDetail runId={selected} />}
            </CardContent>
        </Card>
    );
}
