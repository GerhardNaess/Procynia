<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProfileMatch extends Model
{
    protected $fillable = [
        'customer_id',
        'department_id',
        'watch_profile_id',
        'notice_id',
        'score',
        'matched_keywords_count',
        'matched_cpv_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'matched_keywords_count' => 'integer',
            'matched_cpv_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function watchProfile(): BelongsTo
    {
        return $this->belongsTo(WatchProfile::class);
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
