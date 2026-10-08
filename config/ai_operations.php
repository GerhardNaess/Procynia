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

    /*
     * Pre-call reservation: what an operation is expected to cost at most, priced before the call
     * so a customer's NOK budget is checked before money is spent (AiOperationalPricingService).
     *
     * Each operation states its own `estimate` next to its model. `output_tokens` is the output
     * ceiling the client sends as max_output_tokens (or the model's capacity ceiling where the
     * planner chooses it), so the reservation can never be outrun by the call's own output.
     * `input_tokens` is a conservative ceiling above the largest prompts observed for that
     * operation. An estimate is never the actual cost: the attempt ledger records what the
     * provider reported, and the reservation is settled against that.
     *
     * A variant inherits its parent's estimate unless it is listed itself, the same way it
     * inherits the model. `fallback_estimate` is an emergency rule only: an operation that reaches
     * it is logged, because it means a new operation was added without its own estimate.
     */
    'reservation' => [
        'fallback_estimate' => ['input_tokens' => 60000, 'output_tokens' => 16000],
    ],

    'operations' => [
        // Anbud
        'tender.requirement_extraction' => ['model' => $requirementExtractionModel, 'estimate' => ['input_tokens' => 60000, 'output_tokens' => 16000]],
        'tender.requirement_extraction.segment' => ['estimate' => ['input_tokens' => 20000, 'output_tokens' => 1200]],
        'tender.requirement_extraction.block' => ['estimate' => ['input_tokens' => 30000, 'output_tokens' => 3000]],
        // The full-document prompt has always pinned its model independently of the env override.
        'tender.requirement_extraction.document' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 60000, 'output_tokens' => 8000]],
        'tender.requirement_relevance' => ['model' => env('OPENAI_REQUIREMENT_RELEVANCE_MODEL', $requirementExtractionModel), 'estimate' => ['input_tokens' => 8000, 'output_tokens' => 256]],
        'tender.excel_structure_discovery' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 30000, 'output_tokens' => 3000]],
        'tender.requirement_assessment' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 800]],
        'tender.requirement_research' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 500]],
        'tender.requirement_answer' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 40000, 'output_tokens' => 2000]],
        'tender.requirement_answer_revision' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 1200]],
        'tender.requirement_alignment' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 2000]],

        // Enterprise Wiki — ingest and maintenance. gpt-5 output ceilings include reasoning tokens.
        'wiki.maintainer_decision' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 60000, 'output_tokens' => 16000]],
        'wiki.generate_page' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 60000, 'output_tokens' => 16000]],
        'wiki.repair_page_sections' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 40000, 'output_tokens' => 16000]],
        'wiki.repair_page_figures' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 40000, 'output_tokens' => 16000]],
        'wiki.extract_page_claims' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 4000]],
        'wiki.verify_claim' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 8000, 'output_tokens' => 900]],
        'wiki.revise_semantics' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 30000, 'output_tokens' => 4000]],
        'wiki.revise_links' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 30000, 'output_tokens' => 3000]],
        'wiki.review_links' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 1000]],
        'wiki.review_semantics' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 1500]],
        'wiki.classify_cross_page_consistency' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 10000, 'output_tokens' => 700]],
        // Legacy section-by-section ingest (app/Jobs/Ai/Wiki).
        'wiki.extract_section_claims' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 20000, 'output_tokens' => 2000]],
        'wiki.generate_article' => ['model' => 'gpt-5', 'estimate' => ['input_tokens' => 30000, 'output_tokens' => 4000]],

        // Enterprise Wiki — navigation and Q&A, shared with requirement research
        'wiki.navigation_plan' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 12000, 'output_tokens' => 1200]],
        'wiki.ask.retrieval_plan' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 12000, 'output_tokens' => 1200]],
        'wiki.ask.answer' => ['model' => 'gpt-4.1-mini', 'estimate' => ['input_tokens' => 40000, 'output_tokens' => 1200]],

        // Kvalitet
        'quality.interpret_process' => ['model' => $qualityFlowModel, 'estimate' => ['input_tokens' => 10000, 'output_tokens' => 4000]],
        'quality.clarify_process' => ['model' => $qualityFlowModel, 'estimate' => ['input_tokens' => 6000, 'output_tokens' => 4000]],
        'quality.propose_process_change' => ['model' => $qualityFlowModel, 'estimate' => ['input_tokens' => 10000, 'output_tokens' => 3000]],
        'quality.draft_activity_article' => ['model' => $qualityFlowModel, 'estimate' => ['input_tokens' => 10000, 'output_tokens' => 4000]],
    ],
];
