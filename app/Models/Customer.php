<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'nationality_id',
        'language_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Nationality::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function watchProfiles(): HasMany
    {
        return $this->hasMany(WatchProfile::class);
    }

    public function watchProfileInboxRecords(): HasMany
    {
        return $this->hasMany(WatchProfileInboxRecord::class);
    }

    public function noticeDecisions(): HasMany
    {
        return $this->hasMany(NoticeDecision::class);
    }

    public function noticeAttentions(): HasMany
    {
        return $this->hasMany(NoticeAttention::class);
    }
}
