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

/** Whole days from now until an ISO timestamp; negative once it has passed. */
export function daysUntil(value: string | null): number | null {
    if (!value) return null;
    const target = new Date(value).getTime();
    if (Number.isNaN(target)) return null;
    return Math.floor((target - Date.now()) / 86_400_000);
}

/** Renew-by urgency for the stored authentication file. */
export type ExpiryUrgency = "unknown" | "gone" | "soon" | "ok";

/** Fewer than this many days left is worth warning about. */
export const RENEW_WARNING_DAYS = 7;

export function expiryUrgency(expiresAt: string | null): ExpiryUrgency {
    const days = daysUntil(expiresAt);
    if (days === null) return "unknown";
    if (days < 0) return "gone";
    return days <= RENEW_WARNING_DAYS ? "soon" : "ok";
}

export function formatExpiry(expiresAt: string | null): string {
    const days = daysUntil(expiresAt);
    if (days === null) return "—";

    const when = new Date(expiresAt as string).toLocaleDateString();

    if (days < 0) return `${when} (${Math.abs(days)} day(s) ago)`;
    if (days === 0) return `${when} (today)`;
    return `${when} (in ${days} day(s))`;
}
