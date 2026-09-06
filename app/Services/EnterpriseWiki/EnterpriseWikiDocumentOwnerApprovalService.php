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

        $this->supersedeRowsNoLongerRequired($version, $approvals);

        return $approvals;
    }

    /**
     * Retire every row on this version that the current requirements no longer ask for.
     *
     * Rows are keyed by (version, owner, source_documents_hash), so an ordinary lifecycle event —
     * a document changing owner, or a second document joining the version — leaves the previous row
     * behind. It is not deleted: it records a real decision, with decided_at and decided_by_user_id,
     * and the approval model has to stay auditable. It is marked historic instead, so that the two
     * questions the row used to answer at once — "is this still required?" and "what was decided?" —
     * become separable.
     *
     * Without this, one orphaned pending row made approvedCurrentVersionPageIds() exclude the page
     * for good: it demands that every row on the current version be approved, while the orphan was
     * invisible to every approval surface and so could never be decided.
     *
     * @param  Collection<int, EnterpriseWikiPageVersionDocumentOwnerApproval>  $required
     */
    private function supersedeRowsNoLongerRequired(EnterpriseWikiPageVersion $version, Collection $required): void
    {
        $keepIds = $required
            ->map(static fn (EnterpriseWikiPageVersionDocumentOwnerApproval $approval): int => (int) $approval->id)
            ->all();

        EnterpriseWikiPageVersionDocumentOwnerApproval::query()
            ->where('enterprise_wiki_page_version_id', $version->id)
            ->whereNull('superseded_at')
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->update(['superseded_at' => now()]);
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
                ->with([
                    'sourceReferences' => fn ($query) => $query
                        ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT),
                    'canonicalFact',
                ])
                ->get();

        return $this->buildRequirementGroups($version, $claims, $page);
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
            ->where(function ($query) use ($document): void {
                $query->whereHas('claims.sourceReferences', fn ($q) => $q
                    ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                    ->where('source_id', $document->id))
                    ->orWhere(function ($blockQuery) use ($document): void {
                        $blockQuery->whereNotNull('content_blocks_json')->whereRaw(
                            "EXISTS (SELECT 1 FROM json_array_elements(content_blocks_json) AS elem WHERE elem->>'source_type' = ? AND (elem->>'source_id')::bigint = ?)",
                            [EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $document->id],
                        );
                    });
            })
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
     * Are this version's source owners done with it?
     *
     * The quality gate before final Wiki review. Each active row is one document owner confirming
     * that the content drawn from THEIR documents is represented correctly — not that the page as a
     * whole is ready. That judgement stays with the assigned reviewer.
     *
     * Requirements are re-synced first, the same way evaluateRunCompletionGate() does, so a document
     * that changed hands since submission is reflected rather than answered by whoever used to own
     * it. A version with no requirements at all is ready: nothing was drawn from a source document,
     * so there is nobody to ask.
     *
     * @return array{ready: bool, pending: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function sourceOwnerGateForVersion(EnterpriseWikiPageVersion $version): array
    {
        $pending = [];
        $rejected = [];

        foreach ($this->syncForPageVersion($version) as $approval) {
            $entry = [
                'approval_id' => (int) $approval->id,
                'owner_label' => $this->ownerLabel($approval),
                'documents_label' => $this->documentsLabel($approval),
                'status' => $approval->approval_status,
            ];

            if ($approval->isRejected()) {
                $rejected[] = $entry;
            } elseif ($approval->isPending()) {
                $pending[] = $entry;
            }
        }

        return [
            'ready' => $pending === [] && $rejected === [],
            'pending' => $pending,
            'rejected' => $rejected,
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

        // A superseded row is history. Deciding it would record a fresh decision against a
        // requirement that no longer exists — System Owner included.
        if ($approval->isSuperseded()) {
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
     * The pages whose CURRENT version is fully signed off by its document owners — the one
     * authoritative "approved current knowledge" signal in this architecture.
     *
     * Same rule evaluateRunCompletionGate() applies to a run, asked per page instead: every
     * approval row on the current version is approved, and there is at least one. A version with
     * no approval requirement at all is not "approved by default" — nobody vouched for it, so it
     * stays out of anything that presents Wiki content as documented customer fact.
     *
     * Why the current version and not enterprise_wiki_pages.status: approvals are keyed to
     * (version, owner, source_documents_hash), so a regenerated page gets fresh pending rows and
     * loses its approval automatically. A page-level status carries no version identity and would
     * keep saying "approved" over content nobody has read.
     *
     * Read-only and set-based — never syncs or writes rows.
     *
     * @param  list<int>  $pageIds  optional narrowing; empty means every page of the customer
     * @return list<int>
     */
    public function approvedCurrentVersionPageIds(int $customerId, array $pageIds = []): array
    {
        return EnterpriseWikiPageVersion::query()
            ->join('enterprise_wiki_pages', 'enterprise_wiki_pages.id', '=', 'enterprise_wiki_page_versions.enterprise_wiki_page_id')
            ->join(
                'enterprise_wiki_page_version_document_owner_approvals as approvals',
                'approvals.enterprise_wiki_page_version_id',
                '=',
                'enterprise_wiki_page_versions.id',
            )
            ->where('enterprise_wiki_pages.customer_id', $customerId)
            ->where('enterprise_wiki_page_versions.is_current', true)
            ->whereNull('approvals.superseded_at')
            ->when($pageIds !== [], fn ($query) => $query->whereIn('enterprise_wiki_page_versions.enterprise_wiki_page_id', $pageIds))
            ->groupBy('enterprise_wiki_page_versions.enterprise_wiki_page_id')
            ->havingRaw('count(*) = count(*) filter (where approvals.approval_status = ?)', [
                EnterpriseWikiPageVersionDocumentOwnerApproval::APPROVAL_STATUS_APPROVED,
            ])
            ->pluck('enterprise_wiki_page_versions.enterprise_wiki_page_id')
            ->map(static fn ($pageId): int => (int) $pageId)
            ->all();
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

        return $this->buildRequirementGroups($version, $claims, $page)
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
    private function buildRequirementGroups(EnterpriseWikiPageVersion $version, Collection $claims, EnterpriseWikiPage $page): Collection
    {
        // v0.10 (docs/enterprise-llm-wiki-plan.md, "Arkitekturnotat — v0.10"): Document Owner
        // approval attributes real, source-linked content to its owning document — it is
        // orthogonal to claim QA review and must never be suppressed by open claim QA signals
        // (unsupported_generated_content/internal_error/missing provenance). Approval
        // requirements are always built from the page version's actual provenance below,
        // regardless of hasOpenClaimQaSignals() — that method remains available purely as
        // informational data for the voluntary QA screen (see hasOpenClaimQaSignalsForVersion()).
        //
        // Authoritative provenance is EnterpriseWikiPageVersion::content_blocks_json — every
        // block written by EnterpriseWikiPageContentBlockService carries its own source_type/
        // source_id independently of whether a claim was ever extracted for it, so ordinary
        // source-attributed content (content_origin = source_based) is discovered here even with
        // zero claims. Claims' own source references are unioned in on top of that (not replaced)
        // so a claim that cites a document beyond what the version's blocks record still triggers
        // its owner's requirement, and existing claim-based provenance keeps working unchanged.
        $documentIds = $this->sourceBasedDocumentIdsForVersion($version)
            ->merge($this->claimSourceDocumentIdsFromCollection($claims))
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
     * Document IDs a page version's own content blocks are directly attributed to. Only
     * content_origin = source_based blocks count — best_practice/unsupported_generated_content/
     * internal_error/unclassified blocks are Procynia-side content, not source document content,
     * and must never generate a document owner approval requirement on their own.
     *
     * @return Collection<int, int>
     */
    private function sourceBasedDocumentIdsForVersion(EnterpriseWikiPageVersion $version): Collection
    {
        $blocks = is_array($version->content_blocks_json) ? $version->content_blocks_json : [];
        $ids = collect();

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['content_origin'] ?? null) !== EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED) {
                continue;
            }

            $ids->push($this->documentIdFromProvenancePayload($block));

            foreach ((array) ($block['source_elements'] ?? []) as $element) {
                if (is_array($element)) {
                    $ids->push($this->documentIdFromProvenancePayload($element));
                }
            }
        }

        return $ids->filter(static fn (?int $value): bool => $value !== null && $value > 0)
            ->unique()
            ->values();
    }

    private function documentIdFromProvenancePayload(array $payload): ?int
    {
        if (($payload['source_type'] ?? null) !== EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT) {
            return null;
        }

        $sourceId = (int) ($payload['source_id'] ?? 0);

        return $sourceId > 0 ? $sourceId : null;
    }

    /**
     * @return Collection<int, int>
     */
    private function claimSourceDocumentIdsFromCollection(Collection $claims): Collection
    {
        return $claims->flatMap(function (EnterpriseWikiClaim $claim): Collection {
            return $claim->sourceReferences
                ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT)
                ->pluck('source_id');
        })->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
    }

    /**
     * Whether a page version's claims currently carry an open claim QA signal — informational
     * only (v0.10). Public counterpart of hasOpenClaimQaSignals() for callers (WikiController)
     * that only need the boolean, not a full requirements/approvals computation. Does not affect
     * whether a Document Owner approval requirement is created — see buildRequirementGroups().
     */
    public function hasOpenClaimQaSignalsForVersion(EnterpriseWikiPageVersion $version): bool
    {
        $claims = $version->relationLoaded('claims')
            ? $version->claims
            : EnterpriseWikiClaim::query()
                ->where('enterprise_wiki_page_version_id', $version->id)
                ->with([
                    'sourceReferences' => fn ($query) => $query
                        ->where('source_type', EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT),
                    'canonicalFact',
                ])
                ->get();

        return $this->hasOpenClaimQaSignals($claims);
    }

    /**
     * Whether a page version's claims carry an open claim QA signal (unsupported generated
     * content, an internal generation/anchoring error a human has explicitly flagged, or a
     * source-based claim missing its provenance — the same set
     * EnterpriseWikiPostIngestQaService::findOpenClaimQaSignals() reports, so the two can never
     * disagree). Purely informational since v0.10 (docs/enterprise-llm-wiki-plan.md,
     * "Arkitekturnotat — v0.10") — it no longer suppresses or gates the Document Owner approval
     * requirement (see buildRequirementGroups()). Best-practice suggestions are
     * excluded — those are a distinct, already-supported review flow (WikiClaimController::approve()).
     */
    private function hasOpenClaimQaSignals(Collection $claims): bool
    {
        return $claims->contains(function (EnterpriseWikiClaim $claim): bool {
            if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR) {
                // internal_error alone is pure technical noise (a linking/anchoring limitation),
                // not content a human generated — stays hidden unless a human explicitly flagged
                // it, matching EnterpriseWikiClaimFindingExplainer::isUserFacingAddition().
                return $claim->blocking_override === true;
            }

            if ($claim->content_origin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT) {
                return $this->claimFindingExplainer->isUserFacingAddition($claim);
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
            $revive = [];

            if ($run !== null && $approval->enterprise_wiki_ingest_run_id === null) {
                $revive['enterprise_wiki_ingest_run_id'] = $run->id;
            }

            // The same (owner, hash) can become required again — a document handed back, or a
            // second document removed again. The row is the requirement, so it returns to active
            // with the decision it already carries rather than being duplicated.
            if ($approval->superseded_at !== null) {
                $revive['superseded_at'] = null;
            }

            if ($revive !== []) {
                $approval->forceFill($revive)->save();
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
