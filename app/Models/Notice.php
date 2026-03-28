<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Notice extends Model
{
    protected $fillable = [
        'notice_id',
        'title',
        'description',
        'notice_type',
        'notice_subtype',
        'status',
        'publication_date',
        'issue_date',
        'deadline',
        'estimated_value_amount',
        'estimated_value_currency',
        'buyer_name',
        'buyer_org_number',
        'buyer_city',
        'buyer_postal_code',
        'buyer_region_code',
        'buyer_country_code',
        'contact_name',
        'contact_email',
        'contact_phone',
        'internal_status',
        'internal_comment',
        'decision_by_user_id',
        'assigned_to_user_id',
        'status_changed_at',
        'status_changed_by_user_id',
        'relevance_level',
        'relevance_score',
        'score_breakdown',
        'department_scores',
        'visible_to_departments',
        'raw_xml_stored',
        'downloaded_at',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'publication_date' => 'datetime',
            'issue_date' => 'datetime',
            'deadline' => 'datetime',
            'status_changed_at' => 'datetime',
            'estimated_value_amount' => 'decimal:2',
            'relevance_score' => 'integer',
            'score_breakdown' => 'array',
            'department_scores' => 'array',
            'visible_to_departments' => 'array',
            'raw_xml_stored' => 'boolean',
            'downloaded_at' => 'datetime',
            'parsed_at' => 'datetime',
        ];
    }

    public function cpvCodes(): HasMany
    {
        return $this->hasMany(NoticeCpvCode::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(NoticeLot::class);
    }

    public function rawXml(): HasOne
    {
        return $this->hasOne(NoticeRawXml::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function attentions(): HasMany
    {
        return $this->hasMany(NoticeAttention::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(NoticeDocument::class)->orderBy('sort_order');
    }

    public function decisionByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(NoticeDecision::class)->latest('id');
    }
}
