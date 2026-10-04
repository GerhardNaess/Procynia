<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko → håndteres av → Kontroll.
 *
 * A link, and nothing else. The control is the existing `control` QualityItem in Kvalitet; nothing
 * about it — title, criterion, evidence — is copied here, so there is still one place a control is
 * described. Which row may be linked (same customer, type control) is checked by
 * RiskControlService before a row is written.
 *
 * THE LINK BELONGS TO THE RISK.
 *
 * It is created, read and removed only from the risk's side, with the risk's access. Kvalitet
 * never reads this table: a control's page, the Kontroller register and every other Quality view
 * stay exactly as they were, so a link can never tell someone in Kvalitet that a risk exists.
 *
 * That is also why deleting the control cascades rather than restricts. A restrict would make
 * Kvalitet refuse a delete "because something links here" — a statement about hidden risks.
 * Removing a link (unlink) never touches the control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['risk_id', 'quality_item_id']);
            $table->index('quality_item_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_controls');
    }
};
