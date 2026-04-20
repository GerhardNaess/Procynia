<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->text('ai_instructions')->nullable()->after('next_process_date_at');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn('ai_instructions');
        });
    }
};
