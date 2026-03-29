<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CpvCode;
use App\Models\Department;
use App\Models\User;
use App\Models\WatchProfile;
use App\Support\CustomerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WatchProfileController extends Controller
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $filters = $this->indexFilters($request);
        $scopedQuery = $this->scopedWatchProfilesQuery($user, $customerId);

        $watchProfiles = $this->applyIndexFilters(clone $scopedQuery, $filters)
            ->with(['department:id,name,is_active', 'user:id,name'])
            ->withCount('cpvCodes')
            ->orderBy('name')
            ->get()
            ->map(fn (WatchProfile $watchProfile): array => $this->watchProfileListItem($watchProfile))
            ->all();

        return Inertia::render('App/WatchProfiles/Index', [
            'watchProfiles' => $watchProfiles,
            'filters' => $filters,
            'filterOptions' => [
                'users' => $this->userFilterOptions(clone $scopedQuery, $user),
                'departments' => $this->departmentFilterOptions(clone $scopedQuery, $user, $customerId),
            ],
            'ownerOptions' => $this->ownerOptions($user),
            'canCreateDepartmentProfiles' => $this->canCreateDepartmentProfiles($user),
        ]);
    }

    public function create(Request $request): Response
    {
        [$user, $customerId] = $this->frontendContext($request);

        return Inertia::render('App/WatchProfiles/Create', [
            'ownerOptions' => $this->ownerOptions($user),
            'defaultOwnerScope' => WatchProfile::OWNER_SCOPE_USER,
            'departmentOptions' => $this->departmentOptions($user, $customerId),
            'storeUrl' => route('app.watch-profiles.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $payload = $this->validatedPayload($request, $user, $customerId);

        DB::transaction(function () use ($customerId, $payload): void {
            $watchProfile = WatchProfile::query()->create([
                'customer_id' => $customerId,
                'user_id' => $payload['user_id'],
                'department_id' => $payload['department_id'],
                'name' => $payload['name'],
                'description' => $payload['description'],
                'keywords' => $payload['keywords'],
                'is_active' => $payload['is_active'],
            ]);

            $this->syncCpvRules($watchProfile, $payload['cpv_codes']);
        });

        return redirect()
            ->route('app.watch-profiles.index')
            ->with('success', 'Watch Profile ble opprettet.');
    }

    public function edit(Request $request, int $watchProfile): Response
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedWatchProfile($user, $customerId, $watchProfile);

        $record->loadMissing([
            'department:id,name,is_active',
            'user:id,name',
            'cpvCodes' => fn ($query) => $query->orderBy('id'),
        ]);

        return Inertia::render('App/WatchProfiles/Edit', [
            'watchProfile' => $this->watchProfileFormPayload($record),
            'ownerOptions' => $this->ownerOptions($user),
            'departmentOptions' => $this->departmentOptions($user, $customerId),
        ]);
    }

    public function update(Request $request, int $watchProfile): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedWatchProfile($user, $customerId, $watchProfile);
        $payload = $this->validatedPayload($request, $user, $customerId, $record->id);

        DB::transaction(function () use ($record, $payload): void {
            $record->fill([
                'user_id' => $payload['user_id'],
                'department_id' => $payload['department_id'],
                'name' => $payload['name'],
                'description' => $payload['description'],
                'keywords' => $payload['keywords'],
                'is_active' => $payload['is_active'],
            ])->save();

            $this->syncCpvRules($record, $payload['cpv_codes']);
        });

        return redirect()
            ->route('app.watch-profiles.index')
            ->with('success', 'Watch Profile ble oppdatert.');
    }

    public function toggleActive(Request $request, int $watchProfile): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedWatchProfile($user, $customerId, $watchProfile);

        $record->forceFill([
            'is_active' => ! (bool) $record->is_active,
        ])->save();

        return back()->with('success', $record->is_active ? 'Watch Profile ble aktivert.' : 'Watch Profile ble deaktivert.');
    }

    public function destroy(Request $request, int $watchProfile): RedirectResponse
    {
        [$user, $customerId] = $this->frontendContext($request);
        $record = $this->scopedWatchProfile($user, $customerId, $watchProfile);

        DB::transaction(function () use ($record): void {
            $record->delete();
        });

        return redirect()
            ->route('app.watch-profiles.index')
            ->with('success', 'Watch Profile ble slettet.');
    }

    private function frontendContext(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $customerId = $this->customerContext->currentCustomerId($user);

        abort_unless(
            $user instanceof User
            && $user->canAccessCustomerFrontend()
            && $customerId !== null,
            403,
        );

        return [$user, $customerId];
    }

    private function scopedWatchProfilesQuery(User $user, int $customerId): Builder
    {
        return WatchProfile::query()->accessibleTo($user)->where('customer_id', $customerId);
    }

    private function applyIndexFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['user_id'] !== null, fn (Builder $builder) => $builder->where('user_id', $filters['user_id']))
            ->when($filters['department_id'] !== null, fn (Builder $builder) => $builder->where('department_id', $filters['department_id']));
    }

    private function scopedWatchProfile(User $user, int $customerId, int $watchProfileId): WatchProfile
    {
        return $this->scopedWatchProfilesQuery($user, $customerId)
            ->whereKey($watchProfileId)
            ->firstOrFail();
    }

    private function watchProfileListItem(WatchProfile $watchProfile): array
    {
        $keywordCount = collect($watchProfile->keywords ?? [])
            ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
            ->count();

        return [
            'id' => $watchProfile->id,
            'name' => $watchProfile->name,
            'owner_scope' => $watchProfile->ownerScope(),
            'owner_label' => $watchProfile->isUserOwned()
                ? 'Personlig'
                : 'Avdeling',
            'owner_reference' => $watchProfile->isUserOwned()
                ? ($watchProfile->user?->name ?? 'Ukjent bruker')
                : ($watchProfile->department?->name ?? 'Ukjent avdeling'),
            'is_active' => (bool) $watchProfile->is_active,
            'keyword_count' => $keywordCount,
            'cpv_rule_count' => (int) ($watchProfile->cpv_codes_count ?? 0),
            'updated_at' => optional($watchProfile->updated_at)?->toIso8601String(),
            'edit_url' => route('app.watch-profiles.edit', ['watchProfile' => $watchProfile->id]),
            'toggle_active_url' => route('app.watch-profiles.toggle-active', ['watchProfile' => $watchProfile->id]),
            'delete_url' => route('app.watch-profiles.destroy', ['watchProfile' => $watchProfile->id]),
        ];
    }

    private function indexFilters(Request $request): array
    {
        return [
            'user_id' => $this->positiveIntegerOrNull($request->query('user_id')),
            'department_id' => $this->positiveIntegerOrNull($request->query('department_id')),
        ];
    }

    private function userFilterOptions(Builder $scopedQuery, User $user): array
    {
        $userIds = collect((clone $scopedQuery)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all())
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if (! $user->isCustomerAdmin()) {
            $userIds->push($user->id);
        }

        return User::query()
            ->where('customer_id', $user->customer_id)
            ->whereIn('id', $userIds->unique()->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $record): array => [
                'value' => $record->id,
                'label' => $record->name,
            ])
            ->all();
    }

    private function departmentFilterOptions(Builder $scopedQuery, User $user, int $customerId): array
    {
        $departmentIds = collect((clone $scopedQuery)
            ->whereNotNull('department_id')
            ->distinct()
            ->pluck('department_id')
            ->all())
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if (! $user->isCustomerAdmin() && $user->department_id !== null) {
            $departmentIds->push($user->department_id);
        }

        return Department::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $departmentIds->unique()->all())
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function watchProfileFormPayload(WatchProfile $watchProfile): array
    {
        return [
            'id' => $watchProfile->id,
            'name' => $watchProfile->name,
            'description' => $watchProfile->description,
            'is_active' => (bool) $watchProfile->is_active,
            'owner_scope' => $watchProfile->ownerScope() ?? WatchProfile::OWNER_SCOPE_USER,
            'department_id' => $watchProfile->department_id,
            'keywords' => collect($watchProfile->keywords ?? [])
                ->filter(fn (mixed $keyword): bool => is_string($keyword) && trim($keyword) !== '')
                ->implode("\n"),
            'cpv_codes' => $watchProfile->cpvCodes
                ->sortBy('id')
                ->values()
                ->map(fn ($cpvRule): array => [
                    'cpv_code' => $cpvRule->cpv_code,
                    'weight' => (int) $cpvRule->weight,
                ])
                ->all(),
            'update_url' => route('app.watch-profiles.update', ['watchProfile' => $watchProfile->id]),
            'toggle_active_url' => route('app.watch-profiles.toggle-active', ['watchProfile' => $watchProfile->id]),
            'delete_url' => route('app.watch-profiles.destroy', ['watchProfile' => $watchProfile->id]),
        ];
    }

    private function ownerOptions(User $user): array
    {
        $options = [
            [
                'value' => WatchProfile::OWNER_SCOPE_USER,
                'label' => 'Personlig watch profile',
                'description' => 'Treff går kun til din personlige innboks.',
            ],
        ];

        if ($this->canCreateDepartmentProfiles($user)) {
            $options[] = [
                'value' => WatchProfile::OWNER_SCOPE_DEPARTMENT,
                'label' => 'Avdelings-watch profile',
                'description' => 'Treff går til innboksen for valgt avdeling.',
            ];
        }

        return $options;
    }

    private function canCreateDepartmentProfiles(User $user): bool
    {
        return $user->isCustomerAdmin() || $user->department_id !== null;
    }

    private function departmentOptions(User $user, int $customerId): array
    {
        $query = Department::query()
            ->where('customer_id', $customerId);

        if (! $user->isCustomerAdmin()) {
            $query->whereKey($user->department_id ?? 0);
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

    private function validatedPayload(Request $request, User $user, int $customerId, ?int $ignoreId = null): array
    {
        $request->merge([
            'owner_scope' => trim((string) $request->input('owner_scope', WatchProfile::OWNER_SCOPE_USER)),
            'name' => Str::squish((string) $request->input('name')),
            'description' => $this->normalizeDescription($request->input('description')),
            'keywords' => is_string($request->input('keywords')) ? $request->input('keywords') : '',
            'cpv_codes' => collect($request->input('cpv_codes', []))
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(fn (array $row): array => [
                    'cpv_code' => trim((string) data_get($row, 'cpv_code')),
                    'weight' => data_get($row, 'weight'),
                ])
                ->values()
                ->all(),
        ]);

        $validated = $request->validate([
            'owner_scope' => ['required', 'string', Rule::in([WatchProfile::OWNER_SCOPE_USER, WatchProfile::OWNER_SCOPE_DEPARTMENT])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'department_id' => [
                Rule::requiredIf(fn (): bool => $request->input('owner_scope') === WatchProfile::OWNER_SCOPE_DEPARTMENT),
                'nullable',
                'integer',
                Rule::exists(Department::class, 'id')->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'keywords' => ['nullable', 'string', 'max:4000'],
            'cpv_codes' => ['nullable', 'array'],
            'cpv_codes.*.cpv_code' => ['required', 'string', 'max:255', Rule::exists(CpvCode::class, 'code')],
            'cpv_codes.*.weight' => ['required', 'integer', 'min:1'],
            'customer_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ]);

        $ownerPayload = $this->validatedOwnerScope(
            $user,
            $validated['owner_scope'],
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        $this->ensureUniqueWatchProfileName(
            $customerId,
            $validated['name'],
            $ownerPayload['user_id'],
            $ownerPayload['department_id'],
            $ignoreId,
        );

        $cpvCodes = $this->normalizeCpvRules($validated['cpv_codes'] ?? []);
        $this->ensureDistinctCpvCodes($cpvCodes);

        return [
            'name' => $validated['name'],
            'description' => $this->normalizeDescription($validated['description'] ?? null),
            'is_active' => (bool) $validated['is_active'],
            'user_id' => $ownerPayload['user_id'],
            'department_id' => $ownerPayload['department_id'],
            'keywords' => $this->normalizeKeywords($validated['keywords'] ?? ''),
            'cpv_codes' => $cpvCodes,
        ];
    }

    private function validatedOwnerScope(User $user, string $ownerScope, ?int $departmentId): array
    {
        if ($ownerScope === WatchProfile::OWNER_SCOPE_USER) {
            return [
                'user_id' => $user->id,
                'department_id' => null,
            ];
        }

        if (! $this->canCreateDepartmentProfiles($user)) {
            throw ValidationException::withMessages([
                'owner_scope' => 'Du har ikke tilgang til å opprette avdelings-watch profiles.',
            ]);
        }

        if ($departmentId === null) {
            throw ValidationException::withMessages([
                'department_id' => 'Du må velge en avdeling for avdelings-watch profiles.',
            ]);
        }

        if (! $user->isCustomerAdmin() && $user->department_id !== $departmentId) {
            throw ValidationException::withMessages([
                'department_id' => 'Du kan bare opprette og administrere watch profiles for din egen avdeling.',
            ]);
        }

        return [
            'user_id' => null,
            'department_id' => $departmentId,
        ];
    }

    private function ensureUniqueWatchProfileName(
        int $customerId,
        string $name,
        ?int $userId,
        ?int $departmentId,
        ?int $ignoreId = null
    ): void {
        $query = WatchProfile::query()
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($userId !== null) {
            $query->where('user_id', $userId)->whereNull('department_id');
        } else {
            $query->where('department_id', $departmentId)->whereNull('user_id');
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Denne Watch Profile-en finnes allerede for valgt eier-scope.',
            ]);
        }
    }

    private function ensureDistinctCpvCodes(array $cpvCodes): void
    {
        $duplicates = collect($cpvCodes)
            ->pluck('cpv_code')
            ->duplicates()
            ->values();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cpv_codes' => 'Hver CPV-kode kan bare brukes én gang per Watch Profile.',
            ]);
        }
    }

    private function syncCpvRules(WatchProfile $watchProfile, array $cpvCodes): void
    {
        $watchProfile->cpvCodes()->delete();

        if ($cpvCodes === []) {
            return;
        }

        $watchProfile->cpvCodes()->createMany(
            array_map(static fn (array $row): array => [
                'cpv_code' => $row['cpv_code'],
                'weight' => $row['weight'],
            ], $cpvCodes),
        );
    }

    private function normalizeKeywords(string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeCpvRules(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): array => [
                'cpv_code' => trim((string) ($row['cpv_code'] ?? '')),
                'weight' => (int) ($row['weight'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function normalizeDescription(mixed $value): ?string
    {
        $description = trim((string) $value);

        return $description === '' ? null : $description;
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        $integer = (int) $normalized;

        return $integer > 0 ? $integer : null;
    }
}
