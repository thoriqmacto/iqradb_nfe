import type { RunStatus, SessionStatus } from "@/lib/scrapper/types";

type BadgeVariant = "default" | "secondary" | "destructive" | "outline" | "success" | "warning" | "info";

/** Keeps status colouring in one place rather than scattered across components. */
export function runStatusVariant(status: RunStatus): BadgeVariant {
    switch (status) {
        case "completed":
            return "success";
        case "failed":
        case "cancelled":
            return "destructive";
        case "session_expired":
            return "warning";
        case "queued":
            return "secondary";
        default:
            return "info";
    }
}

export function sessionStatusVariant(status: SessionStatus): BadgeVariant {
    switch (status) {
        case "valid":
            return "success";
        case "expired":
            return "warning";
        case "invalid":
        case "error":
            return "destructive";
        default:
            return "secondary";
    }
}

export function sessionStatusLabel(status: SessionStatus): string {
    switch (status) {
        case "valid":
            return "Connected";
        case "expired":
            return "Expired";
        case "invalid":
            return "Invalid";
        case "error":
            return "Validation failed";
        default:
            return "Not validated";
    }
}

export function formatDuration(ms: number | null): string {
    if (ms === null) return "—";
    if (ms < 1000) return `${ms}ms`;
    const seconds = ms / 1000;
    if (seconds < 60) return `${seconds.toFixed(1)}s`;
    const minutes = Math.floor(seconds / 60);
    return `${minutes}m ${Math.round(seconds % 60)}s`;
}

export function formatTimestamp(value: string | null): string {
    if (!value) return "—";
    return new Date(value).toLocaleString();
}

export function formatBytes(bytes: number | null): string {
    if (bytes === null) return "—";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
