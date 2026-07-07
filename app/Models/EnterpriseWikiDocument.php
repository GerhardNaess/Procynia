<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiDocument extends Model
{
    public const DOCUMENT_STATUS_PENDING = 'pending';

    public const DOCUMENT_STATUS_EXTRACTED = 'extracted';

    public const DOCUMENT_STATUS_FAILED = 'failed';

    public const DOCUMENT_STATUSES = [
        self::DOCUMENT_STATUS_PENDING,
        self::DOCUMENT_STATUS_EXTRACTED,
        self::DOCUMENT_STATUS_FAILED,
    ];

    protected $fillable = [
        'customer_id',
        'uploaded_by_user_id',
        'original_filename',
        'file_path',
        'file_hash_sha256',
        'extracted_text',
        'document_status',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
