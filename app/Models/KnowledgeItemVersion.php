<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItemVersion extends Model
{
    protected $fillable = [
        'knowledge_item_id',
        'customer_id',
        'version_no',
        'is_current',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'extracted_text',
        'extraction_status',
        'extraction_error',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeItemChunk::class, 'knowledge_item_version_id');
    }
}
