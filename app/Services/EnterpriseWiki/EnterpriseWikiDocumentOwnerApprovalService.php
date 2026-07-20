<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiPageVersionDocumentOwnerApproval;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class EnterpriseWikiDocumentOwnerApprovalService
{
    public function __construct(
        private readonly EnterpriseWikiClaimFindingExplainer $claimFindingExplainer,
    ) {}

    /**
     * Sync approvals for one page version from its current provenance.
     *
     * The rows are materialized so the UI and completion gate can read a stable approval
     * snapshot without re-deriving the provenance graph on every request.
     *
     * @return Collection<int, EnterpriseWikiPageVersionDocumentOwnerApproval>
     */
    public function syncForPageVersion(EnterpriseWikiPageVersion $version, ?EnterpriseWikiIngestRun $run = null): Collection
    {
        $run ??= $this->resolveRunForPageVersion($version);
        $page = $version->relationLoaded('page') ? $version->page : $version->page()->first();

        if ($page === null) {
            return collect();
        }

        $requirements = $this->buildRequirementsForPageVersion($version, $page);

        if ($requirements->isEmpty()) {
            return collect();
        }

        $approvals = collect();

        foreach ($requirements as $requirement) {
            $approvals->push($this->materializeRequirement($version, $page, $run, $requirement));
        }

        return $approvals;
    }

    /**
     * Preview the current required owner groups for one page version without writing rows.
     *
     * @return Collection<int, array{
     *     document_owner_user_id: int|null,
     *     source_document_ids: list<int>,
     *     source_document_labels: list<string>,
     *     source_documents_hash: string
     * }>
     */
    public function previewRequirementsForPageVersion(EnterpriseWikiPageVersion $version): Collection
    {
        $page = $version->relationLoaded('page') ? $version->page : $version->page()->first();

        if ($page === null) {
            return collect();
        }

        $claims = $version->relationLoaded('claims')
            ? $version->claims
            : EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->with(['sourceReferences' => fn ($query) => $query
                    ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)])
                ->get();

        return $this->buildRequirementGroupsFromClaims($claims, $page);
    }

    /**
     * Sync every current active page version that actually uses the given document.
     *
     * @return Collection<int, EnterpriseWikiPageVersion>
     */
    public function syncForDocument(EnterpriseWikiDocument $document): Collection
    {
        $versions = EnterpriseWikiPageVersion::query()
            ->where('is_current', true)
            ->whereHas('page', fn ($query) => $query
                ->where('customer_id', $document->customer_id)
                ->whereNotIn('status', [
                    EnterpriseWikiPage::STATUS_ARCHIVED,
                    EnterpriseWikiPage::STATUS_SUPERSEDED,
                ]))
            ->whereHas('claims.sourceReferences', fn ($query) => $query
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->where('source_id', $document->id))
            ->with(['page'])
            ->get();

        foreach ($versions as $version) {
            $this->syncForPageVersion($version);
        }

        return $versions;
    }

    /**
     * Sync every current page version for a run, then summarize whether completion may proceed.
     *
     * @return array{
     *     ready: bool,
     *     pending: list<array<string, mixed>>,
     *     rejected: list<array<string, mixed>>,
     *     missing_owner: list<array<string, mixed>>,
     *     message: ?string
     * }
     */
    public function evaluateRunCompletionGate(EnterpriseWikiIngestRun $run): array
    {
        $runPages = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->whereNotNull('generated_page_version_id')
            ->with(['page', 'generatedPageVersion'])
            ->get();

        $pending = [];
        $rejected = [];
        $missingOwner = [];

        foreach ($runPages as $runPage) {
            $version = $runPage->generatedPageVersion;

            if (! $version instanceof EnterpriseWikiPageVersion) {
                continue;
            }

            $approvals = $this->syncForPageVersion($version, $run);

            foreach ($approvals as $approval) {
                $ownerLabel = $this->ownerLabel($approval);
                $documentsLabel = $this->documentsLabel($approval);
                $entry = [
                    'page_id' => $approval->enterprise_wiki_page_id,
                    'page_title' => $approval->page?->title,
                    'page_slug' => $approval->page?->slug,
                    'approval_id' => $approval->id,
                    'owner_label' => $ownerLabel,
                    'documents_label' => $documentsLabel,
                    'status' => $approval->approval_status,
                ];

                if ($approval->isRejected()) {
                    $rejected[] = $entry;
                } elseif ($approval->isPending()) {
                    $pending[] = $entry;
                    if ($approval->document_owner_user_id === null) {
                        $missingOwner[] = $entry;
                    }
                }
            }
        }

        $ready = $pending === [] && $rejected === [];

        return [
            'ready' => $ready,
            'pending' => $pending,
            'rejected' => $rejected,
            'missing_owner' => $missingOwner,
            'message' => $ready ? null : $this->buildGateMessage($pending, $rejected, $missingOwner),
        ];
    }

    /**
     * Apply an approval or rejection decision to the concrete approval row.
     */
    public function decide(
        EnterpriseWikiPageVersionDocumentOwnerApproval $approval,
        User $actor,
        string $decision,
        ?string $comment = null,
    ): EnterpriseWikiPageVersionDocumentOwnerApproval {
        $now = now();
        $isOverride = ! $actor->isSystemOwner() ? false : ((int) $approval->document_owner_user_id !== (int) $actor->id);

        $approval->forceFill([
            'approval_status' => $decision,
            'approval_comment' => $comment,
            'decided_at' => $now,
            'decided_by_user_id' => $actor->id,
            'is_override' => $isOverride,
            'override_reason' => $isOverride ? $comment : null,
            'overridden_by_user_id' => $isOverride ? $actor->id : null,
            'overridden_at' => $isOverride ? $now : null,
        ])->save();

        return $approval->fresh(['pageVersion.page', 'documentOwner', 'decidedBy', 'overriddenBy']);
    }

    /**
     * Returns whether the given user can decide this approval row.
     */
    public function canDecide(EnterpriseWikiPageVersionDocumentOwnerApproval $approval, User $actor): bool
    {
        if (! $actor->is_active || ! $actor->canAccessCustomerFrontend()) {
            return false;
        }

        if ($actor->isSystemOwner()) {
            return true;
        }

        return $approval->document_owner_user_id !== null
            && (int) $approval->document_owner_user_id === (int) $actor->id;
    }

    /**
     * Decide whether a user may handle one claim's verification basis.
     *
     * System Owner and QA keep their existing broad access. Document owners are granted access
     * only when the claim is tied to one of their own source documents, or when the claim still
     * lacks a source reference but the current page version already proves that the user is a
     * required owner for that version's provenance.
     */
    public function canHandleClaim(EnterpriseWikiClaim $claim, User $actor, ?EnterpriseWikiPageVersion $currentVersion = null): bool
    {
        if (! $actor->is_active || ! $actor->canAccessCustomerFrontend()) {
            return false;
        }

        if ($actor->isSystemOwner() || $actor->canApproveWikiClaims()) {
            return true;
        }

        $documentIds = $this->claimSourceDocumentIds($claim);

        if ($documentIds !== []) {
            return $this->userOwnsAnySourceDocument($actor, $documentIds);
        }

        $currentVersion ??= $claim->relationLoaded('version')
            ? $claim->version
            : $claim->version()->with('page')->first();

        return $currentVersion instanceof EnterpriseWikiPageVersion
            && $this->isRequiredDocumentOwnerForPageVersion($currentVersion, $actor);
    }

    /**
     * Decide whether a user may inspect or link a specific source document for one claim.
     * Document owners may only work with their own documents; generic QA/System Owner access
     * remains unchanged.
     */
    public function canUseSourceDocumentForClaim(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiDocument $document,
        User $actor,
        ?EnterpriseWikiPageVersion $currentVersion = null,
    ): bool {
        if (! $actor->is_active || ! $actor->canAccessCustomerFrontend()) {
            return false;
        }

        if ($actor->isSystemOwner() || $actor->canApproveWikiClaims()) {
            return $document->customer_id === $actor->customer_id;
        }

        if ((int) $document->customer_id !== (int) $actor->customer_id) {
            return false;
        }

        if ((int) $document->owner_user_id === (int) $actor->id) {
            return $this->canHandleClaim($claim, $actor, $currentVersion);
        }

        return false;
    }

    /**
     * Determine whether the user is one of the required document owners for a version.
     */
    public function isRequiredDocumentOwnerForPageVersion(EnterpriseWikiPageVersion $version, User $user): bool
    {
        $approvals = $this->syncForPageVersion($version);

        return $approvals->contains(function (EnterpriseWikiPageVersionDocumentOwnerApproval $approval) use ($user): bool {
            return $approval->document_owner_user_id !== null
                && (int) $approval->document_owner_user_id === (int) $user->id;
        });
    }

    /**
     * @return Collection<int, EnterpriseWikiPageVersionDocumentOwnerApproval>
     */
    private function buildRequirementsForPageVersion(EnterpriseWikiPageVersion $version, EnterpriseWikiPage $page): Collection
    {
        $claims = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->with(['sourceReferences' => fn ($query) => $query
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)])
            ->get();

        return $this->buildRequirementGroupsFromClaims($claims, $page)
            ->map(function (array $group) use ($version, $page): EnterpriseWikiPageVersionDocumentOwnerApproval {
                $sourceDocumentIds = collect($group['source_document_ids'] ?? [])
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $hash = hash('sha256', json_encode($sourceDocumentIds, JSON_THROW_ON_ERROR));

                return $this->firstOrCreateApproval($version, $page, $group['document_owner_user_id'] ?? null, $sourceDocumentIds, $hash);
            })
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     document_owner_user_id: int|null,
     *     source_document_ids: list<int>,
     *     source_document_labels: list<string>
     * }>
     */
    private function buildRequirementGroupsFromClaims(Collection $claims, EnterpriseWikiPage $page): Collection
    {
        if ($this->hasActiveClaimIntegrityDefects($claims)) {
            return collect();
        }

        $documentIds = $claims->flatMap(function (EnterpriseWikiClaim $claim): Collection {
            return $claim->sourceReferences
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->pluck('source_id');
        })->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();

        if ($documentIds->isEmpty()) {
            return collect();
        }

        $documents = EnterpriseWikiDocument::query()
            ->where('customer_id', $page->customer_id)
            ->whereIn('id', $documentIds)
            ->with('owner:id,name,email,is_active')
            ->get()
            ->keyBy('id');

        $groups = [];

        foreach ($documentIds as $documentId) {
            $document = $documents->get($documentId);

            if (! $document instanceof EnterpriseWikiDocument) {
                continue;
            }

            $ownerId = $document->owner_user_id !== null ? (int) $document->owner_user_id : null;
            $groupKey = $ownerId === null ? 'missing-owner' : 'owner-'.$ownerId;
            $groups[$groupKey]['document_owner_user_id'] = $ownerId;
            $groups[$groupKey]['source_document_ids'][] = (int) $document->id;
            $groups[$groupKey]['source_document_labels'][] = $document->original_filename;
        }

        return collect($groups)
            ->map(fn (array $group): array => [
                'document_owner_user_id' => $group['document_owner_user_id'] ?? null,
                'source_document_ids' => collect($group['source_document_ids'] ?? [])
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
                'source_document_labels' => collect($group['source_document_labels'] ?? [])
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->values()
                    ->all(),
                'source_documents_hash' => hash('sha256', json_encode(
                    collect($group['source_document_ids'] ?? [])
                        ->map(static fn (mixed $value): int => (int) $value)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                    JSON_THROW_ON_ERROR,
                )),
            ])
            ->values();
    }

    /**
     * Whether a page version's claims currently carry an active claim-integrity defect — the
     * public counterpart of hasActiveClaimIntegrityDefects() for callers (WikiController) that
     * only need the boolean, not a full requirements/approvals computation.
     */
    public function hasActiveClaimIntegrityDefectsForVersion(EnterpriseWikiPageVersion $version): bool
    {
        $claims = $version->relationLoaded('claims')
            ? $version->claims
            : EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->with(['sourceReferences' => fn ($query) => $query
                    ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)])
                ->get();

        return $this->hasActiveClaimIntegrityDefects($claims);
    }

    /**
     * A page version carrying an active claim-integrity defect (unsupported generated content,
     * an internal generation/anchoring error, or a source-based claim missing its provenance —
     * the same set EnterpriseWikiPostIngestQaService::findClaimIntegrityDefects() gates QA on)
     * must never generate a Document Owner approval requirement: the whole-page approval this
     * service builds would otherwise ask the Document Owner to bless known-invalid text as
     * ordinary, presumed-correct Wiki content. Best-practice suggestions are excluded — those
     * are a distinct, already-supported review flow (WikiClaimController::approve()) and must
     * not suppress or block the document-owner requirement on their own.
     */
    private function hasActiveClaimIntegrityDefects(Collection $claims): bool
    {
        return $claims->contains(function (EnterpriseWikiClaim $claim): bool {
            if (in_array($claim->content_origin, [
                EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
                EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            ], true)) {
                // Same effective-blocking rule as EnterpriseWikiPostIngestQaService::
                // findClaimIntegrityDefects() — an authorized user's recorded override wins,
                // otherwise the system's own suggestion (false for internal_error/"technical
                // uncertainty", true for unsupported_generated_content).
                return $claim->blocking_override ?? $this->claimFindingExplainer->suggestedBlocking($claim);
            }

            return $claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED
                && $claim->sourceReferences->isEmpty();
        });
    }

    private function firstOrCreateApproval(
        EnterpriseWikiPageVersion $version,
        EnterpriseWikiPage $page,
        ?int $documentOwnerUserId,
        array $sourceDocumentIds,
        string $hash,
        ?EnterpriseWikiIngestRun $run = null,
    ): EnterpriseWikiPageVersionDocumentOwnerApproval {
        $attributes = [
            'enterprise_wiki_page_version_id' => $version->id,
            'document_owner_user_id' => $documentOwnerUserId,
            'source_documents_hash' => $hash,
        ];

        $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where($attributes)
            ->first();

        if ($approval instanceof EnterpriseWikiPageVersionDocumentOwnerApproval) {
            if ($run !== null && $approval->enterprise_wiki_ingest_run_id === null) {
                $approval->forceFill(['enterprise_wiki_ingest_run_id' => $run->id])->save();
            }

            return $approval->loadMissing(['page', 'pageVersion.page', 'documentOwner', 'decidedBy', 'overriddenBy']);
        }

        try {
            $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()->create(array_merge($attributes, [
                'customer_id' => $page->customer_id,
                'enterprise_wiki_page_id' => $page->id,
                'enterprise_wiki_ingest_run_id' => $run?->id,
                'source_document_ids' => $sourceDocumentIds,
                'approval_status' => EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_PENDING,
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            $approval = EnterpriseWikiPageVersionDocumentOwnerApproval::query()
                ->where($attributes)
                ->firstOrFail();
        }

        return $approval->loadMissing(['page', 'pageVersion.page', 'documentOwner', 'decidedBy', 'overriddenBy']);
    }

    private function materializeRequirement(
        EnterpriseWikiPageVersion $version,
        EnterpriseWikiPage $page,
        ?EnterpriseWikiIngestRun $run,
        EnterpriseWikiPageVersionDocumentOwnerApproval $requirement,
    ): EnterpriseWikiPageVersionDocumentOwnerApproval {
        return $this->firstOrCreateApproval(
            $version,
            $page,
            $requirement->document_owner_user_id !== null ? (int) $requirement->document_owner_user_id : null,
            is_array($requirement->source_document_ids) ? $requirement->source_document_ids : [],
            (string) $requirement->source_documents_hash,
            $run,
        );
    }

    private function resolveRunForPageVersion(EnterpriseWikiPageVersion $version): ?EnterpriseWikiIngestRun
    {
        $runId = EnterpriseWikiIngestRunPage::query()
            ->where('generated_page_version_id', $version->id)
            ->value('enterprise_wiki_ingest_run_id');

        return $runId !== null
            ? EnterpriseWikiIngestRun::query()->find($runId)
            : null;
    }

    private function ownerLabel(EnterpriseWikiPageVersionDocumentOwnerApproval $approval): string
    {
        if ($approval->document_owner_user_id === null) {
            return 'Kildedokument mangler Dokumenteier';
        }

        return $approval->documentOwner?->name ?? 'Ukjent dokumenteier';
    }

    private function documentsLabel(EnterpriseWikiPageVersionDocumentOwnerApproval $approval): string
    {
        $documentIds = is_array($approval->source_document_ids) ? $approval->source_document_ids : [];

        return $documentIds === []
            ? 'Ingen dokumenter'
            : 'Dokumenter: '.implode(', ', array_map(static fn (mixed $id): string => (string) $id, $documentIds));
    }

    /**
     * @return list<int>
     */
    private function claimSourceDocumentIds(EnterpriseWikiClaim $claim): array
    {
        return collect($claim->relationLoaded('sourceReferences')
            ? $claim->sourceReferences
            : $claim->sourceReferences()->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)->get())
            ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
            ->pluck('source_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $documentIds
     */
    private function userOwnsAnySourceDocument(User $actor, array $documentIds): bool
    {
        if ($documentIds === []) {
            return false;
        }

        return EnterpriseWikiDocument::query()
            ->where('customer_id', $actor->customer_id)
            ->whereIn('id', $documentIds)
            ->where('owner_user_id', $actor->id)
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $rejected
     * @param  list<array<string, mixed>>  $missingOwner
     */
    private function buildGateMessage(array $pending, array $rejected, array $missingOwner): string
    {
        $parts = [];

        if ($missingOwner !== []) {
            $parts[] = 'Kildedokument mangler Dokumenteier';
        }

        if ($pending !== []) {
            $parts[] = 'Avventer godkjenning fra Dokumenteier';
        }

        if ($rejected !== []) {
            $parts[] = 'Avvist av Dokumenteier';
        }

        return implode(' · ', array_values(array_unique($parts)));
    }
}
