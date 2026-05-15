<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purpose: Create the safe AI usage event ledger used by the technical guard.
     * Inputs: None.
     * Returns: None.
     * Side effects: Creates the ai_usage_events table and its indexes.
     */
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_key');
            $table->string('status', 20);
            $table->string('limit_type', 20)->nullable();
            $table->unsignedInteger('operation_count')->default(1);
            $table->timestamps();

            $table->index(['customer_id', 'user_id', 'operation_key', 'status'], 'ai_usage_events_scope_index');
            $table->index(['operation_key', 'status'], 'ai_usage_events_operation_status_index');
            $table->index('created_at', 'ai_usage_events_created_at_index');
        });
    }

    /**
     * Purpose: Remove the safe AI usage event ledger.
     * Inputs: None.
     * Returns: None.
     * Side effects: Drops the ai_usage_events table.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
