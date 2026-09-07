<?php

use App\Support\MigrationSchemaPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MigrationSchemaPrecondition::requireTables(basename(__FILE__, '.php'), 'department_user', 'users');

        $now = now();

        DB::table('users')
            ->select(['id as user_id', 'department_id'])
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($now): void {
                foreach ($rows as $row) {
                    $exists = DB::table('department_user')
                        ->where('user_id', $row->user_id)
                        ->where('department_id', $row->department_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('department_user')->insert([
                        'user_id' => $row->user_id,
                        'department_id' => $row->department_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Keep backfilled memberships in place to avoid destructive rollback of real user memberships.
    }
};
