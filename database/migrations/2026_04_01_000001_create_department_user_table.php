<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department_user')) {
            Schema::create('department_user', function (Blueprint $table): void {
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['department_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'department_id')) {
            return;
        }

        $memberships = DB::table('users')
            ->select('id', 'department_id')
            ->whereNotNull('department_id')
            ->get()
            ->map(fn (object $row): array => [
                'department_id' => (int) $row->department_id,
                'user_id' => (int) $row->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($memberships === []) {
            return;
        }

        DB::table('department_user')->insertOrIgnore($memberships);
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
    }
};
