<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which module record a Wiki source document was handed over from — see the migration
 * `create_enterprise_wiki_document_origins_table` for the reading and deletion rules.
 *
 * Read by the source module (to list what it handed over) and by usage attribution. Never by a
 * Wiki screen.
 */
class EnterpriseWikiDocumentOrigin extends Model
{
    protected $fillable = [
        'customer_id',
        'enterprise_wiki_document_id',
        'source_module',
        'source_type',
        'source_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'enterprise_wiki_document_id' => 'integer',
            'source_id' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * The resource a Wiki run's AI usage is attributed to: the module record the document was
     * handed over from (earliest origin), or the document itself for an ordinary upload. Feature and
     * operation stay `wiki.*` — the Wiki does the work; this only makes the cost traceable to it.
     *
     * @return array{type: string, id: int}
     */
    public static function usageResourceFor(int $documentId): array
    {
        $origin = self::query()
            ->where('enterprise_wiki_document_id', $documentId)
            ->orderBy('id')
            ->first(['source_type', 'source_id']);

        return $origin instanceof self
            ? ['type' => (string) $origin->source_type, 'id' => (int) $origin->source_id]
            : ['type' => 'enterprise_wiki_document', 'id' => $documentId];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
