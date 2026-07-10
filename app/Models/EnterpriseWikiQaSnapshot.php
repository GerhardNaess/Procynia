<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiQaSnapshot extends Model
{
    protected $fillable = [
        'enterprise_wiki_ingest_run_id',
        'customer_id',
        'qa_status',
        'qa_attempt_count',
        'snapshotted_at',
        'technical_qa_passed',
        'structural_qa_passed',
        'open_lint_errors',
        'lint_error_count',
        'lint_warning_count',
        'semantic_qa_ran',
        'semantic_pass',
        'semantic_quality_score',
        'semantic_coverage_score',
        'semantic_factual_score',
        'semantic_missing_topics_count',
        'semantic_missing_key_facts_count',
        'semantic_unsupported_claims_count',
        'semantic_source_hash',
        'semantic_page_version_id',
        'semantic_model',
        'semantic_prompt_version',
        'semantic_repair_attempted',
        'semantic_repair_success',
        'semantic_repair_previous_version_id',
        'semantic_repair_new_version_id',
        'semantic_repair_model',
        'semantic_post_repair_page_version_id',
        'semantic_post_repair_pass',
        'semantic_post_repair_quality_score',
        'semantic_post_repair_coverage_score',
        'semantic_post_repair_factual_score',
    ];

    protected $casts = [
        'snapshotted_at'                    => 'datetime',
        'technical_qa_passed'               => 'boolean',
        'structural_qa_passed'              => 'boolean',
        'open_lint_errors'                  => 'boolean',
        'lint_error_count'                  => 'integer',
        'lint_warning_count'                => 'integer',
        'semantic_qa_ran'                   => 'boolean',
        'semantic_pass'                     => 'boolean',
        'semantic_quality_score'            => 'float',
        'semantic_coverage_score'           => 'float',
        'semantic_factual_score'            => 'float',
        'semantic_missing_topics_count'     => 'integer',
        'semantic_missing_key_facts_count'  => 'integer',
        'semantic_unsupported_claims_count' => 'integer',
        'semantic_page_version_id'          => 'integer',
        'semantic_repair_attempted'         => 'boolean',
        'semantic_repair_success'           => 'boolean',
        'semantic_repair_previous_version_id' => 'integer',
        'semantic_repair_new_version_id'       => 'integer',
        'semantic_post_repair_page_version_id' => 'integer',
        'semantic_post_repair_pass'            => 'boolean',
        'semantic_post_repair_quality_score'  => 'float',
        'semantic_post_repair_coverage_score' => 'float',
        'semantic_post_repair_factual_score'  => 'float',
    ];

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }
}
