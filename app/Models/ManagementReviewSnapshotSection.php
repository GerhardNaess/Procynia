<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The basis of one section as it stood when the review was finalized — what the management actually
 * had. Never read as the state now, and never shown without ManagementReviewSnapshotReader filtering
 * it to what the reader may see today.
 *
 * state: captured, module_unavailable (the customer did not hold the module), or not_captured (the
 * person who finalized could not read it). coverage records whose visibility it was captured with.
 *
 * Append-only: the model refuses update and delete, and so does the database.
 */
class ManagementReviewSnapshotSection extends Model
{
    public const STATE_CAPTURED = 'captured';

    public const STATE_MODULE_UNAVAILABLE = 'module_unavailable';

    public const STATE_NOT_CAPTURED = 'not_captured';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'management_review_id',
        'section_key',
        'state',
        'source_module',
        'schema_version',
        'coverage',
        'payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'coverage' => 'array',
            'payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A management review snapshot is history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('A management review snapshot is history and cannot be deleted.');
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }
}
