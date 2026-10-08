<?php

/*
 * The one registry of AI operations: what Procynia asks a model to do, which product area it
 * belongs to, and which model does it.
 *
 * Every provider call carries one of these keys as its `operation_key` on `ai_usage_attempts`, and
 * the feature is always the key's first segment — so usage, cost and model choice are all read off
 * the same name. A client never names a model itself; it asks AiOperationCatalog::model() for its
 * operation. Changing a model is therefore a config change here, never a code change in a client.
 *
 * Naming: `<feature>.<operation>`, with an optional further segment for a variant of the same
 * operation (`tender.requirement_extraction.segment`). Model lookup falls back one segment at a
 * time, so a variant inherits its parent's model unless it is listed itself.
 *
 * The defaults below are the models each call used before this registry existed. This file moves
 * the choice; it deliberately does not change it.
 */

$defaultModel = env('OPENAI_MODEL', 'gpt-4.1-mini');
$requirementExtractionModel = env('OPENAI_REQUIREMENT_EXTRACTION_MODEL', $defaultModel);
$qualityFlowModel = env('QUALITY_FLOW_MODEL', $defaultModel);

return [
    /*
     * The product areas usage is attributed to. `system` is reserved for work Procynia does for
     * itself with no customer behind it, and is only valid on a context explicitly marked as such.
     */
    'features' => [
        'tender',
        'wiki',
        'quality',
        'supplier',
        'compliance',
        'risk',
        'improvements',
        'objectives',
        'document_analysis',
        'system',
    ],

    /*
     * Names historical `ai_usage_attempts` rows carry from before the standard. The ledger is
     * append-only and is never rewritten; readers translate through AiOperationCatalog instead.
     */
    'legacy_features' => [
        'saved_notice' => 'tender',
        'enterprise_wiki' => 'wiki',
    ],

    'legacy_operation_prefixes' => [
        'operator.wiki.' => 'wiki.operator.',
        'saved_notice.' => 'tender.',
        'enterprise_wiki.' => 'wiki.',
    ],

    'legacy_operations' => [
        'process_flow_interpretation' => 'quality.interpret_process',
        'process_flow_clarification' => 'quality.clarify_process',
        'process_flow_change' => 'quality.propose_process_change',
        'process_activity_article_draft' => 'quality.draft_activity_article',
    ],

    /*
     * When a customer-driven call reaches the provider without a customer:
     *   warn   — the call proceeds, is recorded as `unattributed`, and is logged and alerted.
     *   strict — the call is refused before any money is spent.
     * A missing feature or operation is always refused, in both modes.
     */
    'context_enforcement' => env('AI_CONTEXT_ENFORCEMENT', 'warn'),

    'operations' => [
        // Anbud
        'tender.requirement_extraction' => ['model' => $requirementExtractionModel],
        // The full-document prompt has always pinned its model independently of the env override.
        'tender.requirement_extraction.document' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_relevance' => ['model' => env('OPENAI_REQUIREMENT_RELEVANCE_MODEL', $requirementExtractionModel)],
        'tender.excel_structure_discovery' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_assessment' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_research' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_answer' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_answer_revision' => ['model' => 'gpt-4.1-mini'],
        'tender.requirement_alignment' => ['model' => 'gpt-4.1-mini'],

        // Enterprise Wiki — ingest and maintenance
        'wiki.maintainer_decision' => ['model' => 'gpt-5'],
        'wiki.generate_page' => ['model' => 'gpt-5'],
        'wiki.repair_page_sections' => ['model' => 'gpt-5'],
        'wiki.repair_page_figures' => ['model' => 'gpt-5'],
        'wiki.extract_page_claims' => ['model' => 'gpt-4.1-mini'],
        'wiki.verify_claim' => ['model' => 'gpt-4.1-mini'],
        'wiki.revise_semantics' => ['model' => 'gpt-5'],
        'wiki.revise_links' => ['model' => 'gpt-5'],
        'wiki.review_links' => ['model' => 'gpt-4.1-mini'],
        'wiki.review_semantics' => ['model' => 'gpt-4.1-mini'],
        'wiki.classify_cross_page_consistency' => ['model' => 'gpt-4.1-mini'],
        // Legacy section-by-section ingest (app/Jobs/Ai/Wiki).
        'wiki.extract_section_claims' => ['model' => 'gpt-4.1-mini'],
        'wiki.generate_article' => ['model' => 'gpt-5'],

        // Enterprise Wiki — navigation and Q&A, shared with requirement research
        'wiki.navigation_plan' => ['model' => 'gpt-4.1-mini'],
        'wiki.ask.retrieval_plan' => ['model' => 'gpt-4.1-mini'],
        'wiki.ask.answer' => ['model' => 'gpt-4.1-mini'],

        // Kvalitet
        'quality.interpret_process' => ['model' => $qualityFlowModel],
        'quality.clarify_process' => ['model' => $qualityFlowModel],
        'quality.propose_process_change' => ['model' => $qualityFlowModel],
        'quality.draft_activity_article' => ['model' => $qualityFlowModel],
    ],
];
