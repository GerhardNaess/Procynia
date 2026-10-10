<?php

namespace App\Services\ManagementReview;

use App\Models\BusinessArea;
use App\Models\ManagementReview;
use App\Models\User;
use App\Services\ManagementReview\Sections\ComplianceSection;
use App\Services\ManagementReview\Sections\ContextChangesSection;
use App\Services\ManagementReview\Sections\ImprovementsSection;
use App\Services\ManagementReview\Sections\ObjectivesSection;
use App\Services\ManagementReview\Sections\PreviousDecisionsSection;
use App\Services\ManagementReview\Sections\QualitySection;
use App\Services\ManagementReview\Sections\RisksSection;
use App\Services\ManagementReview\Sections\SuppliersSection;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\BusinessAreaGrants;
use App\Support\CustomerPermissionCatalog;

/**
 * The sections of a review, in order, and the gate in front of each (plan §3.4, §8.2).
 *
 * type:
 *  - own: built from the reviews themselves (Tidligere beslutninger). Read with management_review.view.
 *  - manual: the management's own text. A manual section may have a supporting basis from a module
 *    (Endringer i forhold ← Etterlevelse), gated by that module; the text and judgement are not.
 *  - module: built from another module. The basis AND the judgement and comment on it are shown only
 *    to someone who may read that module's data — never through the review.
 *
 * Gates read the module's own view permission and, where the module is area-scoped, the fagområder
 * BusinessAreaGrants gives the person. A live draft also requires the customer to hold the module
 * (module_unavailable otherwise). A finalized snapshot does not: history does not disappear when an
 * option is cancelled — the permission still decides.
 */
final class ManagementReviewSectionCatalog
{
    public const TYPE_OWN = 'own';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_MODULE = 'module';

    public const STATE_AVAILABLE = 'available';

    public const STATE_MODULE_UNAVAILABLE = 'module_unavailable';

    public const STATE_NO_ACCESS = 'no_access';

    /** A snapshot section the person who finalized could not read: it was never captured. */
    public const STATE_NOT_CAPTURED = 'not_captured';

    /** The pseudo-section that records the review's own decisions in its snapshot. */
    public const DECISIONS = 'decisions';

    public const DEFINITIONS = [
        'previous_decisions' => ['type' => self::TYPE_OWN, 'builder' => PreviousDecisionsSection::class],
        'context_changes' => [
            'type' => self::TYPE_MANUAL, 'builder' => ContextChangesSection::class,
            'module' => 'compliance', 'permission' => CustomerPermissionCatalog::COMPLIANCE_VIEW,
        ],
        'stakeholder_feedback' => ['type' => self::TYPE_MANUAL, 'framework_only' => true],
        'objectives' => [
            'type' => self::TYPE_MODULE, 'builder' => ObjectivesSection::class,
            'module' => 'objectives', 'permission' => CustomerPermissionCatalog::OBJECTIVE_VIEW, 'area_scoped' => true,
        ],
        'risks' => [
            'type' => self::TYPE_MODULE, 'builder' => RisksSection::class,
            'module' => 'risk', 'permission' => CustomerPermissionCatalog::RISK_VIEW, 'area_scoped' => true,
        ],
        'improvements' => [
            'type' => self::TYPE_MODULE, 'builder' => ImprovementsSection::class,
            'module' => 'improvements', 'permission' => CustomerPermissionCatalog::IMPROVEMENT_VIEW, 'area_scoped' => true,
        ],
        'compliance' => [
            'type' => self::TYPE_MODULE, 'builder' => ComplianceSection::class,
            'module' => 'compliance', 'permission' => CustomerPermissionCatalog::COMPLIANCE_VIEW,
        ],
        'quality' => [
            'type' => self::TYPE_MODULE, 'builder' => QualitySection::class,
            'module' => 'quality', 'permission' => CustomerPermissionCatalog::QUALITY_VIEW,
        ],
        'suppliers' => [
            'type' => self::TYPE_MODULE, 'builder' => SuppliersSection::class,
            'module' => 'supplier', 'permission' => CustomerPermissionCatalog::SUPPLIER_VIEW,
        ],
        'resources' => ['type' => self::TYPE_MANUAL],
    ];

    public function __construct(
        private readonly ModuleEntitlementService $entitlements,
        private readonly BusinessAreaGrants $grants,
        private readonly ManagementReviewAccessService $access,
    ) {}

