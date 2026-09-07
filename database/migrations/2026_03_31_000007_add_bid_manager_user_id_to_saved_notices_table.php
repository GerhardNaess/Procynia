<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(basename(__FILE__, '.php'), 'saved_notices');

        // Genuine idempotence, unlike the table check: the column already being there means this
        // migration has nothing left to do, not that something is wrong.
        if (Schema::hasColumn('saved_notices', 'bid_manager_user_id')) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->foreignId('bid_manager_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_notices') || ! Schema::hasColumn('saved_notices', 'bid_manager_user_id')) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bid_manager_user_id');
        });
    }
};
