<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the legacy content column from operational runbooks.
     */
    public function up(): void
    {
        Schema::table('operational_runbooks', function (Blueprint $table): void {
            $table->dropColumn('content');
        });
    }

    /**
     * Restore the legacy content column if the migration is rolled back.
     */
    public function down(): void
    {
        Schema::table('operational_runbooks', function (Blueprint $table): void {
            $table->longText('content')->nullable()->after('summary');
        });
    }
};
