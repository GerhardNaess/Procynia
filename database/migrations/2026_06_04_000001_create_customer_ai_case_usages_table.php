<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_case_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->timestamp('activated_at');
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source_operation_key', 64);
            $table->unsignedBigInteger('source_ai_usage_event_id')->nullable();
            $table->unsignedBigInteger('source_ai_token_event_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['customer_id', 'saved_notice_id', 'period_start', 'period_end'],
                'customer_ai_case_usages_customer_notice_period_unique',
            );
            $table->index(['customer_id', 'period_start', 'period_end'], 'customer_ai_case_usages_customer_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ai_case_usages');
    }
};
