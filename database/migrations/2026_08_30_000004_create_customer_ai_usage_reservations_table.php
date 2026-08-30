<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_usage_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('operation', 100);
            $table->string('correlation_key', 128)->nullable();
            $table->string('status', 20);
            $table->timestamp('reserved_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'period_start', 'period_end', 'status'], 'customer_ai_reservation_quota_index');
            $table->index(['customer_id', 'saved_notice_id', 'period_start', 'period_end'], 'customer_ai_reservation_case_index');
        });
    }

    public function down(): void { Schema::dropIfExists('customer_ai_usage_reservations'); }
};
