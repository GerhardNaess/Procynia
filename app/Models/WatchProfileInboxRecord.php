<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProfileInboxRecord extends Model
{
    protected $fillable = [
        'watch_profile_id',
        'customer_id',
        'user_id',
        'department_id',
        'doffin_notice_id',
        'title',
        'buyer_name',
        'publication_date',
        'deadline',
        'external_url',
        'relevance_score',
        'discovered_at',
        'last_seen_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'publication_date' => 'datetime',
            'deadline' => 'datetime',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function watchProfile(): BelongsTo
    {
        return $this->belongsTo(WatchProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $query->where('customer_id', $user->customer_id);

        if ($user->isCustomerAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $scopeQuery) use ($user): void {
            $scopeQuery->where('user_id', $user->id);

            if ($user->department_id !== null) {
                $scopeQuery->orWhere('department_id', $user->department_id);
            }
        });
    }

    public function scopeUserInbox(Builder $query, User $user): Builder
    {
        return $query
            ->where('customer_id', $user->customer_id)
            ->where('user_id', $user->id);
    }

    public function scopeDepartmentInbox(Builder $query, User $user): Builder
    {
        if ($user->department_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('customer_id', $user->customer_id)
            ->where('department_id', $user->department_id);
    }
}
