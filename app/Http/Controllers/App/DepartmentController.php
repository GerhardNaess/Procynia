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
        [$actor, $customerId] = $this->customerBidManagerContext($request);

        $departments = $this->scopedCustomerDepartmentsQuery($customerId, $actor)
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
                'is_active' => (bool) $department->is_active,
                'user_count' => (int) $department->members_count,
                'created_at' => optional($department->created_at)?->toIso8601String(),
                'updated_at' => optional($department->updated_at)?->toIso8601String(),
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
        [$user] = $this->customerBidManagerContext($request);

        abort_unless($this->customerContext->canCreateCustomerDepartments($user), 403);

        return Inertia::render('App/Departments/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $customerId] = $this->customerBidManagerContext($request);

        abort_unless($this->customerContext->canCreateCustomerDepartments($user), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
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

        return $this->successRedirect($request, 'app.departments.index', 'Avdelingen ble opprettet.');
    }

    public function edit(Request $request, int $department): Response
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        abort_unless($this->customerContext->canCreateCustomerDepartments($actor), 403);
        $record = $this->scopedCustomerDepartment($customerId, $department, $actor);

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
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        abort_unless($this->customerContext->canCreateCustomerDepartments($actor), 403);
        $record = $this->scopedCustomerDepartment($customerId, $department, $actor);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
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

        return $this->successRedirect($request, 'app.departments.index', 'Avdelingen ble oppdatert.');
    }

    public function toggleActive(Request $request, int $department): RedirectResponse
    {
        [$actor, $customerId] = $this->customerBidManagerContext($request);
        abort_unless($this->customerContext->canCreateCustomerDepartments($actor), 403);
        $record = $this->scopedCustomerDepartment($customerId, $department, $actor);

        $record->forceFill([
            'is_active' => ! (bool) $record->is_active,
        ])->save();

        return $this->successRedirect(
            $request,
            'app.departments.index',
            $record->is_active ? 'Avdelingen ble aktivert.' : 'Avdelingen ble deaktivert.',
            true,
        );
    }

    private function customerBidManagerContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $this->customerContext->canManageCustomerUsers($user)
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function scopedCustomerDepartmentsQuery(int $customerId, User $actor)
    {
        $query = Department::query()->where('customer_id', $customerId);

        if ($actor->hasCompanyWideBidManagementScope()) {
            return $query;
        }

        $manageableDepartmentIds = $this->customerContext->manageableDepartmentIds($actor);

        if ($manageableDepartmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $manageableDepartmentIds);
    }

    private function scopedCustomerDepartment(int $customerId, int $departmentId, User $actor): Department
    {
        $department = $this->scopedCustomerDepartmentsQuery($customerId, $actor)
            ->whereKey($departmentId)
            ->firstOrFail();

        abort_unless($this->customerContext->canManageDepartment($department, $actor), 404);

        return $department;
    }

    private function ensureUniqueDepartmentName(int $customerId, string $name, ?int $ignoreId = null): void
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
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

    private function successRedirect(Request $request, string $fallbackRoute, string $message, bool $useBack = false): RedirectResponse
    {
        $redirectTo = $this->redirectTarget($request);

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo)->with('success', $message);
        }

        if ($useBack) {
            return back()->with('success', $message);
        }

        return redirect()->route($fallbackRoute)->with('success', $message);
    }

    private function redirectTarget(Request $request): ?string
    {
        $redirectTo = trim((string) $request->input('redirect_to'));

        if ($redirectTo === '' || ! str_starts_with($redirectTo, '/app/customer-environment')) {
            return null;
        }

        return $redirectTo;
    }
}
