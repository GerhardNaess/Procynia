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
 * Product rule: Basis (Wiki, Kvalitet, Avvik og forbedringer) is the one commercial source of
 * included AI capacity. Options (Risiko, Mål og KPI, Etterlevelse, Leverandøroppfølging, Anbud)
 * give access to features, never a pool of their own: every module draws on the same pool.
 * The old subscription plans (free/pro/max/ultra/enterprise) play no part here.
 *
 * Included units, in order: customers.included_ai_units (per billing period — the mechanism for
 * enterprise/special capacity) → Basis below (per month, ×12 for a yearly period) → none
 * (unmetered: observed and recorded, never refused).
 *
 * NONE of the numbers in this file are commercial decisions. They are technical defaults for
 * collecting data (docs/operations/ai-capacity.md) and are expected to change.
 */
return [

    // Internal settled AI cost, in NOK, that one AI unit represents. A technical calibration value,
    // not a price: never shown to customers, never marketed. Default 0.10.
    'nok_per_unit' => (float) env('AI_CUSTOMER_CAPACITY_NOK_PER_UNIT', 0.10),

    'basis' => [
        // TECHNICAL PLACEHOLDER — units a customer holding Basis gets per month of billing period
        // until a commercial level is decided. Null = no Basis capacity (every Basis customer
        // unmetered). Options never add to it.
        'included_units_per_month' => env('AI_CUSTOMER_CAPACITY_BASIS_UNITS_PER_MONTH', 2000) === null
            ? null
            : (int) env('AI_CUSTOMER_CAPACITY_BASIS_UNITS_PER_MONTH', 2000),

        // While true, a Basis-derived capacity is marked provisional and the customer is told the
        // level is being phased in. A customer-specific amount is never provisional.
        'provisional' => (bool) env('AI_CUSTOMER_CAPACITY_BASIS_PROVISIONAL', true),
    ],

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
