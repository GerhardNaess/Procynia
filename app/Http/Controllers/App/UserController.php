<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CustomerContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const ROLE_MAP = [
        'admin' => User::ROLE_CUSTOMER_ADMIN,
        'user' => User::ROLE_USER,
    ];

    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $customerId] = $this->customerAdminContext($request);

        $users = $this->scopedCustomerUsersQuery($customerId)
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (User $user): array => $this->userListItem($user, $actor, $customerId))
            ->all();

        return Inertia::render('App/Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->customerAdminContext($request);

        return Inertia::render('App/Users/Create', [
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function edit(Request $request, int $user): Response
    {
        [$actor, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerUser($customerId, $user);

        return Inertia::render('App/Users/Edit', [
            'user' => $this->editUserPayload($record, $actor, $customerId),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [, $customerId] = $this->customerAdminContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', 'string', Rule::in(array_keys(self::ROLE_MAP))],
            'customer_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'nationality_id' => ['prohibited'],
            'preferred_language_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['prohibited'],
        ]);

        $temporaryPassword = Str::password(16);

        $user = User::create([
            'name' => Str::squish($validated['name']),
            'email' => Str::lower(trim($validated['email'])),
            'password' => Hash::make($temporaryPassword),
            'role' => self::ROLE_MAP[$validated['role']],
            'is_active' => true,
            'customer_id' => $customerId,
        ]);

        return redirect()
            ->route('app.users.index')
            ->with('success', 'Brukeren ble opprettet.')
            ->with('userCreated', [
                'name' => $user->name,
                'email' => $user->email,
                'temporaryPassword' => $temporaryPassword,
            ]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        [$actor, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerUser($customerId, $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in(array_keys(self::ROLE_MAP))],
            'email' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'nationality_id' => ['prohibited'],
            'preferred_language_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['prohibited'],
        ]);

        $nextRole = self::ROLE_MAP[$validated['role']];

        if ($this->wouldRemoveLastActiveAdmin($record, $nextRole, (bool) $record->is_active)) {
            throw ValidationException::withMessages([
                'role' => 'Kunden må ha minst én aktiv administrator.',
            ]);
        }

        $record->fill([
            'name' => Str::squish($validated['name']),
            'role' => $nextRole,
        ])->save();

        if ($record->is($actor) && $nextRole === User::ROLE_USER) {
            return redirect()
                ->route('app.notices.index')
                ->with('success', 'Brukeren ble oppdatert. Du har ikke lenger tilgang til brukeradministrasjon.');
        }

        return redirect()
            ->route('app.users.index')
            ->with('success', 'Brukeren ble oppdatert.');
    }

    public function toggleActive(Request $request, int $user): RedirectResponse
    {
        [$actor, $customerId] = $this->customerAdminContext($request);
        $record = $this->scopedCustomerUser($customerId, $user);

        if ($record->is($actor)) {
            return back()->with('error', 'Du kan ikke deaktivere din egen bruker.');
        }

        $nextActive = ! (bool) $record->is_active;

        if ($this->wouldRemoveLastActiveAdmin($record, $record->role, $nextActive)) {
            return back()->with('error', 'Kunden må ha minst én aktiv administrator.');
        }

        $record->forceFill([
            'is_active' => $nextActive,
        ])->save();

        return back()->with('success', $nextActive ? 'Brukeren ble aktivert.' : 'Brukeren ble deaktivert.');
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

    private function scopedCustomerUsersQuery(int $customerId)
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->whereIn('role', array_values(self::ROLE_MAP));
    }

    private function scopedCustomerUser(int $customerId, int $userId): User
    {
        return $this->scopedCustomerUsersQuery($customerId)
            ->whereKey($userId)
            ->firstOrFail();
    }

    private function roleOptions(): array
    {
        return [
            ['value' => 'admin', 'label' => 'Admin'],
            ['value' => 'user', 'label' => 'Bruker'],
        ];
    }

    private function userListItem(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $this->roleLabel($user->role),
            'role_value' => $this->roleValue($user->role),
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'can_demote' => ! $this->wouldRemoveLastActiveAdmin($user, User::ROLE_USER, (bool) $user->is_active),
            'edit_url' => route('app.users.edit', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
            'created_at' => optional($user->created_at)?->toIso8601String(),
        ];
    }

    private function editUserPayload(User $user, User $actor, int $customerId): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_value' => $this->roleValue($user->role),
            'role_label' => $this->roleLabel($user->role),
            'is_active' => (bool) $user->is_active,
            'is_self' => $user->is($actor),
            'can_toggle_active' => $this->canToggleActive($user, $actor, $customerId),
            'can_demote' => ! $this->wouldRemoveLastActiveAdmin($user, User::ROLE_USER, (bool) $user->is_active),
            'update_url' => route('app.users.update', ['user' => $user->id]),
            'toggle_active_url' => route('app.users.toggle-active', ['user' => $user->id]),
        ];
    }

    private function canToggleActive(User $user, User $actor, int $customerId): bool
    {
        if (! $user->is_active) {
            return true;
        }

        if ($user->is($actor)) {
            return false;
        }

        return ! $this->wouldRemoveLastActiveAdmin($user, $user->role, false, $customerId);
    }

    private function wouldRemoveLastActiveAdmin(User $user, string $nextRole, bool $nextActive, ?int $customerId = null): bool
    {
        if (! $user->is_active || $user->role !== User::ROLE_CUSTOMER_ADMIN) {
            return false;
        }

        if ($nextRole === User::ROLE_CUSTOMER_ADMIN && $nextActive) {
            return false;
        }

        $activeAdminCount = $this->scopedCustomerUsersQuery($customerId ?? (int) $user->customer_id)
            ->where('role', User::ROLE_CUSTOMER_ADMIN)
            ->where('is_active', true)
            ->count();

        return $activeAdminCount <= 1;
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            User::ROLE_CUSTOMER_ADMIN => 'Admin',
            User::ROLE_USER => 'Bruker',
            default => $role,
        };
    }

    private function roleValue(string $role): string
    {
        return match ($role) {
            User::ROLE_CUSTOMER_ADMIN => 'admin',
            User::ROLE_USER => 'user',
            default => $role,
        };
    }
}
