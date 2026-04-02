<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'bid_role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('bid_role', 32)->default('contributor')->after('role');
            });
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->whereNull('bid_role')
                    ->orWhere('bid_role', '');
            })
            ->update([
                'bid_role' => 'contributor',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'bid_role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('bid_role');
        });
    }
};
