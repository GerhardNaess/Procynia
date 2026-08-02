<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model output ceilings
    |--------------------------------------------------------------------------
    |
    | Absolute max_output_tokens ceiling this project allows per model family. A
    | capacity plan's chosen budget (see EnterpriseWikiAiCapacityPlanner) can never
    | exceed the ceiling for its model, regardless of how large an operation's own
    | profile computes. Centralizes what was previously ~30 independently guessed
    | MAX_OUTPUT_TOKENS constants scattered across individual AI client classes.
    |
    */

    'models' => [
        'gpt-5' => [
            'max_output_tokens' => (int) env('AI_CAPACITY_GPT5_MAX_OUTPUT_TOKENS', 16000),
        ],
        'gpt-4.1-mini' => [
            'max_output_tokens' => (int) env('AI_CAPACITY_GPT41_MINI_MAX_OUTPUT_TOKENS', 8000),
        ],
    ],

    // Used only when a model has no entry above. Deliberately conservative — an
    // unrecognised model must never silently receive an unbounded budget.
    'default_model_max_output_tokens' => (int) env('AI_CAPACITY_DEFAULT_MODEL_MAX_OUTPUT_TOKENS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Operation capacity profiles
    |--------------------------------------------------------------------------
    |
    | One profile per AiCapacityRequest::$operationType. Each profile describes how
    | expected output size grows with the request (base overhead + a per-result-object
    | term + an input-size-driven term), plus safety margin and capacity-retry
    | behaviour. See EnterpriseWikiAiCapacityPlanner::plan() for the exact formula.
    |
    */

    'operations' => [

        'enterprise_wiki_maintainer_decision' => [
            // Fixed cost of the wrapper JSON plus source_article/source_summary,
            // regardless of concept/entity count.
            'base_overhead_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_BASE_TOKENS', 900),

            // Tokens per result-object slot (source_article/source_summary/concept/entity
            // page entry: title, slug, reason, owned/reference_only/excluded topics,
            // related_page_guidance).
            'tokens_per_result_object' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_PER_OBJECT_TOKENS', 220),

            // Input-driven contribution standing in for the number of concept_candidates
            // the AI will enumerate — unknown before it responds, so estimated from source
            // text size instead: this many tokens per this many input characters (the
            // run-583 growth driver: a longer, richer source document produces more
            // candidates, each with several free-text fields).
            'tokens_per_input_chars_unit' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_INPUT_TOKENS', 120),
            'input_chars_per_unit' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_INPUT_CHARS_PER_UNIT', 800),

            // gpt-5 reasoning tokens count against max_output_tokens even at 'low' effort —
            // run 583 spent 1344 of its 3000-token budget on reasoning alone.
            'reasoning_token_buffer' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_REASONING_BUFFER', 1500),

            'minimum_output_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_MIN_TOKENS', 1200),
            'safety_margin_ratio' => (float) env('AI_CAPACITY_WIKI_MAINTAINER_SAFETY_MARGIN', 0.35),

            // Applied once per capacity retry level (EnterpriseWikiMaintainerDecisionAiClient
            // allows at most one capacity retry), always still clamped to the lesser of the
            // model's and this operation's absolute maximum below.
            'retry_multiplier' => (float) env('AI_CAPACITY_WIKI_MAINTAINER_RETRY_MULTIPLIER', 1.75),

            'max_output_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_MAX_TOKENS', 9000),
            'max_capacity_retries' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_MAX_RETRIES', 1),

            /*
            |----------------------------------------------------------------
            | Split-flow global-plan profile
            |----------------------------------------------------------------
            |
            | Sizes EnterpriseWikiMaintainerDecisionSplitCoordinator's Phase A call (global plan +
            | compact concept_candidate_mentions identification only — no full candidate
            | disposition). Deliberately a MUCH smaller tokens_per_input_chars_unit than the
            | profile above: reusing that value here would size Phase A as if it still had to
            | return full candidate detail for every candidate, defeating the point of splitting
            | in the first place (Phase A would itself compute strategy=split_required, since it
            | shares the same large input).
            |
            */
            'global_plan' => [
                'base_overhead_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_BASE_TOKENS', 900),
                'tokens_per_result_object' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_PER_OBJECT_TOKENS', 220),
                'tokens_per_input_chars_unit' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_INPUT_TOKENS', 40),
                'input_chars_per_unit' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_INPUT_CHARS_PER_UNIT', 800),
                'reasoning_token_buffer' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_REASONING_BUFFER', 1500),
                'minimum_output_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_MIN_TOKENS', 1200),
                'safety_margin_ratio' => (float) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_SAFETY_MARGIN', 0.35),
                'retry_multiplier' => (float) env('AI_CAPACITY_WIKI_MAINTAINER_GLOBAL_RETRY_MULTIPLIER', 1.75),
            ],

            /*
            |----------------------------------------------------------------
            | Split-flow batch profile
            |----------------------------------------------------------------
            |
            | Used only when EnterpriseWikiAiCapacityPlanner::plan() returns
            | strategy=split_required — sizes EnterpriseWikiMaintainerDecisionSplitCoordinator's
            | per-batch concept-candidate calls. A concept_candidates-only response has a
            | different per-item token cost than a full page entry, hence its own
            | tokens_per_candidate/base_overhead rather than reusing the values above.
            |
            */
            'batch' => [
                // Fixed cost of the batch response's wrapper JSON (concept_candidates +
                // concept_pages arrays), independent of candidate count.
                'batch_overhead_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_BATCH_OVERHEAD_TOKENS', 400),

                // Estimated tokens per candidate this batch decides — the full 9-field
                // concept_candidates entry, plus its concept_pages entry when decision=create.
                'tokens_per_candidate' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_BATCH_TOKENS_PER_CANDIDATE', 260),

                'safety_margin_ratio' => (float) env('AI_CAPACITY_WIKI_MAINTAINER_BATCH_SAFETY_MARGIN', 0.35),
                'minimum_output_tokens' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_BATCH_MIN_TOKENS', 800),

                // Capacity-driven batch size (computed from the above) is further capped by
                // this configured ceiling, and floored at the configured minimum — the planner
                // always takes the smaller of the two caps, never just one or the other.
                'max_candidates_per_batch' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_MAX_CANDIDATES_PER_BATCH', 6),
                'min_candidates_per_batch' => (int) env('AI_CAPACITY_WIKI_MAINTAINER_MIN_CANDIDATES_PER_BATCH', 1),
            ],
        ],

    ],

];
