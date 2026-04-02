<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'description',
        'is_active',
        // Deprecated legacy targeting fields kept temporarily for safe migration only.
        'cpv_whitelist',
        'keywords',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Deprecated legacy targeting fields kept temporarily for safe migration only.
            'cpv_whitelist' => 'array',
            'keywords' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps()
            ->orderBy('users.name');
    }

    public function managedBidManagers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bid_manager_departments')
            ->withTimestamps()
            ->orderBy('users.name');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attentions(): HasMany
    {
        return $this->hasMany(NoticeAttention::class);
    }

    public function watchProfiles(): HasMany
    {
        return $this->hasMany(WatchProfile::class);
    }

    public function watchProfileInboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }
}
