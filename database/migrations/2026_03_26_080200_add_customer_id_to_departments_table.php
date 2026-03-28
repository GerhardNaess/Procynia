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
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->cascadeOnDelete()->index();
        });

        $defaultCustomerId = DB::table('customers')
            ->where('slug', 'default-customer')
            ->value('id');

        if ($defaultCustomerId === null) {
            $defaultCustomerId = DB::table('customers')->insertGetId([
                'name' => 'Default Customer',
                'slug' => 'default-customer',
                'nationality_code' => 'NO',
                'default_language_code' => 'no',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('departments')
            ->whereNull('customer_id')
            ->update([
                'customer_id' => $defaultCustomerId,
                'updated_at' => now(),
            ]);

        DB::statement('ALTER TABLE departments ALTER COLUMN customer_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE departments ALTER COLUMN customer_id DROP NOT NULL');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
