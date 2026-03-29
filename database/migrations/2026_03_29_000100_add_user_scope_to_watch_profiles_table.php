<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('user_id');
        });

        DB::table('watch_profiles')
            ->whereNull('department_id')
            ->orderBy('id')
            ->get(['id', 'customer_id'])
            ->each(function (object $watchProfile): void {
                $ownerUserId = DB::table('users')
                    ->where('customer_id', $watchProfile->customer_id)
                    ->orderBy('id')
                    ->value('id');

                if ($ownerUserId === null) {
                    return;
                }

                DB::table('watch_profiles')
                    ->where('id', $watchProfile->id)
                    ->update([
                        'user_id' => $ownerUserId,
                        'updated_at' => now(),
                    ]);
            });

        DB::statement('DROP INDEX IF EXISTS watch_profiles_customer_id_name_unique');
        DB::statement('DROP INDEX IF EXISTS watch_profiles_user_name_unique');
        DB::statement('DROP INDEX IF EXISTS watch_profiles_department_name_unique');
        DB::statement(
            'CREATE UNIQUE INDEX watch_profiles_user_name_unique '.
            'ON watch_profiles (customer_id, user_id, LOWER(name)) '.
            'WHERE user_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX watch_profiles_department_name_unique '.
            'ON watch_profiles (customer_id, department_id, LOWER(name)) '.
            'WHERE department_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS watch_profiles_user_name_unique');
        DB::statement('DROP INDEX IF EXISTS watch_profiles_department_name_unique');
        DB::statement('CREATE UNIQUE INDEX watch_profiles_customer_id_name_unique ON watch_profiles (customer_id, LOWER(name))');

        Schema::table('watch_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
