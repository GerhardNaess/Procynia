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

        /*
        |----------------------------------------------------------------
        | Wiki page content (generation, figure repair, section repair)
        |----------------------------------------------------------------
        |
        | Wiki run-6: WikiPageContentAiClient previously used one hardcoded
        | max_output_tokens=6000 for every call — full-page generation, figure repair
        | (also returns a full page), AND planned-section repair (returns only the
        | missing/empty section(s), but was still charged the same flat ceiling
        | regardless of how many sections needed repair). Run 6's article page had 2
        | planned sections missing after the first generation pass, and the repair
        | call for both of them together hit status=incomplete/max_output_tokens
        | (input_tokens=15594, output_tokens capped at exactly 6000 — 768 of it spent
        | on reasoning, leaving too little for two full sections' worth of structured
        | blocks). Adopts EnterpriseWikiAiCapacityPlanner/EnterpriseWikiAiCapacityRetryExecutor
        | (built generically for EnterpriseWikiMaintainerDecisionAiClient, "another Wiki
        | AI client can adopt it later ... with no change to this class") instead of a
        | second, bespoke retry mechanism.
        |
        */
        'enterprise_wiki_page_content' => [
            // Fixed cost of the page.blocks wrapper JSON, independent of block count.
            'base_overhead_tokens' => (int) env('AI_CAPACITY_WIKI_PAGE_BASE_TOKENS', 400),

            // Tokens per expected content block (markdown prose + content_origin +
            // source_element_keys/types + best_practice_reason + link_intents) — used
            // for full-page generation/figure-repair, scaled per page type (see
            // WikiPageContentAiClient::EXPECTED_BLOCK_COUNTS) since an article page
            // has materially more blocks than a summary or concept page.
            'tokens_per_result_object' => (int) env('AI_CAPACITY_WIKI_PAGE_PER_BLOCK_TOKENS', 260),

            // Input-driven contribution standing in for how much body content a
            // longer source document justifies — unknown before the model responds.
            'tokens_per_input_chars_unit' => (int) env('AI_CAPACITY_WIKI_PAGE_INPUT_TOKENS', 60),
            'input_chars_per_unit' => (int) env('AI_CAPACITY_WIKI_PAGE_INPUT_CHARS_PER_UNIT', 800),

            // gpt-5 reasoning tokens count against max_output_tokens even at 'low'
            // effort — same empirically-derived figure as enterprise_wiki_maintainer_
            // decision above (run 583 spent 1344 of 3000 on reasoning alone); run 6's
            // article repair independently observed 768 of 6000.
            'reasoning_token_buffer' => (int) env('AI_CAPACITY_WIKI_PAGE_REASONING_BUFFER', 1500),

            'minimum_output_tokens' => (int) env('AI_CAPACITY_WIKI_PAGE_MIN_TOKENS', 2500),
            'safety_margin_ratio' => (float) env('AI_CAPACITY_WIKI_PAGE_SAFETY_MARGIN', 0.35),

            // Applied once per capacity retry level (at most one — see
            // EnterpriseWikiAiCapacityRetryExecutor), still clamped to max_output_tokens below.
            'retry_multiplier' => (float) env('AI_CAPACITY_WIKI_PAGE_RETRY_MULTIPLIER', 1.6),

            'max_output_tokens' => (int) env('AI_CAPACITY_WIKI_PAGE_MAX_TOKENS', 9000),

            /*
            |----------------------------------------------------------------
            | Planned-section repair profile
            |----------------------------------------------------------------
            |
            | Sizes WikiPageContentAiClient::repairPlannedSections() — reuses
            | EnterpriseWikiAiCapacityPlanner::planBatchCall()'s existing "N candidates"
            | shape, treating each missing/empty/link-only planned section as one
            | candidate, so a 2-section repair (run 6) gets a materially larger budget
            | than a 1-section repair instead of sharing the exact same flat ceiling.
            | Inherits reasoning_token_buffer/retry_multiplier/max_output_tokens/the
            | input-size term from the profile above (unset here).
            |
            */
            'batch' => [
                // Fixed cost of the sections.blocks wrapper JSON, independent of how
                // many sections are being repaired.
                'batch_overhead_tokens' => (int) env('AI_CAPACITY_WIKI_PAGE_REPAIR_OVERHEAD_TOKENS', 500),

                // Estimated tokens per repaired section — a full substantial paragraph
                // (or more) of prose plus its block-schema fields; larger than a single
                // generation block's own per-object estimate above since a whole
                // planned section can span more than one block.
                'tokens_per_candidate' => (int) env('AI_CAPACITY_WIKI_PAGE_REPAIR_TOKENS_PER_SECTION', 1800),

                'safety_margin_ratio' => (float) env('AI_CAPACITY_WIKI_PAGE_REPAIR_SAFETY_MARGIN', 0.35),
                'minimum_output_tokens' => (int) env('AI_CAPACITY_WIKI_PAGE_REPAIR_MIN_TOKENS', 3000),
            ],
        ],

    ],

];
