<?php

namespace App\Services\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\EnterpriseWiki\EnterpriseWikiPublishedKnowledgeReader;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Quality\QualityProcessContextReader;
use App\Services\Risk\RiskAccessService;
use App\Services\Suppliers\SupplierAccessService;
use App\Support\ApiOperationCatalog;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * What an integration identity can read through the API, module by module — for GET /api/v1/me
 * and for the System Owner in Kundemiljø → Integrasjoner, so the two never disagree.
 *
 * Only modules the API serves are described, through the same rules the endpoints apply: a module
 * the customer has not ordered, or the identity cannot open, reads as not readable with no
 * fagområder.
 */
class ApiAccessSummary
{
    public function __construct(
        private readonly ModuleEntitlementService $entitlements,
        private readonly RiskAccessService $risks,
        private readonly ImprovementCaseAccessService $improvements,
        private readonly ObjectiveAccessService $objectives,
        private readonly ManagementReviewAccessService $managementReviews,
        private readonly ComplianceAccessService $compliance,
        private readonly SupplierAccessService $suppliers,
        private readonly QualityProcessContextReader $quality,
        private readonly EnterpriseWikiPublishedKnowledgeReader $wiki,
    ) {}

    /**
     * @return array<string, array{entitled: bool, readable: bool, business_areas: 'all'|list<array{id: int, name: string}>|null}>
     */
    public function for(User $identity, Customer $customer): array
    {
        return [
            'risk' => $this->module(
                $this->entitlements->hasModule($customer, 'risk') && $this->risks->canOpenModule($identity),
                $this->entitlements->hasModule($customer, 'risk'),
                fn (): bool => $this->risks->reachesAllAreas($identity, CustomerPermissionCatalog::RISK_VIEW),
                fn (): EloquentCollection => $this->risks->areasFor($identity, CustomerPermissionCatalog::RISK_VIEW),
            ),
            // GET /api/v1/deviations: Avvik og forbedringer, avvik only.
            'deviations' => $this->module(
                $this->entitlements->hasModule($customer, 'improvements') && $this->improvements->canOpenModule($identity),
                $this->entitlements->hasModule($customer, 'improvements'),
                fn (): bool => $this->improvements->reachesAllAreas($identity, CustomerPermissionCatalog::IMPROVEMENT_VIEW),
                fn (): EloquentCollection => $this->improvements->areasFor($identity, CustomerPermissionCatalog::IMPROVEMENT_VIEW),
            ),
            // GET /api/v1/objectives and GET /api/v1/kpis: Mål og KPI.
            'objectives' => $this->module(
                $this->entitlements->hasModule($customer, 'objectives') && $this->objectives->canOpenModule($identity),
                $this->entitlements->hasModule($customer, 'objectives'),
                fn (): bool => $this->objectives->reachesAllAreas($identity, CustomerPermissionCatalog::OBJECTIVE_VIEW),
                fn (): EloquentCollection => $this->objectives->areasFor($identity, CustomerPermissionCatalog::OBJECTIVE_VIEW),
            ),
            // GET /api/v1/management-reviews: Ledelsens gjennomgang. Customer-wide: no fagområder.
            'management_review' => $this->customerWide(
                $this->entitlements->hasModule($customer, 'management_review') && $this->managementReviews->canOpenModule($identity),
                $this->entitlements->hasModule($customer, 'management_review'),
            ),
            // GET /api/v1/compliance/…: Etterlevelse og revisjon. Customer-wide, an explicit-grant domain.
            'compliance' => $this->customerWide(
                $this->compliance->canReadFromAnotherModule($identity),
                $this->entitlements->hasModule($customer, 'compliance'),
            ),
            // GET /api/v1/suppliers…: Leverandøroppfølging. Customer-wide, an explicit-grant domain.
            'suppliers' => $this->customerWide(
                $this->suppliers->canReadFromAnotherModule($identity),
                $this->entitlements->hasModule($customer, 'supplier'),
            ),
            // GET /api/v1/quality/…: Kvalitet. Customer-wide.
            'quality' => $this->customerWide(
                $this->quality->canRead($identity),
                $this->entitlements->hasModule($customer, 'quality'),
            ),
            // GET /api/v1/wiki/…: Enterprise Wiki, published knowledge. Customer-wide.
            'wiki' => $this->customerWide(
                $this->wiki->canRead($identity),
                $this->entitlements->hasModule($customer, 'wiki'),
            ),
            // GET /api/v1/tender/…: Anbud. Customer-wide, every case, once tender.read is on (D23).
            'tender' => $this->customerWide(
                $this->entitlements->hasModule($customer, 'tender') && $this->tenderReadSwitchedOn($identity, $customer),
                $this->entitlements->hasModule($customer, 'tender'),
            ),
        ];
    }

