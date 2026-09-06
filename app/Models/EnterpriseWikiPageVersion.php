<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `is_current` is the single source of truth for a page's live/active Wiki version — ordinary
 * page display, the graph, lint, coverage, and claim/QA reads all resolve "the current version"
 * through this flag, never through any ingest run's `generated_page_version_id`. Enforced at the
 * database level by the `ewpv_page_single_current_unique` partial unique index (at most one
 * current row per page) and `ewpv_page_version_number_unique` (version_number unique per page) —
 * see the `add_authoritative_version_constraints_to_enterprise_wiki_page_versions_table` migration.
 * All writes that create a new current version or promote an existing one must go through
 * EnterpriseWikiPageVersionWriter, which takes the page-row lock these constraints assume.
 */
class EnterpriseWikiPageVersion extends Model
{
    protected $fillable = [
        'enterprise_wiki_page_id',
        'version_number',
        'is_current',
        'is_staged',
        'content_markdown',
        'content_blocks_json',
        'best_practice_review_json',
        'generated_by_model',
        'generation_prompt_hash',
        'created_by_user_id',
        'submitted_by_user_id',
        'submitted_at',
        'reviewer_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'is_current' => 'boolean',
            'is_staged' => 'boolean',
            'content_blocks_json' => 'array',
            'best_practice_review_json' => 'array',
            'created_by_user_id' => 'integer',
            'submitted_by_user_id' => 'integer',
            'submitted_at' => 'datetime',
            'reviewer_user_id' => 'integer',
        ];
    }

    /** Who handed this version over for review. */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** Who is expected to act on it. Null when the version was never submitted through the flow. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function isAwaitingReview(): bool
    {
        return $this->reviewer_user_id !== null;
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EnterpriseWikiClaim::class, 'enterprise_wiki_page_version_id')
            ->orderBy('position_order');
    }

    public function documentOwnerApprovals(): HasMany
    {
        return $this->hasMany(EnterpriseWikiPageVersionDocumentOwnerApproval::class, 'enterprise_wiki_page_version_id');
    }
}
