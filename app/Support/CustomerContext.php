<?php

namespace App\Support;

use App\Models\CpvCode;
use App\Models\Customer;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

class CustomerContext
{
    public function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function currentCustomer(?User $user = null): ?Customer
    {
        $user ??= $this->currentUser();

        if (! $user instanceof User || $user->customer_id === null) {
            return null;
        }

        if ($user->relationLoaded('customer')) {
            return $user->customer;
        }

        return $user->customer()->first();
    }

    public function currentCustomerId(?User $user = null): ?int
    {
        return $this->currentCustomer($user)?->id;
    }

    public function currentDepartmentId(?User $user = null): ?int
    {
        $user ??= $this->currentUser();

        return $user?->department_id;
    }

    public function isInternalAdmin(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User
            && $user->isSuperAdmin()
            && $user->customer_id === null;
    }

    public function isSuperAdmin(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function isCustomerAdmin(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User
            && $user->isCustomerAdmin()
            && $user->customer_id !== null;
    }

    public function canManageUsers(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User && $user->canManageUsers();
    }

    public function canManageCustomerUsers(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User && $user->canManageCustomerUsers();
    }

    public function canCreateCustomerDepartments(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User
            && $this->canManageCustomerUsers($user)
            && $user->isSystemOwner();
    }

    public function customerDepartmentIds(?User $user = null): array
    {
        $customerId = $this->currentCustomerId($user);

        if ($customerId === null) {
            return [];
        }

        return Department::query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function manageableDepartmentIds(?User $user = null): array
    {
        $user ??= $this->currentUser();

        if (! $user instanceof User || ! $this->canManageCustomerUsers($user)) {
            return [];
        }

        if ($user->hasCompanyWideCustomerManagementScope()) {
            return $this->customerDepartmentIds($user);
        }

        if (! $user->hasDepartmentScopedBidManagement()) {
            return [];
        }

        return Department::query()
            ->where('customer_id', $user->customer_id)
            ->whereIn('id', $user->managedDepartmentIds())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function membershipDepartmentIds(User $user): array
    {
        return $user->membershipDepartmentIds();
    }

    public function hasDepartmentMembership(?User $user = null): bool
    {
        $user ??= $this->currentUser();

        return $user instanceof User && $user->hasDepartmentMembership();
    }

    public function canManageDepartment(Department $department, ?User $user = null): bool
    {
        $user ??= $this->currentUser();

        if (! $user instanceof User || ! $this->canManageCustomerUsers($user)) {
            return false;
        }

        if ((int) $department->customer_id !== (int) $user->customer_id) {
            return false;
        }

        if ($user->hasCompanyWideCustomerManagementScope()) {
            return true;
        }

        return in_array((int) $department->id, $this->manageableDepartmentIds($user), true);
    }

    public function canManageCustomerUser(User $actor, User $target): bool
    {
        if (! $this->canManageCustomerUsers($actor)) {
            return false;
        }

        if ($actor->customer_id === null || (int) $target->customer_id !== (int) $actor->customer_id) {
            return false;
        }

        if (! in_array($target->role, [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER], true)) {
            return false;
        }

        if ($actor->is($target)) {
            return true;
        }

        if ($actor->isSystemOwner()) {
            return true;
        }

        if (! $actor->isBidManager()) {
            return false;
        }

        if ($target->isSystemOwner() || $target->isBidManager()) {
            return false;
        }

        if ($actor->hasCompanyWideBidManagementScope()) {
            return true;
        }

        $manageableDepartmentIds = $this->manageableDepartmentIds($actor);

        if ($manageableDepartmentIds === []) {
            return false;
        }

        $membershipDepartmentIds = $this->membershipDepartmentIds($target);

        return $membershipDepartmentIds !== []
            && ! $this->hasOutOfScopeDepartment($membershipDepartmentIds, $manageableDepartmentIds);
    }

    public function scopeCustomerOwned(Builder $query, string $column = 'customer_id', ?User $user = null): Builder
    {
        $user ??= $this->currentUser();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isInternalAdmin($user)) {
            return $query;
        }

        if ($user->customer_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $user->customer_id);
    }

    public function scopeNoticeDiscovery(Builder $query, ?User $user = null): Builder
    {
        $user ??= $this->currentUser();

        if (! $user instanceof User || $this->currentCustomerId($user) === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('raw_xml_stored', true)
            ->whereNotNull('parsed_at');
    }

    public function resolveLanguageCode(?User $user = null): string
    {
        $user ??= $this->currentUser();

        $preferredLanguage = $this->userPreferredLanguageCode($user);

        if ($preferredLanguage !== null) {
            return $preferredLanguage;
        }

        $nationalityLanguage = $this->languageForNationality($this->userNationalityCode($user));

        if ($nationalityLanguage !== null) {
            return $nationalityLanguage;
        }

        $customerLanguage = $this->customerLanguageCode($user);

        if ($customerLanguage !== null) {
            return $customerLanguage;
        }

        return 'no';
    }

    public function resolveNationalityCode(?User $user = null): ?string
    {
        $user ??= $this->currentUser();

        $userNationality = $this->userNationalityCode($user);

        if ($userNationality !== null) {
            return $userNationality;
        }

        return $this->customerNationalityCode($user);
    }

    public function cpvDescription(?CpvCode $catalogEntry, ?User $user = null): ?string
    {
        if (! $catalogEntry instanceof CpvCode) {
            return null;
        }

        return match ($this->resolveLanguageCode($user)) {
            'en' => $this->normalizeText($catalogEntry->description_en) ?? $this->normalizeText($catalogEntry->description_no),
            default => $this->normalizeText($catalogEntry->description_no) ?? $this->normalizeText($catalogEntry->description_en),
        };
    }

    public function label(string $key, ?string $fallback = null, ?User $user = null): string
    {
        $languageCode = $this->resolveLanguageCode($user);
        $translated = Lang::get($key, locale: $languageCode);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return $fallback ?? $key;
    }

    private function normalizeLanguageCode(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function normalizeNationalityCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function languageForNationality(?string $nationalityCode): ?string
    {
        return match ($this->normalizeNationalityCode($nationalityCode)) {
            'NO' => 'no',
            default => null,
        };
    }

    private function normalizeText(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function userPreferredLanguageCode(?User $user): ?string
    {
        if (! $user instanceof User || $user->preferred_language_id === null) {
            return null;
        }

        if ($user->relationLoaded('preferredLanguage')) {
            return $this->normalizeLanguageCode($user->preferredLanguage?->code);
        }

        return $this->normalizeLanguageCode($user->preferredLanguage()->value('code'));
    }

    private function userNationalityCode(?User $user): ?string
    {
        if (! $user instanceof User || $user->nationality_id === null) {
            return null;
        }

        if ($user->relationLoaded('nationality')) {
            return $this->normalizeNationalityCode($user->nationality?->code);
        }

        return $this->normalizeNationalityCode($user->nationality()->value('code'));
    }

    private function customerLanguageCode(?User $user): ?string
    {
        $customer = $this->currentCustomer($user);

        if (! $customer instanceof Customer || $customer->language_id === null) {
            return null;
        }

        if ($customer->relationLoaded('language')) {
            return $this->normalizeLanguageCode($customer->language?->code);
        }

        return $this->normalizeLanguageCode($customer->language()->value('code'));
    }

    private function customerNationalityCode(?User $user): ?string
    {
        $customer = $this->currentCustomer($user);

        if (! $customer instanceof Customer || $customer->nationality_id === null) {
            return null;
        }

        if ($customer->relationLoaded('nationality')) {
            return $this->normalizeNationalityCode($customer->nationality?->code);
        }

        return $this->normalizeNationalityCode($customer->nationality()->value('code'));
    }

    private function hasOutOfScopeDepartment(array $candidateIds, array $allowedIds): bool
    {
        foreach ($candidateIds as $candidateId) {
            if (! in_array((int) $candidateId, $allowedIds, true)) {
                return true;
            }
        }

        return false;
    }
}
