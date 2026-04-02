<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'bid_manager_scope')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('bid_manager_scope')->nullable()->after('bid_role');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'bid_manager_scope')) {
            DB::table('users')
                ->where('bid_role', User::BID_ROLE_BID_MANAGER)
                ->whereNull('bid_manager_scope')
                ->update([
                    'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
                ]);
        }

        if (! Schema::hasTable('bid_manager_departments')) {
            Schema::create('bid_manager_departments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'department_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bid_manager_departments')) {
            Schema::drop('bid_manager_departments');
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'bid_manager_scope')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('bid_manager_scope');
            });
        }
    }
};
