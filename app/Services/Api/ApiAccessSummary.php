<?php

namespace App\Services\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\User;
use App\Services\Improvements\ImprovementCaseAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
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
    ) {}

    /**
     * @return array<string, array{entitled: bool, readable: bool, business_areas: 'all'|list<array{id: int, name: string}>}>
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
        ];
    }

    /**
     * @param  callable(): bool  $reachesAll
     * @param  callable(): EloquentCollection<int, BusinessArea>  $areas
     * @return array{entitled: bool, readable: bool, business_areas: 'all'|list<array{id: int, name: string}>}
     */
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
