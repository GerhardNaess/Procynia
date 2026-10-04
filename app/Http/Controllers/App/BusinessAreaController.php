<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BusinessArea;
use App\Models\Risk;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The customer's fagområder, administered in Kundemiljø → Tilganger beside the customer's roles.
 *
 * System Owner only, on the same gate as the roles themselves: an area grants nothing until a role
 * carries it, and roles are System Owner's to edit. Which roles reach an area is set on the role
 * (CustomerRoleController) — explicitly or with «Alle» — so this surface only names areas.
 *
 * Nothing here reads risk content. The administrator learns that an area is in use when deleting
 * it is refused, and nothing more — no counts, no titles.
 */
class BusinessAreaController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $customerId = $this->administrationCustomerId($request);
        $validated = $this->validated($request);

        $name = trim($validated['name']);
        $this->guardUniqueName($customerId, $name, null);

        BusinessArea::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => $this->normalizedDescription($validated['description'] ?? null),
        ]);

        return $this->backToPermissions();
    }

    public function update(Request $request, BusinessArea $businessArea): RedirectResponse
    {
        $customerId = $this->administrationCustomerId($request);

        abort_unless((int) $businessArea->customer_id === $customerId, 404);

        $validated = $this->validated($request);
        $name = trim($validated['name']);
        $this->guardUniqueName($customerId, $name, (int) $businessArea->id);

        $businessArea->fill([
            'name' => $name,
            'description' => $this->normalizedDescription($validated['description'] ?? null),
        ])->save();

        return $this->backToPermissions();
    }

    /**
     * An area that still holds risks cannot be deleted: the risks would either go with it or be
     * left in no one's scope, and neither is acceptable. When another module starts scoping by
     * fagområde, its own "in use" check belongs here too. Role links go with the area (FK cascade).
     */
    public function destroy(Request $request, BusinessArea $businessArea): RedirectResponse
    {
        $customerId = $this->administrationCustomerId($request);

        abort_unless((int) $businessArea->customer_id === $customerId, 404);

        if (Risk::query()->where('business_area_id', $businessArea->id)->exists()) {
            return $this->backToPermissions()->with('error', __('procynia.customer_env.roles.business_areas.in_use'));
        }

        $businessArea->delete();

        return $this->backToPermissions();
    }

    private function administrationCustomerId(Request $request): int
    {
        /** @var User|null $actor */
        $actor = $request->user();
        $customerId = $this->customerContext->currentCustomerId($actor);

        abort_unless(
            $actor instanceof User
            && $actor->isSystemOwner()
            && $customerId !== null,
            403,
        );

        return $customerId;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function guardUniqueName(int $customerId, string $name, ?int $ignoreId): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('procynia.customer_env.roles.business_areas.name_required'),
            ]);
        }

        $query = BusinessArea::query()
            ->forCustomer($customerId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('procynia.customer_env.roles.business_areas.name_taken'),
            ]);
        }
    }

    private function normalizedDescription(?string $value): ?string
    {
        $description = trim((string) $value);

        return $description !== '' ? $description : null;
    }

    private function backToPermissions(): RedirectResponse
    {
        return redirect()->route('app.customer-environment.index', ['tab' => 'permissions']);
    }
}
