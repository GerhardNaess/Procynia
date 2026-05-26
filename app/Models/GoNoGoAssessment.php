<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoNoGoAssessment extends Model
{
    protected $table = 'go_no_go_assessments';

    protected $fillable = [
        'saved_notice_id',
        'template_id',
        'customer_id',
        'created_by',
        'updated_by',
        'recommendation',
        'total_score',
        'max_score',
        'completed_at',
    ];

    protected $casts = [
        'total_score'  => 'integer',
        'max_score'    => 'integer',
        'completed_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(GoNoGoAssessmentTemplate::class, 'template_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GoNoGoAssessmentAnswer::class, 'assessment_id');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
