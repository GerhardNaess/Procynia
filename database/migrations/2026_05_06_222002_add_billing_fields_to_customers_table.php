<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('billing_interval')->default('monthly')->after('subscription_plan'); // monthly|yearly
            $table->unsignedInteger('included_users')->default(1)->after('billing_interval');
            $table->unsignedInteger('included_ai_credits')->default(0)->after('included_users');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['billing_interval', 'included_users', 'included_ai_credits']);
        });
    }
};
