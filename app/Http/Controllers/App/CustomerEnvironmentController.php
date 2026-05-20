<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerEnvironmentController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->customerEnvironmentContext($request);
        $activeTab = in_array($request->query('tab'), ['departments', 'users', 'permissions', 'document-templates'], true)
            ? (string) $request->query('tab')
            : 'departments';

        $departments = $this->scopedCustomerDepartmentsQuery($actor, $customerId)
            ->withCount('members')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => $this->departmentListItem($department))
            ->all();

        $users = User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->with([
                'primaryDepartment:id,name',
                'departments:id,name,is_active',
                'managedDepartments:id,name,is_active',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (User $user): bool => $this->customerContext->canManageCustomerUser($actor, $user))
            ->values()
            ->map(fn (User $user): array => $this->userListItem($user, $actor, $customerId))
            ->all();

        $documentTemplates = $this->scopedCustomerDocumentTemplatesQuery($customerId)
            ->with(['customer'])
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (DocumentTemplate $template): array => $this->documentTemplateListItem($template))
            ->all();

        return Inertia::render('App/CustomerEnvironment/Index', [
            'activeTab' => $activeTab,
            'departments' => $departments,
            'users' => $users,
            'documentTemplates' => $documentTemplates,
            'canManageDocumentTemplates' => $this->customerContext->canManageCustomerDocumentTemplates($actor),
            'bidRoleOptions' => $this->bidRoleOptions($actor),
            'bidManagerScopeOptions' => collect(User::bidManagerScopeOptions())
                ->map(fn (string $label, string $value): array => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all(),
            'departmentOptions' => $this->membershipDepartmentOptions($actor, $customerId),
            'managedDepartmentOptions' => $this->managedDepartmentOptions($actor, $customerId),
            'departmentFilterOptions' => $this->departmentFilterOptions($actor, $customerId),
            'canCreateDepartments' => $this->customerContext->canCreateCustomerDepartments($actor),
            'permissionSettings' => $actor->isSystemOwner()
                ? $this->permissionSettingsPayload($actor, $customerId)
                : null,
            'routes' => [
                'index' => route('app.customer-environment.index'),
                'departments_store' => route('app.departments.store'),
                'users_store' => route('app.users.store'),
                'users_create' => route('app.users.create'),
                'permissions_update' => route('app.customer-environment.permissions.update'),
                'document_templates_store' => route('app.customer-environment.document-templates.store'),
            ],
        ]);
    }

    public function updatePermissions(Request $request): RedirectResponse
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

        $allowedRoles = ['system_owner', 'bid_manager', 'contributor', 'all'];
        $allowedPermissions = [
            Customer::PERMISSION_CREATE_DEPARTMENTS,
            Customer::PERMISSION_CREATE_USERS,
            Customer::PERMISSION_VIEW_ALL_CASES,
        ];

        $validated = $request->validate([
            'permission' => ['required', 'string', 'in:' . implode(',', $allowedPermissions)],
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'in:' . implode(',', $allowedRoles)],
        ]);

        $customer = Customer::findOrFail($customerId);
        $settings = $customer->resolvedPermissionSettings();

        $roles = array_values(array_unique(
            array_merge(['system_owner'], $validated['roles'])
        ));

        $settings[$validated['permission']] = $roles;
        $customer->permission_settings = $settings;
        $customer->save();

        return redirect()->route('app.customer-environment.index', ['tab' => 'permissions']);
    }

    public function storeDocumentTemplate(Request $request): RedirectResponse
    {
        [$actor, $customerId] = $this->customerDocumentTemplateContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'file_path' => ['required', 'file', 'mimes:docx'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
            'customer_id' => ['prohibited'],
            'template_type' => ['prohibited'],
            'file_disk' => ['prohibited'],
            'original_filename' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'file_size' => ['prohibited'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('file_path');
        DocumentTemplate::validateUploadedWordExportTemplate($uploadedFile);

        [$storedPath, $originalFilename] = $this->storeWordExportTemplateFile($uploadedFile, $customerId);

        DocumentTemplate::query()->create([
            'customer_id' => $customerId,
            'name' => Str::squish((string) $validated['name']),
            'description' => $this->normalizeNullableText($validated['description'] ?? null),
            'template_type' => DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT,
            'file_disk' => 'local',
            'file_path' => $storedPath,
            'original_filename' => $originalFilename,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);

        return $this->successRedirect($request, 'document-templates', 'Dokumentmalen ble lastet opp.');
    }

    public function updateDocumentTemplate(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        [$actor, $customerId] = $this->customerDocumentTemplateContext($request);
        $record = $this->scopedCustomerDocumentTemplate($customerId, $documentTemplate->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
            'customer_id' => ['prohibited'],
            'template_type' => ['prohibited'],
            'file_path' => ['prohibited'],
            'file_disk' => ['prohibited'],
            'original_filename' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'file_size' => ['prohibited'],
        ]);

        $isActive = (bool) ($validated['is_active'] ?? $record->is_active);
        $isDefault = (bool) ($validated['is_default'] ?? $record->is_default);

        if (! $isActive) {
            $isDefault = false;
        }

        $record->fill([
            'name' => Str::squish((string) $validated['name']),
            'description' => $this->normalizeNullableText($validated['description'] ?? null),
            'is_active' => $isActive,
            'is_default' => $isDefault,
            'updated_by_user_id' => $actor->id,
        ])->save();

        return $this->successRedirect($request, 'document-templates', 'Dokumentmalen ble oppdatert.');
    }

    public function toggleDocumentTemplateActive(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        [$actor, $customerId] = $this->customerDocumentTemplateContext($request);
        $record = $this->scopedCustomerDocumentTemplate($customerId, $documentTemplate->id);

        $nextIsActive = ! (bool) $record->is_active;
        $payload = [
            'is_active' => $nextIsActive,
            'updated_by_user_id' => $actor->id,
        ];

        if (! $nextIsActive) {
            $payload['is_default'] = false;
        }

        $record->forceFill($payload)->save();

        return $this->successRedirect(
            $request,
            'document-templates',
            $nextIsActive ? 'Dokumentmalen ble aktivert.' : 'Dokumentmalen ble deaktivert.',
        );
    }

    public function setDefaultDocumentTemplate(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        [$actor, $customerId] = $this->customerDocumentTemplateContext($request);
        $record = $this->scopedCustomerDocumentTemplate($customerId, $documentTemplate->id);

        $record->forceFill([
            'is_active' => true,
            'is_default' => true,
            'updated_by_user_id' => $actor->id,
        ])->save();

        return $this->successRedirect($request, 'document-templates', 'Dokumentmalen ble satt som standard.');
    }

    public function destroyDocumentTemplate(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        [$actor, $customerId] = $this->customerDocumentTemplateContext($request);
        $record = $this->scopedCustomerDocumentTemplate($customerId, $documentTemplate->id);

        $record->delete();

        return $this->successRedirect($request, 'document-templates', 'Dokumentmalen ble slettet.');
    }

    private function permissionSettingsPayload(User $actor, int $customerId): array
    {
        $customer = Customer::find($customerId);
        $settings = $customer ? $customer->resolvedPermissionSettings() : Customer::DEFAULT_PERMISSION_SETTINGS;

        $roleColumns = [
            ['value' => 'system_owner', 'label' => 'System Owner', 'locked' => true],
            ['value' => 'bid_manager', 'label' => 'Bid Manager', 'locked' => false],
            ['value' => 'contributor', 'label' => 'Contributor', 'locked' => false],
            ['value' => 'all', 'label' => 'Alle', 'locked' => false],
        ];

        $permissionRows = [
            [
                'key' => Customer::PERMISSION_CREATE_DEPARTMENTS,
                'label' => 'Opprette avdelinger',
                'roles' => $settings[Customer::PERMISSION_CREATE_DEPARTMENTS],
            ],
            [
                'key' => Customer::PERMISSION_CREATE_USERS,
                'label' => 'Opprette brukere',
                'roles' => $settings[Customer::PERMISSION_CREATE_USERS],
            ],
            [
                'key' => Customer::PERMISSION_VIEW_ALL_CASES,
                'label' => 'Se alle saker',
                'roles' => $settings[Customer::PERMISSION_VIEW_ALL_CASES],
            ],
        ];

        return [
            'role_columns' => $roleColumns,
            'permission_rows' => $permissionRows,
            'update_url' => route('app.customer-environment.permissions.update'),
        ];
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

    private function customerEnvironmentContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $customerId !== null
            && (
                $this->customerContext->canManageCustomerUsers($user)
                || $this->customerContext->canManageCustomerDocumentTemplates($user)
            ),
            403,
        );

        return [$user, $customerId];
    }

    private function customerDocumentTemplateContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $customerId !== null
            && $this->customerContext->canManageCustomerDocumentTemplates($user),
            403,
        );

        return [$user, $customerId];
    }

    private function canToggleActive(User $user, User $actor, int $customerId): bool
    {
        if (! $user->is_active) {
            return true;
        }

        if ($user->is($actor)) {
            return false;
        }

        if (! $user->isSystemOwner()) {
            return true;
        }

        $activeSystemOwnerCount = User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER])
            ->where('bid_role', User::BID_ROLE_SYSTEM_OWNER)
            ->where('is_active', true)
            ->count();

        return $activeSystemOwnerCount > 1;
    }

    private function scopedCustomerDepartmentsQuery(User $actor, int $customerId)
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

    private function departmentListItem(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'description' => $department->description,
            'is_active' => (bool) $department->is_active,
            'user_count' => (int) $department->members_count,
            'created_at' => optional($department->created_at)?->toIso8601String(),
            'updated_at' => optional($department->updated_at)?->toIso8601String(),
            'update_url' => route('app.departments.update', ['department' => $department->id]),
            'toggle_active_url' => route('app.departments.toggle-active', ['department' => $department->id]),
        ];
    }

    private function userListItem(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'bid_role' => $user->bid_role_label,
            'bid_role_value' => $user->resolvedBidRole(),
            'bid_manager_scope_value' => $user->resolvedBidManagerScope(),
            'bid_manager_scope_label' => $user->bid_manager_scope_label,
            'bid_manager_scope_summary' => $this->bidManagerScopeSummary($user),
            'primary_affiliation_scope_value' => $user->resolvedPrimaryAffiliationScope(),
            'primary_affiliation_scope_label' => $user->primary_affiliation_scope_label,
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'primary_department' => $user->primaryDepartment ? [
                'id' => $user->primaryDepartment->id,
                'name' => $user->primaryDepartment->name,
            ] : null,
            'department_ids' => $user->departments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'departments' => $user->departments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'managed_department_ids' => $user->managedDepartments->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'managed_departments' => $user->managedDepartments
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'is_active' => (bool) $department->is_active,
                ])
                ->all(),
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'edit_url' => route('app.users.edit', ['user' => $user->id]),
            'update_url' => route('app.users.update', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
        ];
    }

    private function membershipDepartmentOptions(User $actor, int $customerId, array $selectedDepartmentIds = []): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $actor->hasCompanyWideBidManagementScope()) {
            $visibleDepartmentIds = array_values(array_unique(array_merge(
                $this->customerContext->manageableDepartmentIds($actor),
                $selectedDepartmentIds,
            )));

            if ($visibleDepartmentIds === []) {
                return [];
            }

            $query->whereIn('id', $visibleDepartmentIds);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function bidRoleOptions(User $actor): array
    {
        $allowedRoles = $actor->isSystemOwner()
            ? User::BID_ROLES
            : [
                User::BID_ROLE_CONTRIBUTOR,
                User::BID_ROLE_VIEWER,
            ];

        return collect(User::bidRoleOptions())
            ->only($allowedRoles)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function managedDepartmentOptions(User $actor, int $customerId, array $selectedDepartmentIds = []): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($selectedDepartmentIds): void {
                $query->where('is_active', true);

                if ($selectedDepartmentIds !== []) {
                    $query->orWhereIn('id', $selectedDepartmentIds);
                }
            });

        if (! $actor->hasCompanyWideBidManagementScope()) {
            $visibleDepartmentIds = array_values(array_unique(array_merge(
                $this->customerContext->manageableDepartmentIds($actor),
                $selectedDepartmentIds,
            )));

            if ($visibleDepartmentIds === []) {
                return [];
            }

            $query->whereIn('id', $visibleDepartmentIds);
        }

        return $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function departmentFilterOptions(User $actor, int $customerId): array
    {
        return $this->membershipDepartmentOptions($actor, $customerId);
    }

    private function scopedCustomerDocumentTemplatesQuery(int $customerId)
    {
        return DocumentTemplate::query()
            ->where('customer_id', $customerId)
            ->where('template_type', DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT);
    }

    private function scopedCustomerDocumentTemplate(int $customerId, int $documentTemplateId): DocumentTemplate
    {
        return $this->scopedCustomerDocumentTemplatesQuery($customerId)
            ->whereKey($documentTemplateId)
            ->firstOrFail();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function storeWordExportTemplateFile(UploadedFile $file, int $customerId): array
    {
        $directory = 'document-templates/customer-'.$customerId;
        $storedFilename = Str::ulid().'__'.$file->getClientOriginalName();
        $storedPath = Storage::disk('local')->putFileAs($directory, $file, $storedFilename);

        if (! is_string($storedPath) || $storedPath === '') {
            throw ValidationException::withMessages([
                'file_path' => __('procynia.document_templates.messages.file_missing'),
            ]);
        }

        return [$storedPath, trim((string) $file->getClientOriginalName())];
    }

    private function documentTemplateListItem(DocumentTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'original_filename' => $template->original_filename,
            'template_type' => DocumentTemplate::templateTypeLabel($template->template_type),
            'template_type_value' => $template->template_type,
            'is_active' => (bool) $template->is_active,
            'is_default' => (bool) $template->is_default,
            'updated_at' => optional($template->updated_at)?->toIso8601String(),
            'update_url' => route('app.customer-environment.document-templates.update', ['documentTemplate' => $template->id]),
            'edit_url' => route('app.customer-environment.document-templates.update', ['documentTemplate' => $template->id]),
            'toggle_active_url' => route('app.customer-environment.document-templates.toggle-active', ['documentTemplate' => $template->id]),
            'set_default_url' => route('app.customer-environment.document-templates.set-default', ['documentTemplate' => $template->id]),
            'destroy_url' => route('app.customer-environment.document-templates.destroy', ['documentTemplate' => $template->id]),
        ];
    }

    private function bidManagerScopeSummary(User $user): ?string
    {
        if ($user->isSystemOwner()) {
            return 'Full kontroll i kundemiljøet';
        }

        if (! $user->isBidManager()) {
            return null;
        }

        if ($user->hasCompanyWideBidManagementScope()) {
            return 'Hele selskapet';
        }

        if (! $user->hasDepartmentScopedBidManagement()) {
            return null;
        }

        $count = $user->managedDepartments->count();

        if ($count <= 0) {
            return 'Ingen avdelinger';
        }

        return $count === 1 ? '1 avdeling' : "{$count} avdelinger";
    }

    private function normalizeNullableText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function successRedirect(Request $request, string $fallbackTab, string $message): RedirectResponse
    {
        $redirectTo = $this->redirectTarget($request);

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo)->with('success', $message);
        }

        return redirect()->route('app.customer-environment.index', ['tab' => $fallbackTab])
            ->with('success', $message);
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
