<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded review event on a version: a "send this back" (the reason, who gave it), or the page
 * owner withdrawing the page from review (who withdrew it, and who it had been handed to).
 *
 * Append-only: rows are written when a version is returned or withdrawn and never updated or
 * deleted, so a page that went through several rounds keeps every objection it was asked to answer.
 */
class EnterpriseWikiPageReviewEvent extends Model
{
    /** The assigned reviewer, speaking about the page as a whole. */
    public const ACTOR_ROLE_REVIEWER = 'reviewer';

    /** A document owner, speaking only about content drawn from their own sources. */
    public const ACTOR_ROLE_DOCUMENT_OWNER = 'document_owner';

    /** Whoever may hand the page over (the page owner, or a System Owner) taking it back. */
    public const ACTOR_ROLE_PAGE_OWNER = 'page_owner';

    public const EVENT_TYPE_CHANGES_REQUESTED = 'changes_requested';

    /** The page was taken back from review before anyone decided it. Carries no request to act. */
    public const EVENT_TYPE_WITHDRAWN = 'withdrawn';

    /** A reason short enough to be meaningless helps nobody; one this long is a document. */
    public const REASON_MIN_LENGTH = 10;

    public const REASON_MAX_LENGTH = 2000;

    protected $fillable = [
        'enterprise_wiki_page_id',
        'enterprise_wiki_page_version_id',
        'actor_user_id',
        'actor_role',
        'assigned_reviewer_user_id',
        'event_type',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'enterprise_wiki_page_id' => 'integer',
            'enterprise_wiki_page_version_id' => 'integer',
            'actor_user_id' => 'integer',
            'assigned_reviewer_user_id' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function pageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'enterprise_wiki_page_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** For a withdrawal: who the version had been handed to when it was taken back. */
    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_user_id');
    }
}
