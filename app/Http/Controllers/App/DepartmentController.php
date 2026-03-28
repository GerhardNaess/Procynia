<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [, $customerId] = $this->customerAdminContext($request);

        $departments = $this->scopedCustomerDepartmentsQuery($customerId)
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
                'is_active' => (bool) $department->is_active,
                'created_at' => optional($department->created_at)?->toIso8601String(),
                'edit_url' => route('app.departments.edit', ['department' => $department->id]),
                'toggle_active_url' => route('app.departments.toggle-active', ['department' => $department->id]),
            ])
            ->all();

        return Inertia::render('App/Departments/Index', [
            'departments' => $departments,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->customerAdminContext($request);

        return Inertia::render('App/Departments/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'customer_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'cpv_whitelist' => ['prohibited'],
            'keywords' => ['prohibited'],
        ]);

        $name = Str::squish($validated['name']);
        $description = $this->normalizeDescription($validated['description'] ?? null);

        $this->ensureUniqueDepartmentName($customerId, $name);

        Department::query()->create([
            'customer_id' => $customerId,
            'name' => $name,
            'description' => $description,
            'is_active' => true,
        ]);

        return redirect()
            ->route('app.departments.index')
            ->with('success', 'Avdelingen ble opprettet.');
    }

    public function edit(Request $request, int $department): Response
    {
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerDepartment($customerId, $department);

        return Inertia::render('App/Departments/Edit', [
            'department' => [
                'id' => $record->id,
                'name' => $record->name,
                'description' => $record->description,
                'is_active' => (bool) $record->is_active,
                'update_url' => route('app.departments.update', ['department' => $record->id]),
                'toggle_active_url' => route('app.departments.toggle-active', ['department' => $record->id]),
            ],
        ]);
    }

    public function update(Request $request, int $department): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerDepartment($customerId, $department);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'customer_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'cpv_whitelist' => ['prohibited'],
            'keywords' => ['prohibited'],
        ]);

        $name = Str::squish($validated['name']);
        $description = $this->normalizeDescription($validated['description'] ?? null);

        $this->ensureUniqueDepartmentName($customerId, $name, $record->id);

        $record->fill([
            'name' => $name,
            'description' => $description,
        ])->save();

        return redirect()
            ->route('app.departments.index')
            ->with('success', 'Avdelingen ble oppdatert.');
    }

    public function toggleActive(Request $request, int $department): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerDepartment($customerId, $department);

        $record->forceFill([
            'is_active' => ! (bool) $record->is_active,
        ])->save();

        return back()->with('success', $record->is_active ? 'Avdelingen ble aktivert.' : 'Avdelingen ble deaktivert.');
    }

    private function customerAdminContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $this->customerContext->isCustomerAdmin($user)
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function scopedCustomerDepartmentsQuery(int $customerId)
    {
        return Department::query()->where('customer_id', $customerId);
    }

    private function scopedCustomerDepartment(int $customerId, int $departmentId): Department
    {
        return $this->scopedCustomerDepartmentsQuery($customerId)
            ->whereKey($departmentId)
            ->firstOrFail();
    }

    private function ensureUniqueDepartmentName(int $customerId, string $name, ?int $ignoreId = null): void
    {
        $query = $this->scopedCustomerDepartmentsQuery($customerId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Denne avdelingen finnes allerede for kunden.',
            ]);
        }
    }

    private function normalizeDescription(?string $value): ?string
    {
        $description = trim((string) $value);

        return $description === '' ? null : $description;
    }
}
