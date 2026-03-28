<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('description');
            $table->index('is_active');
        });

        DB::statement('CREATE UNIQUE INDEX departments_customer_id_name_unique ON departments (customer_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS departments_customer_id_name_unique');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['description', 'is_active']);
        });
    }
};
