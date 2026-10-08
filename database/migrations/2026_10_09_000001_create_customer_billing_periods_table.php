<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local record of a customer's actual billing periods, so "which billing period does
 * timestamp X belong to for customer Y?" is answered from the database, never from Stripe.
 *
 * One row per provider period, kept as history: a renewal adds a row, it does not overwrite the
 * previous one, so usage from an earlier period can always be grouped by the period it ran in.
 * Rows never overlap — a mid-period reset (plan or interval change) truncates the earlier row at
 * the new period's start. Times are UTC.
 *
 * `customers.billing_anchor_at` is the explicit contract for customers billed outside Stripe
 * (enterprise/manual): their periods are derived from it, never from an arbitrary calendar month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_billing_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subscription_id');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('interval', 16)->nullable();
            $table->unsignedSmallInteger('interval_count')->nullable();
            $table->string('subscription_status', 32);
            $table->boolean('cancel_at_period_end')->default(false);
            // When the provider produced the state this row reflects: an older webhook delivered
            // late must not overwrite a newer one.
            $table->timestamp('provider_event_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['customer_id', 'period_start']);
            $table->index(['customer_id', 'period_end']);
            $table->index('provider_subscription_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('billing_anchor_at')->nullable()->after('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('billing_anchor_at');
        });

        Schema::dropIfExists('customer_billing_periods');
    }
};
