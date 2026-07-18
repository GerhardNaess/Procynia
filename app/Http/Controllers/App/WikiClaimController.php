<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentSourceElementService;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Claim review decisions — a System Owner, or any user with the QA capability and effective
 * access to the approve_wiki_claims permission, can record an explicit decision on a Wiki
 * claim (approve, reject, or undo the decision later) and attach a real document-backed source
 * reference when a claim is missing one. See User::canApproveWikiClaims().
 *
 * This is a separate permission from whole-page approval/rejection (WikiController::approve()/
 * reject()), which remains System Owner-only — QA never grants the ability to approve or
 * reject an entire Wiki page.
 *
 * Manual approval/rejection remains a distinct review decision
 * (EnterpriseWikiClaim.approval_status/approved_by_user_id/approved_at/approval_comment), not a
 * substitute for real evidence. A separate source-link action can attach an actual
 * EnterpriseWikiSourceReference later, and EnterpriseWikiClaim::sourceStatus() keeps the
 * evidence state and the decision state separate.
 */
class WikiClaimController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly EnterpriseWikiAppliedRunLintService $lintService,
        private readonly EnterpriseWikiDocumentSourceElementService $sourceElementService,
        private readonly EnterpriseWikiDocumentOwnerApprovalService $documentOwnerApprovalService,
    ) {}

    public function approve(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
            'approved_text' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->applyBestPracticeTextEdit($claim, $validated['approved_text'] ?? null);

        $this->storeDecision(
            $claim->fresh(),
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            $validated['comment'] ?? null,
        );

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er godkjent.');
    }

    public function reject(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->storeDecision(
            $claim,
            $user->id,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
            $validated['comment'] ?? null,
        );

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Påstanden er avvist.');
    }

    public function storeSourceReference(Request $request, string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        $validated = $request->validate([
            'source_document_id' => ['required', 'integer'],
            'source_element_key' => ['nullable', 'string', 'max:255'],
            'source_element_type' => ['nullable', 'string', 'max:50'],
            'source_row_key' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:4000'],
            'page_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $document = EnterpriseWikiDocument::query()
            ->where('customer_id', $this->customerContext->currentCustomerId())
            ->where('id', (int) $validated['source_document_id'])
            ->where('document_status', EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED)
            ->first();

        abort_unless($document !== null && trim((string) $document->extracted_text) !== '', 404);
        abort_unless($this->documentOwnerApprovalService->canUseSourceDocumentForClaim($claim, $document, $user, $page->currentVersion()->first()), 403);

        $hadSourceReference = $claim->hasSourceReference();
        $catalog = $this->sourceElementService->inspect($document);
        $sourceElementKey = $this->normalizeNullableString($validated['source_element_key'] ?? null);
        $sourceElementType = $this->normalizeNullableString($validated['source_element_type'] ?? null);
        $sourceRowKey = $this->normalizeNullableString($validated['source_row_key'] ?? null);
        $pageReference = $this->normalizeNullableString($validated['page_reference'] ?? null);
        $excerpt = $this->normalizeNullableString($validated['excerpt'] ?? null);

        $resolvedSelection = null;
        $isManualFallbackRequest = $sourceElementType === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL
            || ($sourceElementKey === null && $sourceElementType === null && $sourceRowKey === null);

        if (! $isManualFallbackRequest) {
            $resolvedSelection = $this->sourceElementService->resolveSelection(
                $document,
                $sourceElementKey,
                $sourceElementType,
                $sourceRowKey,
            );

            abort_unless($resolvedSelection !== null, 422, 'Det valgte kildedokumentelementet finnes ikke i dette dokumentet.');

            $sourceElementKey = $resolvedSelection['source_element_key'] ?? null;
            $sourceElementType = $resolvedSelection['source_element_type'] ?? null;
            $sourceRowKey = $resolvedSelection['source_row_key'] ?? null;
            $excerpt = $resolvedSelection['reference_text'] ?? null;
            $pageReference = $resolvedSelection['page_reference'] ?? null;
        } elseif (! ($catalog['manual_source_allowed'] ?? false)) {
            abort(422, 'Velg et konkret kildedokumentelement når dokumentet har strukturerte elementer.');
        } elseif ($excerpt === null) {
            abort(422, 'Et manuelt kildeutdrag kreves når dokumentet ikke har strukturerte elementer.');
        } else {
            $sourceElementType = EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_MANUAL;
            $sourceElementKey = null;
            $sourceRowKey = null;
        }

        EnterpriseWikiSourceReference::query()->updateOrCreate(
            [
                'enterprise_wiki_claim_id' => $claim->id,
                'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                'source_id' => $document->id,
                'source_element_key' => $sourceElementKey,
                'source_element_type' => $sourceElementType,
                'source_row_key' => $sourceRowKey,
            ],
            [
                'source_label' => $document->original_filename,
                'source_hash' => hash('sha256', implode('|', [
                    'enterprise_wiki_document',
                    $document->id,
                    $document->file_hash_sha256,
                    $sourceElementType ?? 'manual',
                    $sourceElementKey ?? 'manual',
                    $sourceRowKey ?? 'manual',
                ])),
                'excerpt' => $excerpt,
                'page_reference' => $pageReference,
            ],
        );

        $this->lintService->resetClaimDecisionAfterFirstSourceReference($claim, ! $hadSourceReference);
        $this->lintService->resolveClaimMissingSourceFinding($claim);

        return redirect()
            ->route('app.wiki.show', ['slug' => $page->slug, 'claim_id' => $claim->id])
            ->with('success', 'Kilden er koblet til påstanden.');
    }

    public function unapprove(string $slug, EnterpriseWikiClaim $claim): RedirectResponse
    {
        $user = $this->customerContext->currentUser();

        $page = $this->resolvePageForClaim($slug, $claim);
        abort_unless($this->canHandleClaimForPage($page, $claim, $user), 403);

        if (! in_array($claim->approval_status, [
            EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            EnterpriseWikiClaim::APPROVAL_STATUS_REJECTED,
        ], true)) {
            abort(422, 'Claim is not manually decided.');
        }

        $claim->update([
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_comment' => null,
        ]);

        $this->lintService->reopenClaimMissingSourceFindingIfStillMissing($claim);

        return redirect()->route('app.wiki.show', $page->slug)->with('success', 'Beslutningen er angret.');
    }

    private function storeDecision(
        EnterpriseWikiClaim $claim,
        int $userId,
        string $status,
        ?string $comment,
    ): void {
        $claim->update([
            'approval_status' => $status,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'approval_comment' => $comment,
        ]);

        $this->lintService->resolveClaimMissingSourceFinding($claim);
    }

    private function applyBestPracticeTextEdit(EnterpriseWikiClaim $claim, ?string $approvedText): void
    {
        if ($claim->content_origin !== EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE) {
            return;
        }

        $approvedText = $this->normalizeNullableString($approvedText);

        if ($approvedText === null || $approvedText === trim((string) $claim->claim_text)) {
            return;
        }

        $claim->loadMissing('version');
        $version = $claim->version;

        if ($version === null || (int) $version->id !== (int) $claim->enterprise_wiki_page_version_id) {
            abort(422, 'Claim is not tied to a valid Wiki page version.');
        }

        $anchor = $this->normalizeNullableString($claim->page_excerpt) ?? $this->normalizeNullableString($claim->claim_text);

        if ($anchor === null || ! str_contains((string) $version->content_markdown, $anchor)) {
            abort(422, 'Best-practice text can only be edited when the original text is still present in the current Wiki page version.');
        }

        $version->update([
            'content_markdown' => str_replace($anchor, $approvedText, (string) $version->content_markdown),
        ]);

        $claim->update([
            'claim_text' => $approvedText,
            'page_excerpt' => $approvedText,
        ]);
    }

    private function resolvePageForClaim(string $slug, EnterpriseWikiClaim $claim): EnterpriseWikiPage
    {
        $customerId = $this->customerContext->currentCustomerId();

        $page = EnterpriseWikiPage::query()
            ->where('customer_id', $customerId)
            ->where('slug', $slug)
            ->first() ?? abort(404);

        abort_unless($claim->enterprise_wiki_page_id === $page->id, 404);

        return $page;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public function sourceDocumentElements(string $slug, EnterpriseWikiClaim $claim, EnterpriseWikiDocument $document): JsonResponse
    {
        $page = $this->resolvePageForClaim($slug, $claim);
        $user = $this->customerContext->currentUser();

        abort_unless($document->customer_id === $this->customerContext->currentCustomerId(), 404);
        abort_unless($claim->enterprise_wiki_page_id === $page->id, 404);
        abort_unless($document->document_status === EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED, 404);
        abort_unless(trim((string) $document->extracted_text) !== '', 404);
        abort_unless($this->documentOwnerApprovalService->canUseSourceDocumentForClaim($claim, $document, $user, $page->currentVersion()->first()), 403);

        $catalog = $this->sourceElementService->inspect($document);

        return response()->json([
            'document_id' => $document->id,
            'document_name' => $document->original_filename,
            'supports_structured_elements' => $catalog['supports_structured_elements'],
            'manual_source_allowed' => $catalog['manual_source_allowed'],
            'manual_source_reason' => $catalog['manual_source_reason'],
            'elements' => $catalog['elements'],
        ]);
    }

    private function canHandleClaimForPage(EnterpriseWikiPage $page, EnterpriseWikiClaim $claim, ?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $this->documentOwnerApprovalService->canHandleClaim(
            $claim,
            $user,
            $page->currentVersion()->first(),
        );
    }
}
