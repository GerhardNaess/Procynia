<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI experience model: one aggregated snapshot per customer and billing period, for internal
 * analysis of how actual AI usage varies with users, modules and capacity levels.
 *
 * NOT A LEDGER. Every figure is derived from ai_usage_attempts (trusted rows) and can be rebuilt
 * from it by `ai:experience-refresh`. What the ledger cannot give afterwards — the customer's
 * shape in the period (active users, packages, tier, capacity) — is captured here while the period
 * runs and frozen once it ends, so an old period is never read through today's subscription.
 *
 * Read-only toward the commercial model: nothing reads these tables to size a capacity, price a
 * customer or change a weight. The analysis informs a person; config changes stay manual.
 *
 *  - ai_customer_experience_periods:          one row per (customer, billing period)
 *  - ai_customer_experience_period_features:  usage per attribution key (feature, or wiki.<module>
 *                                             for Wiki work handed over from a module)
 *  - ai_customer_experience_period_revisions: what changed when a final period was recomputed —
 *                                             a final period is never changed silently
 *
 * Room for revenue/margin later: a revenue figure per (customer, billing period) attaches to the
 * same key; nothing here assumes there is none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_customer_experience_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The billing period, [period_start, period_end) UTC, as CustomerBillingPeriodResolver gave it.
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('period_source', 24);
            $table->string('billing_interval', 8);
            $table->unsignedTinyInteger('period_months');

            // open: the period runs (or is inside the settle grace) and is refreshed freely.
            // final: closed; usage changes are recorded as revisions, the context never moves.
            $table->string('status', 8)->default('open');
            $table->timestamp('finalized_at')->nullable();
            // full: the whole period lies after the trusted-ledger boundary and has no legacy rows.
            // partial: part of the period predates trusted data — never compared as if complete.
            $table->string('coverage', 8);

            // Customer shape in the period, captured while it ran.
            $table->timestamp('context_captured_at');
            $table->unsignedInteger('active_users_count');
            $table->json('active_packages');
            $table->string('module_mix', 191);
            $table->string('tier_key', 32)->nullable();
            $table->decimal('tier_multiplier', 6, 3)->nullable();
            $table->unsignedInteger('base_units_per_month');
            $table->unsignedInteger('calculated_base_capacity');
            $table->unsignedInteger('calculated_total_capacity')->nullable();
            $table->unsignedInteger('override_units')->nullable();
            $table->unsignedInteger('included_units')->nullable();
            $table->string('capacity_source', 16);
            // The unit rate the units below were converted at.
            $table->decimal('nok_per_unit', 10, 4);

            // Usage, trusted rows only. Settled is actual; pending/unresolved stay separate.
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('successful_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->unsignedInteger('settled_calls')->default(0);
            $table->unsignedInteger('pending_calls')->default(0);
            $table->unsignedInteger('unresolved_calls')->default(0);
            $table->unsignedInteger('legacy_calls')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->decimal('settled_cost_nok', 14, 4)->default(0);
            $table->decimal('pending_reserved_cost_nok', 14, 4)->default(0);
            $table->decimal('unresolved_reserved_cost_nok', 14, 4)->default(0);
            $table->decimal('settled_units', 14, 2)->default(0);
            $table->decimal('reserved_units', 14, 2)->default(0);

            // Capacity behaviour: settled share of included, and settled + open reservations.
            $table->decimal('used_percent', 8, 2)->nullable();
            $table->decimal('committed_percent', 8, 2)->nullable();
            $table->unsignedInteger('verdict_allow')->default(0);
            $table->unsignedInteger('verdict_warn')->default(0);
            $table->unsignedInteger('verdict_exhausted')->default(0);
            $table->unsignedInteger('verdict_insufficient')->default(0);
            $table->unsignedInteger('verdict_unmetered')->default(0);
            $table->unsignedInteger('would_have_blocked')->default(0);

            $table->unsignedSmallInteger('snapshot_version');
            $table->unsignedSmallInteger('ledger_version');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['customer_id', 'period_start']);
            $table->index(['status', 'coverage', 'period_start']);
        });

        Schema::create('ai_customer_experience_period_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_customer_experience_period_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->unsignedInteger('calls')->default(0);
            $table->decimal('settled_cost_nok', 14, 4)->default(0);
            $table->decimal('settled_units', 14, 2)->default(0);
            $table->decimal('reserved_units', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['ai_customer_experience_period_id', 'feature_key'], 'ai_exp_period_feature_unique');
            $table->index('feature_key');
        });

        Schema::create('ai_customer_experience_period_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_customer_experience_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            // {field: {from, to}} for every usage figure that changed.
            $table->json('changes');
            $table->string('reason', 64);
            $table->timestamp('created_at');

            $table->unique(['ai_customer_experience_period_id', 'revision'], 'ai_exp_period_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_customer_experience_period_revisions');
        Schema::dropIfExists('ai_customer_experience_period_features');
        Schema::dropIfExists('ai_customer_experience_periods');
    }
};
