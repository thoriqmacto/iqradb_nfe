<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'dataset_key',
        'status',
        'source_filename',
        'checksum',
        'headers',
        'mapping_version',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'inserted',
        'updated',
        'unchanged',
        'rejected',
        'error_message',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'headers' => 'array',
            'imported_at' => 'datetime',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScraperRun::class, 'scraper_run_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ScraperRecipe::class, 'scraper_recipe_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
