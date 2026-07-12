<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->timestamp('verification_claimed_at')->nullable()->after('verified_at');
            $table->string('verification_claim_token')->nullable()->after('verification_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropColumn(['verification_claimed_at', 'verification_claim_token']);
        });
    }
};
