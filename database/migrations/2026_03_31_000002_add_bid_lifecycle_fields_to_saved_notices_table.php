<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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

        $hasBidStatus = Schema::hasColumn('saved_notices', 'bid_status');
        $hasBidOwnerUserId = Schema::hasColumn('saved_notices', 'bid_owner_user_id');
        $hasBidQualifiedAt = Schema::hasColumn('saved_notices', 'bid_qualified_at');
        $hasBidSubmittedAt = Schema::hasColumn('saved_notices', 'bid_submitted_at');
        $hasBidClosedAt = Schema::hasColumn('saved_notices', 'bid_closed_at');

        if (! $hasBidStatus || ! $hasBidOwnerUserId || ! $hasBidQualifiedAt || ! $hasBidSubmittedAt || ! $hasBidClosedAt) {
            Schema::table('saved_notices', function (Blueprint $table) use (
                $hasBidStatus,
                $hasBidOwnerUserId,
                $hasBidQualifiedAt,
                $hasBidSubmittedAt,
                $hasBidClosedAt,
            ): void {
                if (! $hasBidStatus) {
                    $table->string('bid_status')->default('discovered')->index();
                }

                if (! $hasBidOwnerUserId) {
                    $table->foreignId('bid_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! $hasBidQualifiedAt) {
                    $table->timestamp('bid_qualified_at')->nullable();
                }

                if (! $hasBidSubmittedAt) {
                    $table->timestamp('bid_submitted_at')->nullable();
                }

                if (! $hasBidClosedAt) {
                    $table->timestamp('bid_closed_at')->nullable();
                }
            });
        }

        DB::table('saved_notices')
            ->where(function (Builder $query): void {
                $query->whereNull('bid_status')
                    ->orWhere('bid_status', '');
            })
            ->update([
                'bid_status' => 'discovered',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            return;
        }

        $hasBidStatus = Schema::hasColumn('saved_notices', 'bid_status');
        $hasBidOwnerUserId = Schema::hasColumn('saved_notices', 'bid_owner_user_id');
        $hasBidQualifiedAt = Schema::hasColumn('saved_notices', 'bid_qualified_at');
        $hasBidSubmittedAt = Schema::hasColumn('saved_notices', 'bid_submitted_at');
        $hasBidClosedAt = Schema::hasColumn('saved_notices', 'bid_closed_at');

        if (! $hasBidStatus && ! $hasBidOwnerUserId && ! $hasBidQualifiedAt && ! $hasBidSubmittedAt && ! $hasBidClosedAt) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table) use (
            $hasBidStatus,
            $hasBidOwnerUserId,
            $hasBidQualifiedAt,
            $hasBidSubmittedAt,
            $hasBidClosedAt,
        ): void {
            if ($hasBidOwnerUserId) {
                try {
                    $table->dropForeign(['bid_owner_user_id']);
                } catch (\Throwable $exception) {
                }
            }

            if ($hasBidStatus) {
                try {
                    $table->dropIndex(['bid_status']);
                } catch (\Throwable $exception) {
                }
            }

            $columns = [];

            if ($hasBidStatus) {
                $columns[] = 'bid_status';
            }

            if ($hasBidOwnerUserId) {
                $columns[] = 'bid_owner_user_id';
            }

            if ($hasBidQualifiedAt) {
                $columns[] = 'bid_qualified_at';
            }

            if ($hasBidSubmittedAt) {
                $columns[] = 'bid_submitted_at';
            }

            if ($hasBidClosedAt) {
                $columns[] = 'bid_closed_at';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
