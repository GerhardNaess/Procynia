<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\CustomerPermissionCatalog;

/**
 * The Enterprise Wiki half of the customer's own permission vocabulary, in one place.
 *
 * Enterprise Wiki already has an authority model of its own — page ownership, document ownership,
 * the reviewer handover, the four-eyes rule, status transitions. None of that moves here. A
 * permission says the customer has given this person a job in the Wiki at all; everything the Wiki
 * already asks about THIS page, THIS document and THIS version is asked afterwards and unchanged.
 *
 * Both gates must pass, and the stricter one still decides. wiki.delete does not make a page
 * yours to delete — canDeleteEnterpriseWikiPage() still has to agree — and ownership does not
 * substitute for the permission either.
 *
 * Using controllers declare `private readonly CustomerPermissionService $customerPermissions`.
 */
trait AuthorizesWikiPermissions
{
    /**
     * Whether the user holds one of the Wiki permissions.
     *
     * CustomerPermissionService is the only thing asked: it resolves the customer's own roles and
     * carries System Owner's unconditional grant, so neither is restated at a call site.
     */
    private function mayWiki(?User $user, string $permissionKey): bool
    {
        return $user instanceof User && $this->customerPermissions->has($user, $permissionKey);
    }

    /**
     * The authoritative gate. Every Wiki action opens with one of these, before the tenant check
     * and before validation: a permission the user does not hold is a 403 whatever else is true.
     */
    private function authorizeWikiPermission(?User $user, string $permissionKey): void
    {
        abort_unless($this->mayWiki($user, $permissionKey), 403);
    }

    /**
     * What the Wiki React pages are allowed to offer.
     *
     * Only the permission half. A page still receives the Wiki's own per-object answers
     * (`can_delete_page`, `can_approve_final`, …), and those already have the permission folded
     * into them — this payload is for controls that have no object to ask about, such as uploading
     * a source document.
     *
     * @return array<string, bool>
     */
    private function wikiPermissionPayload(?User $user): array
    {
        return [
            'can_view' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_VIEW),
            'can_edit' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_EDIT),
            'can_review' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_REVIEW),
            'can_approve' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_APPROVE),
            'can_delete' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_DELETE),
            'can_manage_sources' => $this->mayWiki($user, CustomerPermissionCatalog::WIKI_SOURCE_MANAGE),
        ];
    }
}
