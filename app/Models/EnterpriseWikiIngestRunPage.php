<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiIngestRunPage extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
    ];

    public const GENERATION_STATUS_PENDING = 'pending';

    public const GENERATION_STATUS_RUNNING = 'running';

    public const GENERATION_STATUS_COMPLETED = 'completed';

    public const GENERATION_STATUS_FAILED = 'failed';

    public const GENERATION_STATUSES = [
        self::GENERATION_STATUS_PENDING,
        self::GENERATION_STATUS_RUNNING,
        self::GENERATION_STATUS_COMPLETED,
        self::GENERATION_STATUS_FAILED,
    ];

    protected $fillable = [
        'enterprise_wiki_ingest_run_id',
        'enterprise_wiki_page_id',
        'action',
        'generated_page_version_id',
        'generation_status',
        'generation_started_at',
        'generation_completed_at',
        'generation_error',
        'claims_extracted_at',
        'claims_claimed_at',
        'claims_claim_token',
    ];

    protected function casts(): array
    {
        return [
            'generation_started_at' => 'datetime',
            'generation_completed_at' => 'datetime',
            'claims_extracted_at' => 'datetime',
            'claims_claimed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function generatedPageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'generated_page_version_id');
    }

    public function isGenerationTerminal(): bool
    {
        return in_array($this->generation_status, [
            self::GENERATION_STATUS_COMPLETED,
            self::GENERATION_STATUS_FAILED,
        ], true);
    }
}
