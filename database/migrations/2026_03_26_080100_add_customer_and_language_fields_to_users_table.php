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
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete()->index();
            $table->string('nationality_code', 8)->nullable()->after('department_id');
            $table->string('preferred_language_code', 8)->nullable()->after('nationality_code');
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

        DB::table('users')
            ->whereNull('customer_id')
            ->where('email', 'not like', '%@example.com')
            ->update([
                'customer_id' => $defaultCustomerId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['nationality_code', 'preferred_language_code']);
        });
    }
};
