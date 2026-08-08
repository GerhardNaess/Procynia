<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Absolute range
    |--------------------------------------------------------------------------
    |
    | Every resolved timeout (see EnterpriseWikiAiRequestTimeoutPolicy) is clamped to this
    | range, regardless of operation profile or job-budget margin below. Built for the Wiki
    | run-592 incident: EnterpriseWikiAiCapacityRetryExecutor called OpenAiClient::createResponse()
    | with a hardcoded `timeoutSeconds: 60`, which was too short for a large global-plan/batch
    | call and never operation- or workload-aware.
    |
    */

    'min_seconds' => (int) env('AI_REQUEST_TIMEOUT_MIN_SECONDS', 30),
    'max_seconds' => (int) env('AI_REQUEST_TIMEOUT_MAX_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Job-budget safety margin
    |--------------------------------------------------------------------------
    |
    | Fraction of the owning queue job's OWN remaining timeout budget that must always stay
    | unused — a resolved timeout must never risk letting one attempt (plus, if it fails, the
    | one allowed network retry) run the job past its own configured ceiling. See
    | EnterpriseWikiAiCapacityRetryExecutor, which also uses the remaining budget to decide
    | whether a network retry is worth attempting at all.
    |
    */

    'job_budget_margin_ratio' => (float) env('AI_REQUEST_TIMEOUT_JOB_BUDGET_MARGIN', 0.2),

    /*
    |--------------------------------------------------------------------------
    | Network retry backoff
    |--------------------------------------------------------------------------
    |
    | Short, bounded backoff (with jitter) before the ONE allowed automatic retry of a
    | documented-transient HTTP/network failure — see EnterpriseWikiTransientFailureClassifier
    | and EnterpriseWikiAiCapacityRetryExecutor. Never a long backoff: this is a same-request
    | retry within one queued job's own timeout budget, not a separately-scheduled resumption.
    |
    */

    'network_retry_backoff_base_ms' => (int) env('AI_REQUEST_TIMEOUT_RETRY_BACKOFF_BASE_MS', 250),
    'network_retry_backoff_jitter_ms' => (int) env('AI_REQUEST_TIMEOUT_RETRY_BACKOFF_JITTER_MS', 250),

    /*
    |--------------------------------------------------------------------------
    | Operation timeout profiles
    |--------------------------------------------------------------------------
    |
    | One profile per operation type, mirroring config('ai_capacity.operations')'s own
    | base/global_plan/batch structure. Each profile describes how the expected request
    | duration grows with the request: a fixed base + an input-size-driven term (larger source
    | text costs more time even at a fixed output budget) + an output-budget-driven term (the
    | resolved max_output_tokens ceiling — see EnterpriseWikiAiCapacityPlanner — stands in for
    | expected response latency, since actual tokens produced can never be known ahead of the
    | call). See EnterpriseWikiAiRequestTimeoutPolicy::resolve() for the exact formula.
    |
    | Deliberately NOT adaptive/history-based: purely a deterministic function of the request,
    | reusable and testable without a database. A response-time-history model is an explicit
    | non-goal for this task and may be considered separately later.
    |
    */

    'operations' => [

        'enterprise_wiki_maintainer_decision' => [
            'base_seconds' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BASE_SECONDS', 30),
            'seconds_per_input_chars_unit' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_INPUT_SECONDS', 1.0),
            'input_chars_per_unit' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_INPUT_CHARS_PER_UNIT', 2000),
            'seconds_per_output_token' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_OUTPUT_SECONDS_PER_TOKEN', 0.02),

            // Split-flow Phase A (global plan) — same shape as the whole-decision profile
            // above, its own values so it can be tuned independently (a global-plan call's
            // output is much smaller per input character than a full single-call decision).
            'global_plan' => [
                'base_seconds' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_GLOBAL_BASE_SECONDS', 30),
                'seconds_per_input_chars_unit' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_GLOBAL_INPUT_SECONDS', 1.0),
                'input_chars_per_unit' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_GLOBAL_INPUT_CHARS_PER_UNIT', 2000),
                'seconds_per_output_token' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_GLOBAL_OUTPUT_SECONDS_PER_TOKEN', 0.02),
            ],

            // Split-flow Phase B (candidate batches) — adds a per-candidate term, since a
            // batch with more concept candidates to decide takes longer even at the same
            // output-token ceiling (more distinct items to reason about, not just more text).
            'batch' => [
                'base_seconds' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BATCH_BASE_SECONDS', 25),
                'seconds_per_input_chars_unit' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BATCH_INPUT_SECONDS', 1.0),
                'input_chars_per_unit' => (int) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BATCH_INPUT_CHARS_PER_UNIT', 2000),
                'seconds_per_output_token' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BATCH_OUTPUT_SECONDS_PER_TOKEN', 0.02),
                'seconds_per_candidate' => (float) env('AI_REQUEST_TIMEOUT_WIKI_MAINTAINER_BATCH_SECONDS_PER_CANDIDATE', 1.5),
            ],
        ],

        // Mirrors ai_capacity.operations.enterprise_wiki_page_content — see
        // WikiPageContentAiClient (generation, figure repair, planned-section repair).
        'enterprise_wiki_page_content' => [
            'base_seconds' => (int) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_BASE_SECONDS', 30),
            'seconds_per_input_chars_unit' => (float) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_INPUT_SECONDS', 1.0),
            'input_chars_per_unit' => (int) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_INPUT_CHARS_PER_UNIT', 2000),
            'seconds_per_output_token' => (float) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_OUTPUT_SECONDS_PER_TOKEN', 0.02),

            // Planned-section repair — adds a per-section term, mirroring the
            // maintainer-decision batch profile's per-candidate term.
            'batch' => [
                'base_seconds' => (int) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_REPAIR_BASE_SECONDS', 30),
                'seconds_per_input_chars_unit' => (float) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_REPAIR_INPUT_SECONDS', 1.0),
                'input_chars_per_unit' => (int) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_REPAIR_INPUT_CHARS_PER_UNIT', 2000),
                'seconds_per_output_token' => (float) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_REPAIR_OUTPUT_SECONDS_PER_TOKEN', 0.02),
                'seconds_per_candidate' => (float) env('AI_REQUEST_TIMEOUT_WIKI_PAGE_REPAIR_SECONDS_PER_CANDIDATE', 1.5),
            ],
        ],

    ],

];
