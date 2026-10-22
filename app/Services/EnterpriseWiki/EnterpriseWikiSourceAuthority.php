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

/**
 * Who may administer one Wiki source document and its runs — delete it, cancel a run of it, clear
 * a finished run from Kjøringer.
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
}
