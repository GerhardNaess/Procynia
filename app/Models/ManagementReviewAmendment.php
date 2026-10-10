<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A correction registered after a review was finalized («Rettelse»). The original stays as it was;
 * the amendment is shown beside it. Append-only.
 */
class ManagementReviewAmendment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'management_review_id',
        'text',
        'reason',
        'created_by_user_id',
        'created_by_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An amendment is history and cannot be changed. Register a new one.');
        });

        static::deleting(function (): void {
            throw new LogicException('An amendment is history and cannot be deleted.');
        });
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }
}
