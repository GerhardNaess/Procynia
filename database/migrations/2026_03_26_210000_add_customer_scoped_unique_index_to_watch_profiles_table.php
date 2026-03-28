<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX watch_profiles_customer_id_name_unique ON watch_profiles (customer_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS watch_profiles_customer_id_name_unique');
    }
};
