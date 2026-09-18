<?php

namespace App\Enums;

/**
 * The scraper run state machine, in one place.
 *
 * Statuses are never written as bare strings elsewhere in the codebase; every
 * transition goes through `transitionTo()` so an illegal jump (say, `queued`
 * straight to `completed`) fails loudly instead of corrupting run history.
 */
enum ScraperRunStatus: string
{
    case Queued = 'queued';
    case StartingBrowser = 'starting_browser';
    case ValidatingSession = 'validating_session';
    case Navigating = 'navigating';
    case WaitingForReport = 'waiting_for_report';
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Parsing = 'parsing';
    case Validating = 'validating';
    case Staged = 'staged';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';
    case SessionExpired = 'session_expired';
    case Cancelled = 'cancelled';

    /** Statuses from which no further transition is allowed. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::SessionExpired,
            self::Cancelled,
        ], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Failed, self::SessionExpired, self::Cancelled], true);
    }

    /**
     * Statuses reachable from this one.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        // Any non-terminal status may end in one of these.
        $abort = [self::Failed, self::SessionExpired, self::Cancelled];

        return match ($this) {
            self::Queued => [self::StartingBrowser, ...$abort],
            self::StartingBrowser => [self::ValidatingSession, ...$abort],
            self::ValidatingSession => [self::Navigating, ...$abort],
            self::Navigating => [self::WaitingForReport, self::Downloading, self::Completed, ...$abort],
            self::WaitingForReport => [self::Downloading, ...$abort],
            self::Downloading => [self::Downloaded, ...$abort],
            // A download-only run completes here; an import run carries on.
            self::Downloaded => [self::Parsing, self::Completed, ...$abort],
            self::Parsing => [self::Validating, ...$abort],
            self::Validating => [self::Staged, ...$abort],
            // Staged is terminal-ish for datasets with no adapter registered.
            self::Staged => [self::Importing, self::Completed, ...$abort],
            self::Importing => [self::Completed, ...$abort],
            default => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Human-facing phase label for the run history table. */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::StartingBrowser => 'Starting browser',
            self::ValidatingSession => 'Validating session',
            self::Navigating => 'Navigating',
            self::WaitingForReport => 'Waiting for report',
            self::Downloading => 'Downloading',
            self::Downloaded => 'Downloaded',
            self::Parsing => 'Parsing CSV',
            self::Validating => 'Validating rows',
            self::Staged => 'Staged',
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::SessionExpired => 'Session expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
