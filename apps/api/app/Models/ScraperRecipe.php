<?php

namespace App\Models;

use Database\Factories\ScraperRecipeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "What report to fetch, and which clicks get us there."
 *
 * A recipe deliberately knows nothing about IqraDB tables — `dataset_key` is
 * the only link, and an import adapter decides what that key means. That
 * separation is what lets SCDB reports be re-recorded without touching the
 * import side, and vice versa.
 */
class ScraperRecipe extends Model
{
    /** @use HasFactory<ScraperRecipeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'description',
        'start_url',
        'dataset_key',
        'enabled',
        'expected_file_type',
        'expected_filename_pattern',
        'schema_version',
        'actions',
        'import_config',
        'codegen_source',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'actions' => 'array',
            'import_config' => 'array',
            'schema_version' => 'integer',
            'last_success_run_at' => 'datetime',
            'last_failed_run_at' => 'datetime',
        ];
    }

    /** Only `uuid` is a generated UUID; the primary key stays an auto-increment id. */
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

    public function runs(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }
}
