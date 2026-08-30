<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_quota_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('extra_credits')->default(0);
            $table->timestamps();
            $table->unique(['customer_id', 'period_start', 'period_end'], 'customer_ai_quota_period_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('customer_ai_quota_periods'); }
};
