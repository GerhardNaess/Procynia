<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchProfile extends Model
{
    public const OWNER_SCOPE_USER = 'user';

    public const OWNER_SCOPE_DEPARTMENT = 'department';

    protected $fillable = [
        'customer_id',
        'user_id',
        'department_id',
        'name',
        'description',
        'keywords',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cpvCodes(): HasMany
    {
        return $this->hasMany(WatchProfileCpvCode::class);
    }

    public function inboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }

    public function isUserOwned(): bool
    {
        return $this->user_id !== null;
    }

    public function isDepartmentOwned(): bool
    {
        return $this->department_id !== null;
    }

    public function ownerScope(): ?string
    {
        if ($this->isUserOwned()) {
            return self::OWNER_SCOPE_USER;
        }

        if ($this->isDepartmentOwned()) {
            return self::OWNER_SCOPE_DEPARTMENT;
        }

        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $query->where('customer_id', $user->customer_id)
            ->where(function (Builder $ownerQuery): void {
                $ownerQuery
                    ->whereNotNull('user_id')
                    ->orWhereNotNull('department_id');
            });

        if ($user->isCustomerAdmin()) {
            return $query;
        }

        $membershipDepartmentIds = $user->membershipDepartmentIds();

        return $query->where(function (Builder $ownerQuery) use ($user, $membershipDepartmentIds): void {
            $ownerQuery->where('user_id', $user->id);

            if ($membershipDepartmentIds !== []) {
                $ownerQuery->orWhereIn('department_id', $membershipDepartmentIds);
            }
        });
    }
}
