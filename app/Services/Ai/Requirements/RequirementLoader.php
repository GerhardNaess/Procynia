<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use Illuminate\Database\Eloquent\Collection;

class RequirementLoader
{
    /**
     * Purpose: Load every requirement in a case with the canonical relations needed by the AI workspace.
     * Inputs: The visible saved notice identifier.
     * Returns: A deterministically ordered requirement collection.
     * Side effects: None.
     */
    public function loadForCase(int $savedNoticeId): Collection
    {
        return $this->baseQuery($savedNoticeId)
            ->withCount('revisions')
            ->get();
    }

    /**
     * Purpose: Load one canonical requirement row with full traceability context.
     * Inputs: The requirement identifier.
     * Returns: The canonical requirement row or a 404 if it does not exist.
     * Side effects: None.
     */
    public function loadCanonical(int $requirementId): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()
            ->with([
                'document',
                'chunk',
                'assignedUser',
                'assessment.assessedBy',
                'evidence.knowledgeItem',
                'evidence.knowledgeItemChunk',
                'evidence.knowledgeItemVersion',
                'answerBasisItems',
                'wikiAnswer',
                'revisions.changedBy',
            ])
            ->withCount('revisions')
            ->findOrFail($requirementId);
    }

    /**
     * Purpose: Load only approved requirements for downstream evidence and assessment refreshes.
     * Inputs: The visible saved notice identifier.
     * Returns: A deterministic collection of approved requirement rows.
     * Side effects: None.
     */
    public function loadApprovedForCase(int $savedNoticeId): Collection
    {
        return $this->baseQuery($savedNoticeId)
            ->where(function ($query): void {
                $query->where('approval_status', SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED)
                    ->orWhere('review_status', SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED);
            })
            ->withCount('revisions')
            ->get();
    }

    private function baseQuery(int $savedNoticeId)
    {
        return SavedNoticeAiRequirement::query()
            ->where('saved_notice_id', $savedNoticeId)
            ->where('publication_status', SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED)
            ->with([
                'document',
                'chunk',
                'assignedUser',
                'assessment.assessedBy',
                'evidence.knowledgeItem',
                'evidence.knowledgeItemChunk',
                'evidence.knowledgeItemVersion',
                'answerBasisItems',
                'wikiAnswer',
            ])
            ->orderByRaw('CASE WHEN saved_notice_ai_document_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('saved_notice_ai_document_id')
            ->orderBy('saved_notice_ai_document_chunk_id')
            ->orderBy('id');
    }
}
