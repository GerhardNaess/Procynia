<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoNoGoAssessmentTemplate extends Model
{
    protected $table = 'go_no_go_assessment_templates';

    protected $fillable = [
        'customer_id',
        'name',
        'description',
        'is_default',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

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

    public function criteria(): HasMany
    {
        return $this->hasMany(GoNoGoAssessmentCriterion::class, 'template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeCriteria(): HasMany
    {
        return $this->criteria()->where('is_active', true);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(GoNoGoAssessment::class, 'template_id');
    }
}
