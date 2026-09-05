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
        if (! Schema::hasTable('saved_notices') || Schema::hasTable('saved_notice_no_go_decisions')) {
            return;
        }

        Schema::create('saved_notice_no_go_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closure_reason')->nullable();
            $table->text('closure_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('reopened_from_archived_at')->nullable();
            $table->string('reopened_from_history_type')->nullable();
            $table->timestamps();

            $table->index(['saved_notice_id', 'reopened_at']);
        });

        DB::table('saved_notices')
            ->where('bid_status', SavedNotice::BID_STATUS_NO_GO)
            ->orderBy('id')
            ->eachById(function (object $notice): void {
                DB::table('saved_notice_no_go_decisions')->insert([
                    'saved_notice_id' => $notice->id,
                    'customer_id' => $notice->customer_id,
                    'closed_by_user_id' => null,
                    'closure_reason' => $notice->bid_closure_reason,
                    'closure_note' => $notice->bid_closure_note,
                    'closed_at' => $notice->bid_closed_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_notice_no_go_decisions');
    }
};
