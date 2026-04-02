<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            return;
        }

        $hasProcurementType = Schema::hasColumn('saved_notices', 'procurement_type');
        $hasFollowUpMode = Schema::hasColumn('saved_notices', 'follow_up_mode');
        $hasFollowUpOffsetMonths = Schema::hasColumn('saved_notices', 'follow_up_offset_months');

        if (! $hasProcurementType || ! $hasFollowUpMode || ! $hasFollowUpOffsetMonths) {
            Schema::table('saved_notices', function (Blueprint $table) use (
                $hasProcurementType,
                $hasFollowUpMode,
                $hasFollowUpOffsetMonths,
            ): void {
                if (! $hasProcurementType) {
                    $table->string('procurement_type')->nullable();
                }

                if (! $hasFollowUpMode) {
                    $table->string('follow_up_mode')->nullable();
                }

                if (! $hasFollowUpOffsetMonths) {
                    $table->unsignedInteger('follow_up_offset_months')->nullable();
                }
            });
        }

        DB::table('saved_notices')
            ->whereNotNull('contract_period_months')
            ->whereNull('procurement_type')
            ->whereNull('follow_up_mode')
            ->update([
                'procurement_type' => 'recurring',
                'follow_up_mode' => 'contract_end',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            return;
        }

        $hasProcurementType = Schema::hasColumn('saved_notices', 'procurement_type');
        $hasFollowUpMode = Schema::hasColumn('saved_notices', 'follow_up_mode');
        $hasFollowUpOffsetMonths = Schema::hasColumn('saved_notices', 'follow_up_offset_months');

        if (! $hasProcurementType && ! $hasFollowUpMode && ! $hasFollowUpOffsetMonths) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table) use (
            $hasProcurementType,
            $hasFollowUpMode,
            $hasFollowUpOffsetMonths,
        ): void {
            $columns = [];

            if ($hasProcurementType) {
                $columns[] = 'procurement_type';
            }

            if ($hasFollowUpMode) {
                $columns[] = 'follow_up_mode';
            }

            if ($hasFollowUpOffsetMonths) {
                $columns[] = 'follow_up_offset_months';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
