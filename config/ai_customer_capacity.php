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
 * billing period) → base capacity × the customer's tier multiplier (customers.ai_capacity_tier, or
 * default_tier when the customer has not chosen one) → unconfigured, only when no tier applies at
 * all (no commercial limit: usage is still recorded and observed, never refused).
 *
 *   base capacity (per month) = basis + per_user × active users + Σ active options
 *   included (per month)      = round(base capacity × tier multiplier)        (half up, whole units)
 *   included (per period)     = included per month × 12 for a yearly period, × 1 otherwise
 *
 * The weights only SIZE the one shared pool. No module gets a pool or a quota of its own.
 *
 * MANUALLY CALIBRATED. Every number in this file is set by Procynia by hand and changed only by a
 * deliberate edit. Nothing — not ai:capacity-analysis, not usage history — adjusts them
 * automatically; the analysis only informs a person deciding whether to change them
 * (docs/operations/ai-capacity.md).
 */
return [

    // Internal settled AI cost, in NOK, that one AI unit represents. A technical calibration value,
    // not a price: never shown to customers, never marketed. Default 0.10.
    'nok_per_unit' => (float) env('AI_CUSTOMER_CAPACITY_NOK_PER_UNIT', 0.10),

    /*
     * Base capacity, in AI units per month. MANUALLY CALIBRATED placeholders.
     *
     *  - basis:    when the customer holds Basis
     *  - per_user: per active user (active customer_admin/user, as BillingEntitlementService counts them)
     *  - options:  per active option, keyed by the package key in config/procynia_modules.php
     *
     * A package not listed adds nothing. Recomputed on every read: adding or removing a user or an
     * option changes the base at once and never changes the customer's chosen tier.
     */
    'base' => [
        'basis' => 500,
        'per_user' => 50,
        'options' => [
            'risk' => 150,
            'objectives' => 100,
            'compliance' => 150,
            'supplier' => 150,
            'tender' => 400,
        ],
    ],

    /*
     * AI capacity tiers: multipliers on the customer's base capacity. The customer picks one on
     * Abonnement (customers.ai_capacity_tier holds the key); the customer sees the level name and
     * the resulting units, never the multiplier. MANUALLY CALIBRATED placeholders. No tier carries a
     * price yet: there is no Stripe price for AI capacity.
     *
     *  - name:        internal display name; the customer sees procynia.billing.ai_capacity.tier_names.<key>
     *  - multiplier:  applied to the base capacity
     *  - active:      offered for selection. An inactive tier still applies to a customer who already
     *                 holds it, so retiring one never silently changes a customer's capacity.
     *  - sort_order:  order in the selection
     */
    'tiers' => [
        'level_1' => ['name' => 'Level 1', 'multiplier' => 1.00, 'active' => true, 'sort_order' => 10],
        'level_2' => ['name' => 'Level 2', 'multiplier' => 1.50, 'active' => true, 'sort_order' => 20],
        'level_3' => ['name' => 'Level 3', 'multiplier' => 2.00, 'active' => true, 'sort_order' => 30],
    ],

    // The tier a customer gets until it chooses one (and when it holds a key no longer listed).
    // Level 1 is the starting point for an average customer.
    'default_tier' => 'level_1',

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
