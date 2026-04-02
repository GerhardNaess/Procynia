<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            return;
        }

        $hasBidClosureReason = Schema::hasColumn('saved_notices', 'bid_closure_reason');
        $hasBidClosureNote = Schema::hasColumn('saved_notices', 'bid_closure_note');

        if (! $hasBidClosureReason || ! $hasBidClosureNote) {
            Schema::table('saved_notices', function (Blueprint $table) use ($hasBidClosureReason, $hasBidClosureNote): void {
                if (! $hasBidClosureReason) {
                    $table->string('bid_closure_reason')->nullable();
                }

                if (! $hasBidClosureNote) {
                    $table->text('bid_closure_note')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_notices')) {
            return;
        }

        $hasBidClosureReason = Schema::hasColumn('saved_notices', 'bid_closure_reason');
        $hasBidClosureNote = Schema::hasColumn('saved_notices', 'bid_closure_note');

        if (! $hasBidClosureReason && ! $hasBidClosureNote) {
            return;
        }

        Schema::table('saved_notices', function (Blueprint $table) use ($hasBidClosureReason, $hasBidClosureNote): void {
            $columns = [];

            if ($hasBidClosureReason) {
                $columns[] = 'bid_closure_reason';
            }

            if ($hasBidClosureNote) {
                $columns[] = 'bid_closure_note';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
