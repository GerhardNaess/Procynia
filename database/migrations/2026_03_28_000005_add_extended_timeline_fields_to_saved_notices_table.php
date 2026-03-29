<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->timestamp('questions_rfi_deadline_at')->nullable();
            $table->timestamp('questions_rfp_deadline_at')->nullable();
            $table->timestamp('award_date_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn([
                'questions_rfi_deadline_at',
                'questions_rfp_deadline_at',
                'award_date_at',
            ]);
        });
    }
};
