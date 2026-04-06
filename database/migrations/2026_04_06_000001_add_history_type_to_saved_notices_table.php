<?php

use App\Models\SavedNotice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('saved_notices', 'history_type')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->string('history_type')->nullable()->index();
            });
        }

        $unsupportedValues = [];

        DB::table('saved_notices')
            ->whereNotNull('archived_at')
            ->select(['id', 'bid_status', 'history_type'])
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use (&$unsupportedValues): void {
                $currentHistoryType = trim((string) ($row->history_type ?? ''));

                if ($currentHistoryType !== '' && in_array($currentHistoryType, SavedNotice::HISTORY_TYPES, true)) {
                    return;
                }

                $normalizedHistoryType = SavedNotice::historyTypeFromLegacyBidStatus($row->bid_status);

                if ($normalizedHistoryType === null) {
                    $unsupportedValues[] = sprintf(
                        'saved_notices.id=%d bid_status=%s',
                        (int) $row->id,
                        (string) ($row->bid_status ?? 'NULL'),
                    );

                    return;
                }

                DB::table('saved_notices')
                    ->where('id', $row->id)
                    ->update([
                        'history_type' => $normalizedHistoryType,
                    ]);
            });

        if ($unsupportedValues !== []) {
            throw new RuntimeException(
                'Unable to backfill history_type for legacy archived notices: '.implode(', ', $unsupportedValues),
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('saved_notices', 'history_type')) {
            Schema::table('saved_notices', function (Blueprint $table): void {
                $table->dropColumn('history_type');
            });
        }
    }
};