    /**
     * The sections of this review, in order. A framework-only section (stakeholder feedback) is
     * included only when a chosen framework's coverage mapping asks for it — choosing a framework
     * without a mapping never adds a section.
     *
     * @return list<string>
     */
    public function keysFor(ManagementReview $review): array
    {
        $mapped = [];

        foreach ((array) ($review->frameworks ?? []) as $framework) {
            foreach ((array) config('management_review.framework_coverage.'.$framework.'.inputs', []) as $input) {
                array_push($mapped, ...(array) $input['sections']);
            }
        }

        return array_values(array_filter(
            array_keys(self::DEFINITIONS),
            fn (string $key): bool => ! (self::DEFINITIONS[$key]['framework_only'] ?? false) || in_array($key, $mapped, true),
        ));
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        return self::DEFINITIONS[$key];
    }

    public function hasBasis(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]['builder']);
    }

    /** Whether the judgement and comment follow the basis gate — true for module sections only. */
    public function judgementFollowsBasis(string $key): bool
    {
        return self::DEFINITIONS[$key]['type'] === self::TYPE_MODULE;
    }

    public function isAreaScoped(string $key): bool
    {
        return (bool) (self::DEFINITIONS[$key]['area_scoped'] ?? false);
    }

    /**
     * The state of a section's basis for this person, live: module_unavailable when the customer does
     * not hold the module, no_access when they may not read it, available otherwise.
     */
    public function liveState(User $user, string $key): string
    {
        $definition = self::DEFINITIONS[$key];

        if (! isset($definition['module'])) {
            return self::STATE_AVAILABLE;
        }

        $customer = $user->customer;

        if ($customer === null || ! $this->entitlements->hasModule($customer, $definition['module'])) {
            return self::STATE_MODULE_UNAVAILABLE;
        }

        return $this->canRead($user, $key) ? self::STATE_AVAILABLE : self::STATE_NO_ACCESS;
    }

    /**
     * Whether the person may read the section's basis, by permission (and fagområde) alone — no
     * entitlement check. Sections without a module basis are always readable with the review.
     */
    public function canRead(User $user, string $key): bool
    {
        $definition = self::DEFINITIONS[$key];

        if (! isset($definition['permission'])) {
            return true;
        }

        if (! $this->access->holds($user, $definition['permission'])) {
            return false;
        }

        return ! $this->isAreaScoped($key) || $this->areaIds($user, $key) !== [];
    }

    /**
     * For an area-scoped section, the fagområder the person may read it in — the module's own view
     * permission through their own roles. Null for a section that is not area-scoped.
     *
     * @return list<int>|null
     */
    public function areaIds(User $user, string $key): ?array
    {
        if (! $this->isAreaScoped($key)) {
            return null;
        }

        if ($user->customer_id === null) {
            return [];
        }

        return $this->grants->areaIdsFor((int) $user->customer_id, (int) $user->id, self::DEFINITIONS[$key]['permission']);
    }

    /**
     * Whether the person reaches every fagområde of the scope in an area-scoped section — only then
     * may the page speak of the whole picture.
     *
     * @param  list<int>|null  $scopeAreaIds
     */
    public function reachesWholeScope(User $user, string $key, ?array $scopeAreaIds): bool
    {
        if (! $this->isAreaScoped($key) || $user->customer_id === null) {
            return true;
        }

        $permission = self::DEFINITIONS[$key]['permission'];

        if ($scopeAreaIds === null) {
            // «Hele virksomheten» at this moment: «Alle», or every fagområde the customer has now. A
            // basis is read at a point in time, so reaching every area that exists is the whole.
            $scopeAreaIds = BusinessArea::query()->forCustomer((int) $user->customer_id)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if ($this->grants->reachesAllAreas((int) $user->customer_id, (int) $user->id, $permission)) {
                return true;
            }
        }

        return array_diff($scopeAreaIds, $this->areaIds($user, $key) ?? []) === [];
    }

    /** The Avvik og forbedringer fagområder the person may read cases in — for decision rows. */
    public function caseAreaIds(User $user): array
    {
        if ($user->customer_id === null || ! $this->access->holds($user, CustomerPermissionCatalog::IMPROVEMENT_VIEW)) {
            return [];
        }

        return $this->grants->areaIdsFor((int) $user->customer_id, (int) $user->id, CustomerPermissionCatalog::IMPROVEMENT_VIEW);
    }

    public function builder(string $key): SectionBuilder
    {
        return app(self::DEFINITIONS[$key]['builder']);
    }
}
