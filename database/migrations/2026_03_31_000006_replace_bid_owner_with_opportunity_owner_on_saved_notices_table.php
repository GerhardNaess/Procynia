<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasOpportunityOwnerUserId = Schema::hasColumn('saved_notices', 'opportunity_owner_user_id');
        $hasBidOwnerUserId = Schema::hasColumn('saved_notices', 'bid_owner_user_id');

        if ($hasBidOwnerUserId && ! $hasOpportunityOwnerUserId) {
            DB::statement('ALTER TABLE saved_notices RENAME COLUMN bid_owner_user_id TO opportunity_owner_user_id');

            return;
        }

        if ($hasOpportunityOwnerUserId) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->foreignId('opportunity_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $hasOpportunityOwnerUserId = Schema::hasColumn('saved_notices', 'opportunity_owner_user_id');
        $hasBidOwnerUserId = Schema::hasColumn('saved_notices', 'bid_owner_user_id');

        if ($hasOpportunityOwnerUserId && ! $hasBidOwnerUserId) {
            DB::statement('ALTER TABLE saved_notices RENAME COLUMN opportunity_owner_user_id TO bid_owner_user_id');
        }
    }
};