    /**
     * The fagområder the identity reads in, with the modules it reads them in — the same modules,
     * rules and areas as for(), with «all» spelled out as the customer's areas. An area the identity
     * reaches in no module the API serves is not listed. Ordered by name, then id.
     *
     * @return list<array{id: int, name: string, modules: list<string>}>
     */
    public function readableAreas(User $identity, Customer $customer): array
    {
        $sources = [
            'risk' => fn (): EloquentCollection => $this->risks->areasFor($identity, CustomerPermissionCatalog::RISK_VIEW),
            'deviations' => fn (): EloquentCollection => $this->improvements->areasFor($identity, CustomerPermissionCatalog::IMPROVEMENT_VIEW),
            'objectives' => fn (): EloquentCollection => $this->objectives->areasFor($identity, CustomerPermissionCatalog::OBJECTIVE_VIEW),
        ];
        $summary = $this->for($identity, $customer);
        $areas = [];

        foreach ($sources as $module => $areasFor) {
            if (! $summary[$module]['readable']) {
                continue;
            }

            foreach ($areasFor() as $area) {
                // The access services already keep to the identity's customer; checked again here.
                if ((int) $area->customer_id !== (int) $customer->id) {
                    continue;
                }

                $areas[(int) $area->id] ??= ['id' => (int) $area->id, 'name' => $area->name, 'modules' => []];
                $areas[(int) $area->id]['modules'][] = $module;
            }
        }

        return collect($areas)->sortBy([['name', 'asc'], ['id', 'asc']])->values()->all();
    }

    /**
     * Whether the System Owner has switched tender.read on for the identity's active grant. Read
     * from the grant, not from IntegrationOperationAuthority, because Kundemiljø asks outside an
     * API request; in a request the endpoints still ask the authority, which also checks the client.
     */
    private function tenderReadSwitchedOn(User $identity, Customer $customer): bool
    {
        return $identity->isIntegration()
            && $identity->is_active
            && (int) $identity->customer_id === (int) $customer->id
            && ApiClientCustomerGrant::query()
                ->active()
                ->where('integration_user_id', (int) $identity->id)
                ->where('customer_id', (int) $customer->id)
                ->get()
                ->contains(fn (ApiClientCustomerGrant $grant): bool => $grant->allowsOperation(ApiOperationCatalog::TENDER_READ));
    }

    /**
     * @param  callable(): bool  $reachesAll
     * @param  callable(): EloquentCollection<int, BusinessArea>  $areas
     * @return array{entitled: bool, readable: bool, business_areas: 'all'|list<array{id: int, name: string}>}
     */
    /**
     * A module read across the whole customer, without fagområder: business_areas is null, never
     * a list, so it is not mistaken for «no areas reached».
     *
     * @return array{entitled: bool, readable: bool, business_areas: null}
     */
    private function customerWide(bool $readable, bool $entitled): array
    {
        return ['entitled' => $entitled, 'readable' => $readable, 'business_areas' => null];
    }

    private function module(bool $readable, bool $entitled, callable $reachesAll, callable $areas): array
    {
        return [
            'entitled' => $entitled,
            'readable' => $readable,
            'business_areas' => match (true) {
                ! $readable => [],
                $reachesAll() => 'all',
                default => $areas()
                    ->map(fn (BusinessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
                    ->values()
                    ->all(),
            },
        ];
    }
}
