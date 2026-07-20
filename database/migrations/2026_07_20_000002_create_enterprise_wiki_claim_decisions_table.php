<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_claim_decisions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('enterprise_wiki_claim_id');
            $table->foreign('enterprise_wiki_claim_id', 'ewcd_claim_id_foreign')
                ->references('id')->on('enterprise_wiki_claims')->cascadeOnDelete();

            $table->foreignId('decided_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // 'approval_status' (approve/reject/unapprove) or 'blocking_override' (keep/remove
            // blocking) — the two independent decision axes a user can record on a claim.
            $table->string('decision_type');

            // Whatever changed, expressed generically rather than one column per possible field —
            // e.g. {"approval_status": "pending"} -> {"approval_status": "approved"}, or
            // {"blocking_override": null} -> {"blocking_override": false}. Keeps this table usable
            // for both decision types without a schema change if a third one is added later.
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();

            $table->text('comment')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['enterprise_wiki_claim_id', 'created_at'], 'ewcd_claim_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_wiki_claim_decisions');
    }
};
