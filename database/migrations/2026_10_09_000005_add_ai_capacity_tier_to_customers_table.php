<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's selected AI capacity tier: a key in config/ai_customer_capacity.php `tiers`.
 *
 * AI capacity is its own commercial dimension, separate from Basis and the options. Null means no
 * tier is selected — the capacity is unconfigured (observed, never refused) unless
 * `included_ai_units` overrides it. A string key rather than a foreign key, like
 * `subscription_plan`: the tiers are still technical placeholders kept in config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('ai_capacity_tier', 64)->nullable()->after('included_ai_units');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('ai_capacity_tier');
        });
    }
};
