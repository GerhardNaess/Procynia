<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable record of which AI-quota notification a customer has already been sent for a period.
 *
 * Deliberately a table rather than a cache entry: a deploy, a restart or a cache flush must not
 * be able to make every System Owner receive the 80% e-mail a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_notification_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('threshold_percent')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('notified_at');
            $table->timestamps();

            // The dedupe contract in one constraint: one notification per customer, event and
            // period. A new calendar month is a new period, so the cycle starts over by itself.
            $table->unique(['customer_id', 'event_key', 'period_start'], 'customer_ai_notification_state_unique');
            $table->index(['customer_id', 'period_start'], 'customer_ai_notification_state_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ai_notification_states');
    }
};
