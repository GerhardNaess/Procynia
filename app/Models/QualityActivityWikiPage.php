<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity on a prosessflyt was the source of one piece of Enterprise Wiki knowledge.
 *
 * Provenance, not knowledge. The row records that someone stood on a step of a process, asked for
 * the knowledge behind it to be written down, and that it entered the Wiki. What the Wiki then
 * says — and what it says next week — is Wiki's business: there is no title, no text and no
 * status here, because all three would be a second copy that goes stale.
 *
 * WHICH END IT POINTS AT. A row written by the current direction names the SOURCE DOCUMENT the
 * article was stored as, because that is what the activity actually produced: the pages are
 * whatever the ordinary ingest run made of it, and there may be several of several types. Rows
 * written before that direction existed name a single page they created by hand, and keep it.
 * Exactly one of the two is set — the database enforces it; see the migration.
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
        'enterprise_wiki_document_id',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
