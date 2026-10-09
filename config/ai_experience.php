<?php

/*
 * The internal AI experience model (Admin → AI-erfaring, `ai:experience-refresh`,
 * `ai:capacity-analysis --experience`): how actual AI usage varies with users, modules and
 * capacity levels, per customer and billing period.
 *
 * ANALYSIS ONLY. Nothing here, and nothing that reads it, changes a weight, a multiplier, a price
 * or a customer's capacity. These values only decide when a period is closed for analysis and when
 * a sample is large enough to be shown without a warning.
 */
return [

    // A billing period becomes final this many days after it ends, so calls still settling at the
    // boundary land before the figures are frozen. Later changes are recorded as revisions.
    'finalize_after_days' => (int) env('AI_EXPERIENCE_FINALIZE_AFTER_DAYS', 3),

    // Below any of these, an analysis says «For lite datagrunnlag for sikker vurdering». The data
    // is still shown.
    'minimum_sample' => [
        'customers' => (int) env('AI_EXPERIENCE_MIN_CUSTOMERS', 5),
        'periods' => (int) env('AI_EXPERIENCE_MIN_PERIODS', 10),
        'calls' => (int) env('AI_EXPERIENCE_MIN_CALLS', 200),
    ],

    // p95 ÷ median at or above this is reported as «Stor variasjon».
    'large_variation_ratio' => 3.0,

];
