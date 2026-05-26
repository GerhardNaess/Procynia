<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoNoGoAssessmentAnswer extends Model
{
    protected $table = 'go_no_go_assessment_answers';

    protected $fillable = [
        'assessment_id',
        'criterion_id',
        'selected_value',
        'score',
        'comment',
    ];

    protected $casts = [
        'score' => 'integer',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(GoNoGoAssessment::class, 'assessment_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(GoNoGoAssessmentCriterion::class, 'criterion_id');
    }
}
