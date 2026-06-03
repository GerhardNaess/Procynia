<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_token_events', function (Blueprint $table): void {
            $table->string('provider', 64)->nullable()->after('model');
            $table->string('deployment_name', 128)->nullable()->after('provider');
            $table->string('provider_region', 64)->nullable()->after('deployment_name');

            $table->index(['provider', 'model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_token_events', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'model', 'created_at']);
            $table->dropColumn(['provider', 'deployment_name', 'provider_region']);
        });
    }
};
