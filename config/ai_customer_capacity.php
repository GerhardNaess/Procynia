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
 * Product model: Basis + options + AI capacity — three separate commercial dimensions. Basis (Wiki,
 * Kvalitet, Avvik og forbedringer) and the options (Risiko, Mål og KPI, Etterlevelse,
 * Leverandøroppfølging, Anbud) give access to features and never include AI capacity. AI capacity
 * is a dimension of its own: one pool per customer that every module draws on. The old
 * subscription plans (free/pro/max/ultra/enterprise) play no part here either.
 *
 * Included units, in order: customers.included_ai_units (an explicit per-customer override, per
 * billing period) → customers.ai_capacity_tier (a tier below, per month, ×12 for a yearly period)
 * → unconfigured (no commercial limit: usage is still recorded and observed, never refused).
 *
 * NONE of the numbers in this file are commercial decisions. They are technical defaults for
 * collecting data (docs/operations/ai-capacity.md) and are expected to change.
 */
return [

    // Internal settled AI cost, in NOK, that one AI unit represents. A technical calibration value,
    // not a price: never shown to customers, never marketed. Default 0.10.
    'nok_per_unit' => (float) env('AI_CUSTOMER_CAPACITY_NOK_PER_UNIT', 0.10),

    /*
     * AI capacity tiers, selected per customer (customers.ai_capacity_tier holds the key).
     *
     * TECHNICAL PLACEHOLDERS — not commercial levels. The keys are neutral on purpose; names,
     * included units and the number of tiers will be decided from production usage
     * (ai:capacity-analysis). No tier carries a price: there is no Stripe price for AI capacity yet.
     *
     *  - name:                     internal display name; the customer sees procynia.billing.ai_capacity.tier_names.<key>
     *  - included_units_per_month: AI units per month of billing period (×12 for a yearly period)
     *  - active:                   offered when an admin picks a tier. An inactive tier still applies
     *                              to a customer who already holds it, so retiring one never
     *                              silently removes a customer's capacity.
     *  - sort_order:               order in the admin select
     *
     * A key a customer holds that is not listed here resolves to unconfigured.
     */
    'tiers' => [
        'level_1' => ['name' => 'Level 1 (placeholder)', 'included_units_per_month' => 1000, 'active' => true, 'sort_order' => 10],
        'level_2' => ['name' => 'Level 2 (placeholder)', 'included_units_per_month' => 2500, 'active' => true, 'sort_order' => 20],
        'level_3' => ['name' => 'Level 3 (placeholder)', 'included_units_per_month' => 5000, 'active' => true, 'sort_order' => 30],
    ],

    // While true, a tier-derived capacity is marked provisional and the customer is told the level
    // may be adjusted. An explicit customer override is never provisional.
    'tiers_provisional' => (bool) env('AI_CUSTOMER_CAPACITY_TIERS_PROVISIONAL', true),

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
