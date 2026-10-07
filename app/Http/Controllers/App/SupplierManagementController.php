<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierStatusChange;
use App\Models\User;
use App\Services\Suppliers\SupplierAccessService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Support\CustomerContext;
use App\Support\Suppliers\SupplierValidationMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leverandøroppfølging → Leverandører: the register, one supplier, and its lifecycle.
 *
 * The route guard has already refused a customer without the `supplier` module. Every read starts
 * from SupplierAccessService, which narrows to the user's own customer and to nothing at all
 * without supplier.view — before any id is looked at. A supplier of another customer is a 404 like
 * an id that does not exist; a user without supplier.view gets a 403 on everything. System Owner is
 * no exception — supplier is an explicit-grant domain.
 *
 * supplier.edit registers and changes suppliers and moves them through Ta i bruk, Avslutt and
 * Gjenåpne (SupplierLifecycleService, the only writer of a status change); supplier.delete deletes
 * one registered by mistake and never used. supplier.assess is not used here.
 *
 * An ended supplier is read-only until it is reopened.
 */
class SupplierManagementController extends Controller
{
    /** The register's status filter when none is chosen: everything not ended. */
    private const STATUS_FILTER_OPEN = '';

    private const STATUS_FILTER_ALL = 'all';

    public function __construct(
        private readonly CustomerContext $customerContext,
        private readonly SupplierAccessService $access,
        private readonly SupplierLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authorizedUser();

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', self::STATUS_FILTER_OPEN);
        $status = in_array($status, [self::STATUS_FILTER_ALL, ...Supplier::STATUSES], true) ? $status : self::STATUS_FILTER_OPEN;
        $category = in_array($request->query('category'), Supplier::CATEGORIES, true) ? (string) $request->query('category') : '';

        $query = $this->access->visibleSuppliers($user);

        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->whereRaw('lower(suppliers.name) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(suppliers.organization_number, '')) like ?", [$needle]);
            });
        }

        match ($status) {
            self::STATUS_FILTER_OPEN => $query->where('suppliers.status', '!=', Supplier::STATUS_ENDED),
            self::STATUS_FILTER_ALL => null,
            default => $query->where('suppliers.status', $status),
        };

        if ($category !== '') {
            $query->where('suppliers.category', $category);
        }

        $suppliers = $query
            ->with('owner:id,name')
            ->orderByRaw('lower(suppliers.name)')
            ->orderBy('suppliers.id')
            ->get();

        $canEdit = $this->access->canEdit($user);

        return Inertia::render('App/SupplierManagement/Index', [
            'suppliers' => $suppliers->map(fn (Supplier $supplier): array => $this->row($supplier))->all(),
            // Only what the user can see — which in v1 is the customer's whole register, or nothing.
            'visible_count' => $this->access->visibleSuppliers($user)->count(),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category' => $category,
            ],
            'statuses' => Supplier::STATUSES,
            'initial_statuses' => Supplier::INITIAL_STATUSES,
            'categories' => Supplier::CATEGORIES,
            'permissions' => [
                'can_edit' => $canEdit,
            ],
            'owner_options' => $canEdit ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function show(int $supplierId): Response
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        $supplier->loadMissing(['owner:id,name', 'createdBy:id,name']);

        $canEdit = $this->access->canEdit($user);
        $open = ! $supplier->isEnded();
        // Reached through visibleSuppliers(); the history carries no access of its own.
        $changes = $supplier->statusChanges()->with('changedBy:id,name')->get();
        $canDelete = $this->access->canDelete($user);

        return Inertia::render('App/SupplierManagement/Show', [
            'supplier' => $this->row($supplier) + [
                'contact_name' => $supplier->contact_name,
                'contact_email' => $supplier->contact_email,
                'contact_phone' => $supplier->contact_phone,
                'note' => $supplier->note,
            ],
            'registered' => [
                'at' => $supplier->created_at?->toIso8601String(),
                'by_name' => $supplier->createdBy?->name,
                // The status it was registered with: where the oldest change started, or where it
                // still is.
                'status' => $changes->last()?->from_status ?? $supplier->status,
            ],
            'status_history' => $changes->map(fn (SupplierStatusChange $change): array => [
                'id' => (int) $change->id,
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'reason' => $change->reason,
                'changed_at' => $change->changed_at?->toIso8601String(),
                'changed_by_name' => $change->changedBy?->name,
            ])->all(),
            'permissions' => [
                // An ended supplier is reopened before it is changed.
                'can_edit' => $canEdit && $open,
                'can_activate' => $canEdit && $supplier->status === Supplier::STATUS_ONBOARDING,
                'can_end' => $canEdit && $open,
                'can_reopen' => $canEdit && ! $open,
                'can_delete' => $canDelete && $supplier->isDeletable(),
                // Says why the delete button is missing, for someone who could otherwise delete.
                'has_delete_right' => $canDelete,
            ],
            'categories' => Supplier::CATEGORIES,
            'owner_options' => $canEdit && $open ? $this->access->ownerCandidates($user) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizedUser();
        abort_unless($this->access->canEdit($user), 403);

        $fields = $this->validatedFields($request, $user, null);
        $initialStatus = $request->validate([
            'initial_status' => ['required', 'string', Rule::in(Supplier::INITIAL_STATUSES)],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes())['initial_status'];

        $supplier = $this->guardOrganizationNumberRace(function () use ($fields, $user, $initialStatus): Supplier {
            $supplier = new Supplier($fields + [
                'customer_id' => (int) $user->customer_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            // The status it is registered with is the supplier's own; it is no status change.
            $supplier->status = $initialStatus;
            $supplier->save();

            return $supplier;
        });

        return redirect()
            ->route('app.supplier-management.show', ['supplierId' => $supplier->id])
            ->with('success', __('procynia.supplier_management.flash.created'));
    }

    public function update(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        if ($supplier->isEnded()) {
            return back()->with('error', __('procynia.supplier_management.validation.reopen_before_edit'));
        }

        $fields = $this->validatedFields($request, $user, $supplier);

        $this->guardOrganizationNumberRace(fn () => $supplier->fill($fields + ['updated_by' => $user->id])->save());

        return back()->with('success', __('procynia.supplier_management.flash.updated'));
    }

    /** Ta i bruk: Under vurdering → Aktiv. */
    public function activate(int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->activate($supplier, $user);

        return back()->with('success', __('procynia.supplier_management.flash.activated'));
    }

    /** Avslutt leverandør: no longer in use, or not chosen. Always says why. */
    public function end(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->end($supplier, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.supplier_management.flash.ended'));
    }

    /** Gjenåpne leverandør: in use again. Always says why; the ending stays in the history. */
    public function reopen(Request $request, int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canEdit($user), 403);

        $this->lifecycle->reopen($supplier, $user, $this->validatedReason($request));

        return back()->with('success', __('procynia.supplier_management.flash.reopened'));
    }

    public function destroy(int $supplierId): RedirectResponse
    {
        $user = $this->authorizedUser();
        $supplier = $this->visibleSupplierOrFail($user, $supplierId);
        abort_unless($this->access->canDelete($user), 403);

        if (! $supplier->isDeletable()) {
            return back()->with('error', __('procynia.supplier_management.validation.not_deletable'));
        }

        $supplier->delete();

        return redirect()->route('app.supplier-management.index')->with('success', __('procynia.supplier_management.flash.deleted'));
    }

    private function authorizedUser(): User
    {
        $user = $this->customerContext->currentUser();

        abort_unless($this->access->canOpenModule($user), 403);

        return $user;
    }

    private function visibleSupplierOrFail(User $user, int $supplierId): Supplier
    {
        return $this->access->findVisibleSupplier($user, $supplierId) ?? abort(404);
    }

    /**
     * The same master data for registering and editing, checked against what the user can reach.
     * Status is not among them: it is chosen once at registration and then moves only through the
     * lifecycle.
     *
     * @return array<string, mixed>
     */
    private function validatedFields(Request $request, User $user, ?Supplier $current): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_number' => ['nullable', 'string', 'max:50'],
            'category' => ['required', 'string', Rule::in(Supplier::CATEGORIES)],
            'deliverable_description' => ['required', 'string', 'max:5000'],
            'owner_user_id' => ['required', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:10000'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes());

        // A 422 rather than a 404: the id came from a form, and whether it names another customer's
        // user or nobody at all, the answer is the same.
        $owner = User::query()->where('customer_id', (int) $user->customer_id)->find((int) $validated['owner_user_id']);

        if (! $this->access->isValidOwner($owner, (int) $user->customer_id)) {
            throw ValidationException::withMessages(['owner_user_id' => __('procynia.supplier_management.validation.owner_not_allowed')]);
        }

        // «987 654 321» and «987654321» are the same number.
        $organizationNumber = preg_replace('/\s+/u', '', (string) ($validated['organization_number'] ?? ''));
        $organizationNumber = $organizationNumber !== '' ? $organizationNumber : null;

        if ($organizationNumber !== null && $this->organizationNumberTaken((int) $user->customer_id, $organizationNumber, $current?->id)) {
            throw ValidationException::withMessages(['organization_number' => __('procynia.supplier_management.validation.organization_number_taken')]);
        }

        return [
            'name' => trim($validated['name']),
            'organization_number' => $organizationNumber,
            'category' => $validated['category'],
            'deliverable_description' => trim($validated['deliverable_description']),
            'owner_user_id' => (int) $owner->id,
            'contact_name' => $this->optional($validated['contact_name'] ?? null),
            'contact_email' => $this->optional($validated['contact_email'] ?? null),
            'contact_phone' => $this->optional($validated['contact_phone'] ?? null),
            'note' => $this->optional($validated['note'] ?? null),
        ];
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** Within the customer — the same rule as the database's partial unique index. */
    private function organizationNumberTaken(int $customerId, string $organizationNumber, ?int $exceptId): bool
    {
        return Supplier::query()
            ->where('customer_id', $customerId)
            ->where('organization_number', $organizationNumber)
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Two saves of the same organisation number at once both pass the check above; the unique
     * index refuses the second, and it gets the same answer the check would have given.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardOrganizationNumberRace(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['organization_number' => __('procynia.supplier_management.validation.organization_number_taken')]);
        }
    }

    private function validatedReason(Request $request): string
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ], SupplierValidationMessages::messages(), SupplierValidationMessages::attributes())['reason'];
    }

    /** @return array<string, mixed> */
    private function row(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'organization_number' => $supplier->organization_number,
            'category' => $supplier->category,
            'deliverable_description' => $supplier->deliverable_description,
            'status' => $supplier->status,
            'owner_user_id' => $supplier->owner_user_id !== null ? (int) $supplier->owner_user_id : null,
            'owner_name' => $supplier->owner?->name,
            'url' => route('app.supplier-management.show', ['supplierId' => $supplier->id]),
        ];
    }
}
