<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoticeAttention extends Model
{
    protected $fillable = [
        'notice_id',
        'customer_id',
        'department_id',
        'department_score',
        'relevance_level',
        'is_new',
        'first_seen_at',
        'last_seen_at',
        'read_at',
        'read_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_new' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function readBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by_user_id');
    }
}
