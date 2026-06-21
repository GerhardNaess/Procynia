<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeItemRevision extends Model
{
    public const CHANGE_TYPE_CREATED = 'created';

    public const CHANGE_TYPE_METADATA_UPDATED = 'metadata_updated';

    public const CHANGE_TYPE_FILE_REPLACED = 'file_replaced';

    public const CHANGE_TYPE_DELETED = 'deleted';

    public const CHANGE_TYPE_VERSION_REJECTED = 'version_rejected';

    public const CHANGE_TYPES = [
        self::CHANGE_TYPE_CREATED,
        self::CHANGE_TYPE_METADATA_UPDATED,
        self::CHANGE_TYPE_FILE_REPLACED,
        self::CHANGE_TYPE_DELETED,
        self::CHANGE_TYPE_VERSION_REJECTED,
    ];

    public const CHANGE_TYPE_LABELS = [
        self::CHANGE_TYPE_CREATED => 'Opprettet',
        self::CHANGE_TYPE_METADATA_UPDATED => 'Metadata oppdatert',
        self::CHANGE_TYPE_FILE_REPLACED => 'Fil erstattet',
        self::CHANGE_TYPE_DELETED => 'Slettet',
        self::CHANGE_TYPE_VERSION_REJECTED => 'Versjon avvist',
    ];

    protected $fillable = [
        'knowledge_item_id',
        'customer_id',
        'revision_no',
        'change_type',
        'changed_by_user_id',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_item_id' => 'integer',
            'customer_id' => 'integer',
            'revision_no' => 'integer',
            'changed_by_user_id' => 'integer',
            'snapshot' => 'array',
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
