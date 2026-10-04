<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — tiltak: a concrete thing to do about one risk, with a person responsible and a deadline.
 *
 * Deliberately not project management: no priority, no subtasks, no board. An action is open or
 * completed, and completed_at says when. «Forfalt» is not stored — it follows from status and
 * due_at on the day the page is read (RiskTreatmentAction::isOverdue()).
 *
 * ACCESS IS THE RISK'S.
 *
 * An action has no fagområde of its own and is reached only through its risk, so only by someone
 * RiskAccessService lets see that risk. Deleting the risk (risk.delete) takes its actions along.
 *
 * The responsible person is required by the form, but the column is nullable with nullOnDelete:
 * refusing to delete a user "because a tiltak points at them" would be a statement about risks the
 * administrator may not be able to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_treatment_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at');
            $table->string('status');
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['risk_id', 'status']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_treatment_actions');
    }
};
