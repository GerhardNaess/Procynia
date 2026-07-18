<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiSourceReference extends Model
{
    public const SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION = 'knowledge_item_version';

    public const SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT = 'enterprise_wiki_document';

    public const SOURCE_TYPE_SAVED_NOTICE_DOCUMENT = 'saved_notice_document';

    public const SOURCE_TYPE_DOFFIN_NOTICE = 'doffin_notice';

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const SOURCE_ELEMENT_TYPE_PARAGRAPH = 'paragraph';

    public const SOURCE_ELEMENT_TYPE_LIST_ITEM = 'list_item';

    public const SOURCE_ELEMENT_TYPE_TABLE_ROW = 'table_row';

    public const SOURCE_ELEMENT_TYPE_MANUAL = 'manual';

    public const SOURCE_ELEMENT_TYPES = [
        self::SOURCE_ELEMENT_TYPE_PARAGRAPH,
        self::SOURCE_ELEMENT_TYPE_LIST_ITEM,
        self::SOURCE_ELEMENT_TYPE_TABLE_ROW,
        self::SOURCE_ELEMENT_TYPE_MANUAL,
    ];

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
        self::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
        self::SOURCE_TYPE_SAVED_NOTICE_DOCUMENT,
        self::SOURCE_TYPE_DOFFIN_NOTICE,
        self::SOURCE_TYPE_MANUAL,
    ];

    protected $fillable = [
        'enterprise_wiki_claim_id',
        'source_type',
        'source_id',
        'source_element_key',
        'source_element_type',
        'source_row_key',
        'source_label',
        'excerpt',
        'source_hash',
        'page_reference',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class);
    }
}
