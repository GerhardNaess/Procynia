<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The seam between Kvalitet and Wiki.
 *
 * A quality item may draw on any number of Wiki pages, and a Wiki page may back any number of
 * quality items — or none, which is the normal state of almost every page in a mature Wiki. The
 * page learns nothing from being linked: no quality type, no label, no change to how Wiki lists or
 * treats it. That is the whole difference from the retired classification model, where the link was
 * a property of the page and so could only ever be one.
 */
class QualityItemWikiLink extends Model
{
    /** The page is the write-up of this document. */
    public const LINK_TYPE_DOCUMENTS = 'documents';

    /** The page is background the document relies on without being its text. */
    public const LINK_TYPE_SUPPORTS = 'supports';

    /** The page records that the work described actually happened. */
    public const LINK_TYPE_EVIDENCE = 'evidence';

    /** @var list<string> */
    public const LINK_TYPES = [
        self::LINK_TYPE_DOCUMENTS,
        self::LINK_TYPE_SUPPORTS,
        self::LINK_TYPE_EVIDENCE,
    ];

    /** A person made this connection. */
    public const SOURCE_MANUAL = 'manual';

    /** Carried over from the retired page-classification model. */
    public const SOURCE_MIGRATED = 'migrated';

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'enterprise_wiki_page_id',
        'link_type',
        'note',
        'source',
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
}
