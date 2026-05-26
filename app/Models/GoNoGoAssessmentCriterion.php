<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoNoGoAssessmentCriterion extends Model
{
    protected $table = 'go_no_go_assessment_criteria';

    protected $fillable = [
        'template_id',
        'title',
        'short_description',
        'help_what_is_assessed',
        'help_why_it_matters',
        'help_what_to_investigate',
        'help_positive_indicators',
        'help_warning_signs',
        'help_example_assessment',
        'weight',
        'is_score_reversed',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'weight'           => 'integer',
        'sort_order'       => 'integer',
        'is_score_reversed' => 'boolean',
        'is_active'        => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(GoNoGoAssessmentTemplate::class, 'template_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GoNoGoAssessmentAnswer::class, 'criterion_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Compute the numeric score contribution for a given selected value. */
    public function computeScore(string $selectedValue): int
    {
        $base = match ($selectedValue) {
            'lav'     => 1,
            'middels' => 2,
            'hoy'     => 3,
            default   => 0,
        };

        return ($this->is_score_reversed ? (4 - $base) : $base) * $this->weight;
    }
}
