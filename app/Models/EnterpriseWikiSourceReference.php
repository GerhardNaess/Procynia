<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One source behind a Wiki claim. Says who linked it and when (linked_by_*, linked_at): a person
 * («Koble kilde», LINKED_BY_USER), a named step of the pipeline (LINKED_BY_SYSTEM), or — for rows
 * from before this was stored — LINKED_BY_UNRECORDED. A copy into a new version keeps the
 * attribution of the link it copies; copying is not linking.
 */
class EnterpriseWikiSourceReference extends Model
{
    public const LINKED_BY_USER = 'user';

    public const LINKED_BY_SYSTEM = 'system';

    public const LINKED_BY_UNRECORDED = 'unrecorded';

    public const PROCESS_SECTION_PROCESSING = 'section_processing';

    public const PROCESS_CLAIM_EXTRACTION = 'claim_extraction';

    public const PROCESS_CLAIM_VERIFICATION = 'claim_verification';

    public const PROCESS_SOURCE_RECONCILIATION = 'source_reconciliation';

    public const PROCESS_SOURCE_LINK_REPAIR = 'source_link_repair';

    public const SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT = 'enterprise_wiki_document';

    public const SOURCE_TYPE_SAVED_NOTICE_DOCUMENT = 'saved_notice_document';

    public const SOURCE_TYPE_DOFFIN_NOTICE = 'doffin_notice';

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const SOURCE_ELEMENT_TYPE_PARAGRAPH = 'paragraph';

    public const SOURCE_ELEMENT_TYPE_LIST_ITEM = 'list_item';

    public const SOURCE_ELEMENT_TYPE_TABLE_ROW = 'table_row';

    public const SOURCE_ELEMENT_TYPE_IMAGE = 'image';

    public const SOURCE_ELEMENT_TYPE_MANUAL = 'manual';

    public const SOURCE_ELEMENT_TYPES = [
        self::SOURCE_ELEMENT_TYPE_PARAGRAPH,
        self::SOURCE_ELEMENT_TYPE_LIST_ITEM,
        self::SOURCE_ELEMENT_TYPE_TABLE_ROW,
        self::SOURCE_ELEMENT_TYPE_IMAGE,
        self::SOURCE_ELEMENT_TYPE_MANUAL,
    ];

    public const SOURCE_TYPES = [
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
        'source_cell_key',
        'source_column_key',
        'source_label',
        'excerpt',
        'source_hash',
        'page_reference',
        'linked_by_type',
        'linked_by_user_id',
        'linked_by_process',
        'linked_at',
    ];

    /** @return array{linked_by_type: string, linked_by_user_id: int, linked_by_process: null, linked_at: Carbon} */
    public static function linkedByUser(User $user): array
    {
        return [
            'linked_by_type' => self::LINKED_BY_USER,
            'linked_by_user_id' => $user->id,
            'linked_by_process' => null,
            'linked_at' => now(),
        ];
    }

    /** @return array{linked_by_type: string, linked_by_user_id: null, linked_by_process: string, linked_at: Carbon} */
    public static function linkedBySystem(string $process): array
    {
        return [
            'linked_by_type' => self::LINKED_BY_SYSTEM,
            'linked_by_user_id' => null,
            'linked_by_process' => $process,
            'linked_at' => now(),
        ];
    }

    /** @return array<string, mixed> the attribution a copy of this link carries over */
    public function linkAttribution(): array
    {
        return [
            'linked_by_type' => $this->linked_by_type ?? self::LINKED_BY_UNRECORDED,
            'linked_by_user_id' => $this->linked_by_user_id,
            'linked_by_process' => $this->linked_by_process,
            'linked_at' => $this->linked_at ?? $this->created_at,
        ];
    }

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'linked_by_user_id' => 'integer',
            'linked_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
