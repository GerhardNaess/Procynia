<?php

namespace App\Services\Api;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerPermissionCatalog;

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
    ) {}

    /**
     * @return array{risk: array{entitled: bool, readable: bool, business_areas: 'all'|list<array{id: int, name: string}>}}
     */
    public function for(User $identity, Customer $customer): array
    {
        $riskEntitled = $this->entitlements->hasModule($customer, 'risk');
        $riskReadable = $riskEntitled && $this->risks->canOpenModule($identity);

        return [
            'risk' => [
                'entitled' => $riskEntitled,
                'readable' => $riskReadable,
                'business_areas' => match (true) {
                    ! $riskReadable => [],
                    $this->risks->reachesAllAreas($identity, CustomerPermissionCatalog::RISK_VIEW) => 'all',
                    default => $this->risks->areasFor($identity, CustomerPermissionCatalog::RISK_VIEW)
                        ->map(fn (BusinessArea $area): array => ['id' => (int) $area->id, 'name' => $area->name])
                        ->values()
                        ->all(),
                },
            ],
        ];
    }
}
