<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\SavedNoticeAccessService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which of a person's own notifications they may still read.
 *
 * A notification is written when its recipient could see what it is about, but it keeps its title
 * and message after they stop being able to: the customer drops a module, a role loses its view
 * permission, a case is no longer theirs to see. Without this the bell would keep naming Wiki pages,
 * cases or suppliers the person can no longer open. So access is decided again on every read, in SQL,
 * before anything is limited or counted — a hidden row moves neither the list nor the unread count.
 *
 * The prefix of event_type names the module, and the module's own access answer decides it. Where a
 * module restricts reading object by object, the prefix alone is not enough and the object is
 * checked too:
 *
 *  - bid.*            Anbud module, and the case (saved_notice_id) is one SavedNoticeAccessService
 *                     lets the person see. Case visibility is per person, so this is the case that
 *                     matters most. A notification whose case is gone is hidden.
 *  - watch_profile.*  Anbud module. Its notice is shared Doffin data and the profile is the
 *                     person's own; the link goes to the module's own list.
 *  - wiki.*           Wiki module and wiki.view. Every page of the customer is readable with
 *                     wiki.view — approval status never hides a page — so no per-page check exists to
 *                     repeat.
 *  - supplier.*       Leverandøroppfølging module and supplier.view (canReadFromAnotherModule), and
 *                     metadata.supplier_id is one visibleSuppliers() returns, so a deleted supplier's
 *                     name goes too.
 *
 * Anything else — AI quota, billing — is about the account, not a module object, and passes.
 *
 * This hides; it does not authorize. target_url is a link into an ordinary route, which runs its own
 * guards whether or not the bell showed it.
 *
 * Fase 6D: a module that writes notifications adds its prefix and its rule here.
 */
class UserNotificationAccessScope
{
    /** The module prefixes this scope decides; an event outside them is not module data. */
    private const GATED_PREFIXES = ['bid.', 'watch_profile.', 'wiki.', 'supplier.'];

    public function __construct(
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly SupplierAccessService $supplierAccess,
    ) {}

    /** @param  Builder<UserNotification>  $query */
    public function apply(Builder $query, User $user): Builder
    {
        $customer = $user->customer;
        $modules = $customer !== null ? $this->entitlements->modulesFor($customer) : [];
        $tender = in_array('tender', $modules, true);
        $wiki = in_array('wiki', $modules, true) && $this->permissions->has($user, CustomerPermissionCatalog::WIKI_VIEW);
        // canReadFromAnotherModule() asks for the module too; asked once here through $modules
        // would be the same answer, so the access service stays the one that decides.
        $supplier = $this->supplierAccess->canReadFromAnotherModule($user);

        return $query->where(function (Builder $visible) use ($user, $tender, $wiki, $supplier): void {
            $visible
                ->whereNull('event_type')
                ->orWhere(function (Builder $ungated): void {
                    foreach (self::GATED_PREFIXES as $prefix) {
                        $ungated->where('event_type', 'not like', $prefix.'%');
                    }
                });

            if ($tender) {
                $visible
                    ->orWhere(fn (Builder $bid) => $bid
                        ->where('event_type', 'like', 'bid.%')
                        ->whereIn('saved_notice_id', $this->savedNoticeAccess->visibleQueryFor($user)->select('id')))
                    ->orWhere('event_type', 'like', 'watch_profile.%');
            }

            if ($wiki) {
                $visible->orWhere('event_type', 'like', 'wiki.%');
            }

            if ($supplier) {
                // Compared as text: metadata is JSON, and a cast of a malformed value would fail the
                // whole bell rather than hide one row.
                $supplierIds = $this->supplierAccess->visibleSuppliers($user)->selectRaw('CAST(suppliers.id AS TEXT)');

                $visible->orWhere(fn (Builder $rows) => $rows
                    ->where('event_type', 'like', 'supplier.%')
                    ->whereRaw("user_notifications.metadata->>'supplier_id' IN ({$supplierIds->toSql()})", $supplierIds->getBindings()));
            }
        });
    }
}
