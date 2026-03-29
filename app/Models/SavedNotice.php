<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNotice extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'saved_by_user_id',
        'external_id',
        'title',
        'buyer_name',
        'external_url',
        'summary',
        'publication_date',
        'deadline',
        'status',
        'cpv_code',
        'archived_at',
        'questions_deadline_at',
        'questions_rfi_deadline_at',
        'rfi_submission_deadline_at',
        'questions_rfp_deadline_at',
        'rfp_submission_deadline_at',
        'award_date_at',
        'selected_supplier_name',
        'contract_value_mnok',
        'contract_period_months',
        'next_process_date_at',
    ];

    protected $casts = [
        'publication_date' => 'datetime',
        'deadline' => 'datetime',
        'archived_at' => 'datetime',
        'questions_deadline_at' => 'datetime',
        'questions_rfi_deadline_at' => 'datetime',
        'rfi_submission_deadline_at' => 'datetime',
        'questions_rfp_deadline_at' => 'datetime',
        'rfp_submission_deadline_at' => 'datetime',
        'award_date_at' => 'datetime',
        'contract_value_mnok' => 'decimal:2',
        'contract_period_months' => 'integer',
        'next_process_date_at' => 'datetime',
    ];

    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by_user_id');
    }
}
