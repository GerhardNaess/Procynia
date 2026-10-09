<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Wiki page given as guidance on a control requirement (supplier-assurance-v2-plan §28). A
 * reference only: the page's title, text and status are read from the Wiki, with the reader's own
 * Wiki access. Written only by SupplierRequirementWikiGuidance.
 */
class SupplierControlRequirementWikiPage extends Model
{
    protected $fillable = [
        'customer_id',
        'requirement_id',
        'enterprise_wiki_page_id',
        'created_by_user_id',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SupplierControlRequirement::class, 'requirement_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }
}
