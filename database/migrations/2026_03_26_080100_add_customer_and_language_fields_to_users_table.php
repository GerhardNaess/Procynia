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
        // up() chains ->index() onto the foreign key definition, where `index` is the constraint's
        // name — so the key was created as "1", not users_customer_id_foreign. Both names are
        // dropped if present, so this rolls back whichever one a database holds.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS "1"');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_customer_id_foreign');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('customer_id');
            $table->dropColumn(['nationality_code', 'preferred_language_code']);
        });
    }
};
