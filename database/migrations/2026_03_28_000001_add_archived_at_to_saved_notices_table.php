<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('cpv_code');
        });
    }

    public function down(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
