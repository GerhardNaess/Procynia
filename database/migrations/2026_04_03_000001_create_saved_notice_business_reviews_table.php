<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_notice_business_reviews')) {
            return;
        }

        Schema::create('saved_notice_business_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained('saved_notices')->cascadeOnDelete();
            $table->timestamp('business_review_at');
            $table->timestamps();

            $table->index(['saved_notice_id', 'business_review_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_business_reviews');
    }
};
