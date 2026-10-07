<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Krav → oppfylles gjennom → Kvalitet-prosess / -kontroll.
 *
 * Links, and nothing else. The process and the control are the existing QualityItems in Kvalitet;
 * nothing about them — title, criterion, method, frequency, evidence — is copied here, so there is
 * still one place they are described. Compliance owns the requirement and its assessments; Kvalitet
 * owns processes, controls and evidence.
 *
 * Tenant boundaries are held by the database: (requirement_id, customer_id) and
 * (quality_process_id | control_item_id, customer_id) reference each side's own pair, so a link
 * between two customers cannot be written by any path. That the item is a `process` or a `control`,
 * and that a control is not retired, is a rule the keys cannot express — it is checked by
 * ComplianceQualityContextService before a row is written.
 *
 * THE LINK BELONGS TO THE REQUIREMENT. Kvalitet never reads these tables: no requirements on a
 * process or a control, in counts, search or Oversikt. Deleting the process or the control
 * therefore cascades rather than restricts — a refusal would be a statement about requirements the
 * person in Kvalitet may not see. Deleting the requirement takes its links along. Retiring a control
 * in Kvalitet leaves the link standing; the requirement page shows it as utgått.
 *
 * No activity link: where a control sits in a process flow already tells which activity it belongs
 * to (quality_activity_controls), so a third table would only be a second copy of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirement_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('quality_process_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['requirement_id', 'quality_process_id']);
            $table->index('quality_process_id');
            $table->index('customer_id');
        });

        Schema::create('compliance_requirement_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('control_item_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->foreign(['control_item_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['requirement_id', 'control_item_id']);
            $table->index('control_item_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirement_controls');
        Schema::dropIfExists('compliance_requirement_processes');
    }
};
