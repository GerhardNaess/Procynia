<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shared AI capacity gate said about the call when it was admitted: allow, warn,
 * exhausted, insufficient or unmetered (no capacity defined). Null when the gate was off or the
 * call had no customer.
 *
 * An annotation for calibration, not a price: in observe mode it records how often the current
 * capacity *would* have stopped a customer, while the call ran anyway. Cost stays in cost_nok and
 * reserved_cost_nok exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->string('capacity_verdict', 16)->nullable()->after('reserved_cost_nok');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_attempts', function (Blueprint $table): void {
            $table->dropColumn('capacity_verdict');
        });
    }
};
