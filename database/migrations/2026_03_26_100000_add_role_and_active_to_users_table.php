<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('preferred_language_code');

            $table->index('role');
            $table->index('is_active');
        });

        DB::table('users')
            ->where('email', 'gerhardnaess@gmail.com')
            ->update([
                'role' => 'super_admin',
                'customer_id' => null,
                'department_id' => null,
                'nationality_code' => DB::raw("COALESCE(nationality_code, 'NO')"),
                'preferred_language_code' => DB::raw("COALESCE(preferred_language_code, 'no')"),
                'is_active' => true,
                'updated_at' => now(),
            ]);

        DB::table('users')
            ->whereNull('role')
            ->whereNull('customer_id')
            ->update([
                'role' => 'super_admin',
                'is_active' => true,
                'updated_at' => now(),
            ]);

        DB::table('users')
            ->whereNull('role')
            ->whereNotNull('customer_id')
            ->update([
                'role' => 'user',
                'is_active' => true,
                'updated_at' => now(),
            ]);

        DB::statement('ALTER TABLE users ALTER COLUMN role SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
