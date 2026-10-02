<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity on a prosessflyt was the source of one Enterprise Wiki article.
 *
 * Provenance, not knowledge. The row records that someone stood on a step of a process, asked for
 * the knowledge behind it to be written down, and that an ordinary Wiki page was created from it.
 * What the page says — and what it says next week — is Wiki's business: there is no title, no text
 * and no status here, because all three would be a second copy that goes stale.
 *
 * This is the opposite direction from QualityItemWikiLink, and deliberately a different table. That
 * one is "this document draws on that page", chosen by a person out of pages that already exist.
 * This one is "that page exists because of this activity", and it is written exactly once, at the
 * moment the page is created. A link can be made and unmade; an origin cannot.
 *
 * The activity is named by its key in the flow payload, because an activity is a node in that
 * payload and not a row anywhere. See the migration for what happens when the key goes away.
 */
class QualityActivityWikiPage extends Model
{
    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'activity_key',
        'enterprise_wiki_page_id',
        'created_by_user_id',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
