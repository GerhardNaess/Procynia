<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerPermissionCatalog;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Who may administer one Wiki source document and its runs — delete it, cancel or retry a run of
 * it, clear a finished run from Kjøringer — and who may put a source into the store, start a run or
 * name a document owner (A2).
 *
 * The same rule the controllers have always applied, kept in one place so the services behind them
 * apply it too: a person (HumanDecisionGuard), of the document's customer, holding
 * wiki.source.manage, for whom the document is theirs to delete
 * (User::canDeleteEnterpriseWikiDocument(): System Owner or the document owner).
 * docs/external-api-phase-0-plan.md §4.4.
 */
class EnterpriseWikiSourceAuthority
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly CustomerPermissionService $permissions,
    ) {}

    public function mayManageDocument(?User $actor, EnterpriseWikiDocument $document): bool
    {
        return $actor instanceof User
            && $this->guard->allows($actor)
            && (int) $actor->customer_id === (int) $document->customer_id
            && $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE)
            && $actor->canDeleteEnterpriseWikiDocument($document);
    }

    public function assertMayManageDocument(User $actor, EnterpriseWikiDocument $document, HumanDecision $decision): void
    {
        $this->guard->assert($actor, $decision);

        if (! $this->mayManageDocument($actor, $document)) {
            throw new AuthorizationException('This user may not administer this Wiki source document.');
        }
    }

    /**
     * A person of this customer — no source administration needed. The upload service asks only
     * this, because Kvalitet puts files into the same store under quality.create/quality.edit
     * (F3b); the Wiki's own entries add wiki.source.manage through assertMayAdministerSources().
     */
    public function assertPersonOfCustomer(User $actor, int $customerId, HumanDecision $decision): User
    {
        $actor = $this->guard->assert($actor, $decision);

        if ((int) $actor->customer_id !== $customerId) {
            throw new AuthorizationException('This user does not belong to the customer of this Wiki source.');
        }

        return $actor;
    }

    /** Starting a run or naming an owner: a person of this customer holding wiki.source.manage. */
    public function assertMayAdministerSources(User $actor, int $customerId, HumanDecision $decision): User
    {
        $actor = $this->assertPersonOfCustomer($actor, $customerId, $decision);

        if (! $this->permissions->has($actor, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE)) {
            throw new AuthorizationException('This user may not administer Wiki sources.');
        }

        return $actor;
    }

    /**
     * Whom $actor may make the owner of a document of this customer. Nobody is always allowed. The
     * actor themselves when their role may own a document. Anyone else only when the actor may
     * assign owners, and then only an active person of the same customer whose role may own one —
     * never an integration (F3b, §4.2).
     */
    public function assertMayNameOwner(User $actor, int $customerId, ?int $ownerUserId): void
    {
        if ($ownerUserId === null) {
            return;
        }

        if ($ownerUserId === (int) $actor->id) {
            if (! $actor->canBeEnterpriseWikiDocumentOwner()) {
                throw $this->invalidOwner();
            }

            return;
        }

        if (! $actor->canAssignEnterpriseWikiDocumentOwner()) {
            throw new AuthorizationException('This user may not name another person as document owner.');
        }

        $owner = User::query()
            ->whereKey($ownerUserId)
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->first();

        if ($owner === null || $owner->isIntegration() || ! $owner->canBeEnterpriseWikiDocumentOwner()) {
            throw $this->invalidOwner();
        }
    }

    /** A run is administered through the document it was made from; any other run by nobody here. */
    public function assertMayManageRun(User $actor, EnterpriseWikiIngestRun $run, HumanDecision $decision): void
    {
        $this->guard->assert($actor, $decision);

        // Re-read: a caller may hold a partial row (id and status only, under its own lock).
        $run = EnterpriseWikiIngestRun::query()->find($run->getKey());

        $document = $run?->source_type === EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT
            ? EnterpriseWikiDocument::query()->where('customer_id', $run->customer_id)->find($run->source_id)
            : null;

        if (! $document instanceof EnterpriseWikiDocument || ! $this->mayManageDocument($actor, $document)) {
            throw new AuthorizationException('This user may not administer this Wiki run.');
        }
    }

    private function invalidOwner(): ValidationException
    {
        return ValidationException::withMessages([
            'owner_user_id' => __('procynia.wiki.document_owner_invalid'),
        ]);
    }
}
