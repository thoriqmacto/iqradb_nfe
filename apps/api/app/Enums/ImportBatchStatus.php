<?php

namespace App\Enums;

/** Lifecycle of a CSV import batch. */
enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Parsing = 'parsing';
    case Staged = 'staged';

    /** Rows are staged but no target adapter is registered for the dataset. */
    case ReadyForMapping = 'ready_for_mapping';

    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** An identical file (same checksum + dataset) was already imported. */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::ReadyForMapping,
            self::Completed,
            self::Failed,
            self::Skipped,
        ], true);
    }
}
