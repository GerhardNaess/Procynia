<?php

/*
 * The shared, customer-facing AI capacity: one pool per customer and billing period, used by every
 * Procynia module, expressed in AI units. (Not to be confused with config/ai_capacity.php, which
 * sizes output-token budgets per provider call.)
 *
 * An AI unit is a commercial unit, not a token and not a price. The usage ledger
 * (ai_usage_attempts) keeps recording internal cost in NOK; this file is the one place that
 * converts that cost into units. Changing the conversion re-reads all history at the new rate and
 * never touches the ledger, the customer UI or the subscription concept.
 *
 * Included capacity lives with the plans (config/procynia_plans.php `included_ai_units`) and can be
 * overridden per customer (customers.included_ai_units).
 */
return [

    // Internal settled AI cost, in NOK, that one AI unit represents. v1: 1 unit = 0.10 NOK.
    'nok_per_unit' => (float) env('AI_CUSTOMER_CAPACITY_NOK_PER_UNIT', 0.10),

    // Percent of the included capacity *settled* (actually used) at which the status changes.
    'thresholds' => [
        'warning_percent' => 80,
        'exhausted_percent' => 100,
    ],

    // Temporarily reserved capacity (calls in flight, or whose cost is still open) is shown to the
    // customer only when it holds at least this share of the included capacity, or when it is what
    // stops a new operation from starting. Below it, it is normal background noise.
    'reservation_notice_percent' => 5,

    /*
     * What the capacity gate does when an AI call would not fit in the available capacity:
     *  - off:     the gate is not evaluated
     *  - observe: evaluated and logged ("would refuse"); the call proceeds. The default while the
     *             Anbud AI-case quota is still the commercial gate.
     *  - enforce: the call is refused with a customer-facing reason (an operator override can
     *             bypass it, like the AI-case quota)
     */
    'enforcement' => env('AI_CUSTOMER_CAPACITY_ENFORCEMENT', 'observe'),

];
