<?php

namespace App\Models;

use Database\Factories\ScraperSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's stored SCDB browser authentication state.
 *
 * SECURITY: `storage_state` is a Playwright storageState blob — SCDB session
 * cookies. It is encrypted at rest by the cast below, listed in `$hidden` so it
 * cannot leak through model serialisation, and deliberately has no accessor on
 * any API resource. Nothing in this application may return it to a client.
 */
class ScraperSession extends Model
{
    /** @use HasFactory<ScraperSessionFactory> */
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'host',
        'storage_state',
        'status',
        'last_validated_at',
        'last_validation_error',
    ];

    /**
     * Belt and braces: even if someone calls ->toArray() on this model, the
     * cookies do not come out.
     *
     * @var list<string>
     */
    protected $hidden = [
        'storage_state',
    ];

    protected function casts(): array
    {
        return [
            // Laravel encrypts with APP_KEY on write, decrypts on read.
            'storage_state' => 'encrypted:array',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_VALID, self::STATUS_UNKNOWN], true);
    }
}
