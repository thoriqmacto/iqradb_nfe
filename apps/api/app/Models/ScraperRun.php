<?php

namespace App\Models;

use App\Enums\ScraperRunMode;
use App\Enums\ScraperRunStatus;
use Database\Factories\ScraperRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class ScraperRun extends Model
{
    /** @use HasFactory<ScraperRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'mode',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'downloaded_filename',
        'artifact_path',
        'checksum',
        'file_size',
        'error_code',
        'error_message',
        'failed_step_index',
        'failed_action',
        'final_url',
        'screenshot_path',
        'trace_path',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ScraperRunMode::class,
            'status' => ScraperRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_action' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ScraperRecipe::class, 'scraper_recipe_id');
    }

    public function importBatch(): HasOne
    {
        return $this->hasOne(ImportBatch::class);
    }

    /**
     * Move the run forward, refusing transitions the state machine disallows.
     *
     * @param  array<string, mixed>  $attributes  extra columns to persist with the move
     *
     * @throws RuntimeException on an illegal transition
     */
    public function transitionTo(ScraperRunStatus $next, array $attributes = []): void
    {
        $current = $this->status;

        if ($current === $next) {
            if ($attributes !== []) {
                $this->forceFill($attributes)->save();
            }

            return;
        }

        if (! $current->canTransitionTo($next)) {
            throw new RuntimeException(
                "Illegal scraper run transition: {$current->value} -> {$next->value}."
            );
        }

        if ($next->isTerminal()) {
            $attributes['finished_at'] ??= now();

            if ($this->started_at && ! isset($attributes['duration_ms'])) {
                $attributes['duration_ms'] = max(
                    0,
                    (int) (now()->getPreciseTimestamp(3) - $this->started_at->getPreciseTimestamp(3))
                );
            }
        }

        $this->forceFill(['status' => $next, ...$attributes])->save();
    }
}
