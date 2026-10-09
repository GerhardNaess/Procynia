<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Excel file uploaded to «Importer leverandører» — never the file, only the cell values the
 * import understands, and after it is carried out, what happened (see the migration).
 *
 * Nothing is mass assignable: SupplierImportService is the only writer. Customer-owned; reach it only
 * through SupplierImportService, which scopes every lookup to the person's own customer.
 */
class SupplierImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'ignored_columns' => 'array',
            'result' => 'array',
            'update_existing' => 'boolean',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
