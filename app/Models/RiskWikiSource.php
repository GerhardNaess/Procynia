<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A risk was the origin of one Enterprise Wiki source. Ids only — the Wiki owns what it says.
 * Read from the risk's side only; see the migration.
 */
class RiskWikiSource extends Model
{
    protected $fillable = [
        'customer_id',
        'risk_id',
        'enterprise_wiki_document_id',
        'created_by_user_id',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }
}
