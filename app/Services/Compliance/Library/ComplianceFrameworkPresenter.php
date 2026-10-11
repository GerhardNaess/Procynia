<?php

namespace App\Services\Compliance\Library;

use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\Compliance\FrameworkModuleLinks;
use Illuminate\Support\Collection;

/**
 * What the Kravbibliotek pages and a library requirement's page show: the framework, its tree, and —
 * once the customer has activated it — where each requirement sits in the customer's register,
 * with its relevance or Kontrollvalg.
 *
 * The library is the same for every customer and carries no customer data. Everything about the
 * customer's register is read from requirements reached through
 * ComplianceAccessService::visibleRequirements(), so nothing of another customer can reach a page.
 * Compliance figures are not the library's: they live in Etterlevelsesvurdering.
 */
class ComplianceFrameworkPresenter
{
    /** @var array<int, array<string, string>> per user id, for one request */
    private array $moduleLinks = [];

    public function __construct(
        private readonly ComplianceAccessService $access,
        private readonly ModuleEntitlementService $entitlements,
        private readonly CustomerPermissionService $permissions,
    ) {}

    /** @return array<string, mixed> */
    public function framework(ComplianceFramework $framework): array
    {
        return [
            'id' => (int) $framework->id,
            'key' => $framework->key,
            'reference' => $framework->reference,
            'edition' => $framework->edition,
            'label' => $framework->label(),
            'name' => $framework->name,
            'description' => $framework->description,
            'scope_note' => $framework->scope_note,
            'content_verified' => (bool) $framework->content_verified,
            'verification_note' => $framework->verification_note,
            'url' => route('app.compliance.library.show', ['frameworkId' => $framework->id]),
        ];
    }

    /**
     * The tree as a flat list in the standard's order; the page nests it by parent_id. Withdrawn
     * nodes are left out unless the customer still has a requirement on one.
     *
     * @param  Collection<int, ComplianceFrameworkNode>  $nodes
     * @param  array<int, array<string, mixed>>  $register  keyed by node id
     * @return list<array<string, mixed>>
     */
    public function nodes(Collection $nodes, User $user, array $register = []): array
    {
        $links = $this->availableModuleLinks($user);

        return $nodes
            ->filter(fn (ComplianceFrameworkNode $node): bool => ! $node->isWithdrawn() || isset($register[(int) $node->id]))
            ->map(fn (ComplianceFrameworkNode $node): array => [
                'id' => (int) $node->id,
                'parent_id' => $node->parent_id !== null ? (int) $node->parent_id : null,
                'clause' => $node->clause,
                'kind' => $node->kind,
                'depth' => (int) $node->depth,
                'title' => $node->title,
            ] + ($node->isRequirement() ? $this->requirementDetails($node, $links) + [
                'requirement' => $register[(int) $node->id] ?? null,
            ] : []))
            ->values()
            ->all();
    }

    /**
     * The library side of one requirement for its page: guidance, documentation, normative
     * character and where in Procynia the work is done.
     *
     * @return array<string, mixed>|null
     */
    public function libraryContext(ComplianceRequirement $requirement, User $user): ?array
    {
        if (! $requirement->isFromLibrary()) {
            return null;
        }

        $node = $requirement->frameworkNode()->with('framework')->first();

        if ($node === null) {
            return null;
        }

        return [
            'framework_label' => $node->framework->label(),
            'framework_name' => $node->framework->name,
            'framework_url' => route('app.compliance.library.show', ['frameworkId' => $node->framework_id]),
            'content_verified' => (bool) $node->framework->content_verified,
            'clause' => $node->clause,
            'withdrawn' => $node->isWithdrawn(),
        ] + $this->requirementDetails($node, $this->availableModuleLinks($user));
    }

    /**
     * The customer's register for one activated framework, keyed by library node id: where each
     * requirement is in the register and its relevance or Kontrollvalg. Whether it is met is not
     * shown here — that is Etterlevelsesvurdering's — so the library stays a place to choose and
     * explore standards, not a second dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function register(ComplianceSource $source, User $user): array
    {
        $register = [];

        $this->access->visibleRequirements($user)
            ->where('compliance_requirements.source_id', $source->id)
            ->whereNotNull('compliance_requirements.framework_node_id')
            ->get(['compliance_requirements.id', 'compliance_requirements.framework_node_id', 'compliance_requirements.status', 'compliance_requirements.applicability'])
            ->each(function (ComplianceRequirement $requirement) use (&$register): void {
                $register[(int) $requirement->framework_node_id] = [
                    'id' => (int) $requirement->id,
                    'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id]),
                    'status' => $requirement->status,
                    'applicability' => $requirement->applicability,
                ];
            });

        return $register;
    }

    /**
     * @param  array<string, string>  $links  available module links, key → href
     * @return array<string, mixed>
     */
    private function requirementDetails(ComplianceFrameworkNode $node, array $links): array
    {
        return [
            'description' => $node->description,
            'guidance' => $node->guidance,
            'documentation' => array_values($node->suggested_documentation ?? []),
            'requirement_type' => $node->requirement_type,
            'documented_information' => $node->documented_information,
            'applicability_rule' => $node->applicability_rule,
            'withdrawn' => $node->isWithdrawn(),
            'modules' => array_values(array_map(
                fn (string $key): array => ['key' => $key, 'href' => $links[$key]],
                array_values(array_filter($node->related_modules ?? [], fn (mixed $key): bool => isset($links[$key]))),
            )),
        ];
    }

    /**
     * The module hints this person can follow: the customer holds the module and the person holds
     * the permission that opens it. Computed once per request.
     *
     * @return array<string, string> key → href
     */
    private function availableModuleLinks(User $user): array
    {
        $cacheKey = (int) $user->id;

        if (isset($this->moduleLinks[$cacheKey])) {
            return $this->moduleLinks[$cacheKey];
        }

        $customer = $user->customer;
        $available = [];

        foreach (FrameworkModuleLinks::LINKS as $key => $link) {
            if ($customer !== null
                && $this->entitlements->hasModule($customer, $link['module'])
                && ($link['permission'] === null || $this->permissions->has($user, $link['permission']))) {
                $available[$key] = $link['href'];
            }
        }

        return $this->moduleLinks[$cacheKey] = $available;
    }
}
