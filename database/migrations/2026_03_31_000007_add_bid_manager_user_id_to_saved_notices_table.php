<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_notices') || Schema::hasColumn('saved_notices', 'bid_manager_user_id')) {
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
