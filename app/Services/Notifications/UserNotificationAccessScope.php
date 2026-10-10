<?php

namespace App\Services\Notifications;

use App\Models\QualityItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Risk\RiskAccessService;
use App\Services\SavedNoticeAccessService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 *  - risk.*           Risiko module and risk.view, and metadata.risk_id is a risk in one of the
 *                     person's fagområder (RiskAccessService::visibleRisks()).
 *  - improvement.*    Avvik og forbedringer, and metadata.improvement_case_id is visible
 *                     (ImprovementCaseAccessService::visibleCases(), per fagområde).
 *  - compliance.*     Etterlevelse og revisjon, and the requirement or audit named in metadata is
 *                     visible (ComplianceAccessService).
 *  - quality.*        Kvalitet and quality.view, and metadata.quality_item_id is an item of the
 *                     customer that still exists.
 *  - objective.*      Mål og KPI, and metadata.objective_id is visible (ObjectiveAccessService,
 *                     per fagområde).
 *  - management_review.*  Ledelsens gjennomgåelse and management_review.view, and
 *                     metadata.management_review_id is visible (ManagementReviewAccessService).
 *
 * The same rules hold for fristpåminnelser, which carry their module's prefix and object.
 */
class UserNotificationAccessScope
{
    /** The module prefixes this scope decides; an event outside them is not module data. */
    private const GATED_PREFIXES = ['bid.', 'watch_profile.', 'wiki.', 'supplier.', 'risk.', 'improvement.', 'compliance.', 'quality.', 'objective.', 'management_review.'];

    public function __construct(
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
        private readonly SavedNoticeAccessService $savedNoticeAccess,
        private readonly SupplierAccessService $supplierAccess,
        private readonly RiskAccessService $riskAccess,
        private readonly ImprovementCaseAccessService $improvementAccess,
        private readonly ComplianceAccessService $complianceAccess,
        private readonly ObjectiveAccessService $objectiveAccess,
        private readonly ManagementReviewAccessService $managementReviewAccess,
    ) {}

    /** @param  Builder<UserNotification>  $query */
    public function apply(Builder $query, User $user): Builder
    {
        $customer = $user->customer;
        $modules = $customer !== null ? $this->entitlements->modulesFor($customer) : [];
        // The person's permissions read once — the same answer each module's canOpenModule() /
        // canReadFromAnotherModule() gives (customer + its *.view), without a role query per module
        // on every page the bell is drawn on.
        $permissions = $user->customer_id !== null ? $this->permissions->effectivePermissions($user) : [];
        $reads = fn (string $module, string $permission): bool => in_array($module, $modules, true) && in_array($permission, $permissions, true);
        $tender = in_array('tender', $modules, true);
        $wiki = $reads('wiki', CustomerPermissionCatalog::WIKI_VIEW);
        $supplier = $reads('supplier', CustomerPermissionCatalog::SUPPLIER_VIEW);
        $risk = $reads('risk', CustomerPermissionCatalog::RISK_VIEW);
        $improvement = $reads('improvements', CustomerPermissionCatalog::IMPROVEMENT_VIEW);
        $compliance = $reads('compliance', CustomerPermissionCatalog::COMPLIANCE_VIEW);
        $quality = $reads('quality', CustomerPermissionCatalog::QUALITY_VIEW);
        $objective = $reads('objectives', CustomerPermissionCatalog::OBJECTIVE_VIEW);
        $managementReview = $reads('management_review', CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW);

        return $query->where(function (Builder $visible) use ($user, $tender, $wiki, $supplier, $risk, $improvement, $compliance, $quality, $objective, $managementReview): void {
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

            if ($risk) {
                $this->allowObjects($visible, 'risk.', 'risk_id', $this->riskAccess->visibleRisks($user)->selectRaw('CAST(risks.id AS TEXT)'));
            }

            if ($improvement) {
                $this->allowObjects($visible, 'improvement.', 'improvement_case_id', $this->improvementAccess->visibleCases($user)->selectRaw('CAST(improvement_cases.id AS TEXT)'));
            }

            if ($compliance) {
                $this->allowObjects($visible, 'compliance.', 'compliance_requirement_id', $this->complianceAccess->visibleRequirements($user)->selectRaw('CAST(compliance_requirements.id AS TEXT)'));
                $this->allowObjects($visible, 'compliance.', 'compliance_audit_id', $this->complianceAccess->visibleAudits($user)->selectRaw('CAST(compliance_audits.id AS TEXT)'));
            }

            if ($quality) {
                $this->allowObjects($visible, 'quality.', 'quality_item_id', QualityItem::query()->where('quality_items.customer_id', (int) $user->customer_id)->selectRaw('CAST(quality_items.id AS TEXT)'));
            }

            if ($objective) {
                $this->allowObjects($visible, 'objective.', 'objective_id', $this->objectiveAccess->visibleObjectives($user)->selectRaw('CAST(objectives.id AS TEXT)'));
            }

            if ($managementReview) {
                $this->allowObjects($visible, 'management_review.', 'management_review_id', $this->managementReviewAccess->visibleReviews($user)->selectRaw('CAST(management_reviews.id AS TEXT)'));
            }
        });
    }

    /**
     * Rows of one module prefix whose object, named in metadata, is in the given visible set.
     * Compared as text: metadata is JSON, and a cast of a malformed value would fail the whole bell
     * rather than hide one row. A row without the key matches nothing and stays hidden.
     *
     * @param  Builder<Model>  $visibleIds  selecting one text column
     */
    private function allowObjects(Builder $visible, string $prefix, string $metadataKey, Builder $visibleIds): void
    {
        $visible->orWhere(fn (Builder $rows) => $rows
            ->where('event_type', 'like', $prefix.'%')
            ->whereRaw("user_notifications.metadata->>'{$metadataKey}' IN ({$visibleIds->toSql()})", $visibleIds->getBindings()));
    }
}
