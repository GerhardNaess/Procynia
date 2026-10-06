<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A revisjon's scope, as structure: the requirements and the Kvalitet processes it looks at.
 *
 * Fixed rows, and nothing else. Choosing a kravkilde in the form is a shortcut that writes one row
 * per requirement at that moment; there is no source link and no rule, so a requirement added to
 * the source later never enters an audit by itself. Nothing about the requirement or the process is
 * copied here either — reference, title, text and status are read live.
 *
 * Tenant boundaries are held by the database: every key is (id, customer_id), so a link between two
 * customers cannot be written by any path.
 *
 * REQUIREMENT SIDE: NO ACTION, not cascade. A requirement in an audit's scope is part of what was
 * audited, so it cannot quietly disappear from the audit; ComplianceRequirement::isDeletable() says
 * so first. (NO ACTION rather than RESTRICT so that a customer going still takes everything in one
 * statement.) A retired requirement stays in scope and is shown as utgått.
 *
 * PROCESS SIDE: cascade, as for the requirement links. Kvalitet never reads these tables, and a
 * refusal there would be a statement about audits the person in Kvalitet may not see.
 *
 * AUDIT SIDE: cascade. Only a planned audit that has never moved can be deleted at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_audit_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->unsignedBigInteger('requirement_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['audit_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_audits')->cascadeOnDelete();
            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements');
            $table->unique(['audit_id', 'requirement_id']);
            $table->index('requirement_id');
            $table->index('customer_id');
        });

        Schema::create('compliance_audit_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id');
            $table->unsignedBigInteger('quality_process_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['audit_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_audits')->cascadeOnDelete();
            $table->foreign(['quality_process_id', 'customer_id'])->references(['id', 'customer_id'])->on('quality_items')->cascadeOnDelete();
            $table->unique(['audit_id', 'quality_process_id']);
            $table->index('quality_process_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_audit_processes');
        Schema::dropIfExists('compliance_audit_requirements');
    }
};
