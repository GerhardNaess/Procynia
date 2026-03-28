<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CpvCode;
use App\Models\Department;
use App\Models\User;
use App\Models\WatchProfile;
use App\Support\CustomerContext;
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
        [, $customerId] = $this->customerAdminContext($request);

        $watchProfiles = $this->scopedWatchProfilesQuery($customerId)
            ->with(['department:id,name,is_active'])
            ->withCount('cpvCodes')
            ->orderBy('name')
            ->get()
            ->map(fn (WatchProfile $watchProfile): array => $this->watchProfileListItem($watchProfile))
            ->all();

        return Inertia::render('App/WatchProfiles/Index', [
            'watchProfiles' => $watchProfiles,
        ]);
    }

    public function create(Request $request): Response
    {
        [, $customerId] = $this->customerAdminContext($request);

        return Inertia::render('App/WatchProfiles/Create', [
            'departmentOptions' => $this->departmentOptions($customerId),
            'storeUrl' => route('app.watch-profiles.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);
        $payload = $this->validatedPayload($request, $customerId);

        DB::transaction(function () use ($customerId, $payload): void {
            $watchProfile = WatchProfile::query()->create([
                'customer_id' => $customerId,
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
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedWatchProfile($customerId, $watchProfile);

        $record->loadMissing([
            'department:id,name,is_active',
            'cpvCodes' => fn ($query) => $query->orderBy('id'),
        ]);

        return Inertia::render('App/WatchProfiles/Edit', [
            'watchProfile' => $this->watchProfileFormPayload($record),
            'departmentOptions' => $this->departmentOptions($customerId),
        ]);
    }

    public function update(Request $request, int $watchProfile): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedWatchProfile($customerId, $watchProfile);
        $payload = $this->validatedPayload($request, $customerId, $record->id);

        DB::transaction(function () use ($record, $payload): void {
            $record->fill([
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
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedWatchProfile($customerId, $watchProfile);

        $record->forceFill([
            'is_active' => ! (bool) $record->is_active,
        ])->save();

        return back()->with('success', $record->is_active ? 'Watch Profile ble aktivert.' : 'Watch Profile ble deaktivert.');
    }

    public function destroy(Request $request, int $watchProfile): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedWatchProfile($customerId, $watchProfile);

        DB::transaction(function () use ($record): void {
            $record->delete();
        });

        return redirect()
            ->route('app.watch-profiles.index')
            ->with('success', 'Watch Profile ble slettet.');
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

    private function scopedWatchProfilesQuery(int $customerId)
    {
        return WatchProfile::query()->where('customer_id', $customerId);
    }

    private function scopedWatchProfile(int $customerId, int $watchProfileId): WatchProfile
    {
        return $this->scopedWatchProfilesQuery($customerId)
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
            'department' => $watchProfile->department?->name,
            'is_active' => (bool) $watchProfile->is_active,
            'keyword_count' => $keywordCount,
            'cpv_rule_count' => (int) ($watchProfile->cpv_codes_count ?? 0),
            'updated_at' => optional($watchProfile->updated_at)?->toIso8601String(),
            'edit_url' => route('app.watch-profiles.edit', ['watchProfile' => $watchProfile->id]),
            'toggle_active_url' => route('app.watch-profiles.toggle-active', ['watchProfile' => $watchProfile->id]),
            'delete_url' => route('app.watch-profiles.destroy', ['watchProfile' => $watchProfile->id]),
        ];
    }

    private function watchProfileFormPayload(WatchProfile $watchProfile): array
    {
        return [
            'id' => $watchProfile->id,
            'name' => $watchProfile->name,
            'description' => $watchProfile->description,
            'is_active' => (bool) $watchProfile->is_active,
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

    private function departmentOptions(int $customerId): array
    {
        return Department::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->is_active ? $department->name : "{$department->name} (inaktiv)",
            ])
            ->all();
    }

    private function validatedPayload(Request $request, int $customerId, ?int $ignoreId = null): array
    {
        $request->merge([
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists(Department::class, 'id')->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'keywords' => ['nullable', 'string', 'max:4000'],
            'cpv_codes' => ['nullable', 'array'],
            'cpv_codes.*.cpv_code' => ['required', 'string', 'max:255', Rule::exists(CpvCode::class, 'code')],
            'cpv_codes.*.weight' => ['required', 'integer', 'min:1'],
            'customer_id' => ['prohibited'],
        ]);

        $this->ensureUniqueWatchProfileName($customerId, $validated['name'], $ignoreId);

        $cpvCodes = $this->normalizeCpvRules($validated['cpv_codes'] ?? []);
        $this->ensureDistinctCpvCodes($cpvCodes);

        return [
            'name' => $validated['name'],
            'description' => $this->normalizeDescription($validated['description'] ?? null),
            'is_active' => (bool) $validated['is_active'],
            'department_id' => isset($validated['department_id']) ? (int) $validated['department_id'] : null,
            'keywords' => $this->normalizeKeywords($validated['keywords'] ?? ''),
            'cpv_codes' => $cpvCodes,
        ];
    }

    private function ensureUniqueWatchProfileName(int $customerId, string $name, ?int $ignoreId = null): void
    {
        $query = $this->scopedWatchProfilesQuery($customerId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Denne Watch Profile-en finnes allerede for kunden.',
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
}
