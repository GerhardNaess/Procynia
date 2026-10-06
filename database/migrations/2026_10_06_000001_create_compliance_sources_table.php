<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etterlevelse og revisjon — kravkilder: where a requirement comes from. A standard, a law or
 * regulation, a contract, an internal requirement or something else, with an optional version.
 *
 * Customer-wide in v1: no fagområde. A source has no lifecycle of its own; it is deleted only while
 * no requirement uses it (the requirements' foreign key refuses it otherwise).
 *
 * (id, customer_id) is unique so that requirements reference the pair, and the database refuses a
 * requirement whose source belongs to another customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('version', 100)->nullable();
            $table->string('kind');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE compliance_sources ADD CONSTRAINT compliance_sources_kind_check CHECK (kind IN ('standard', 'law', 'contract', 'internal', 'other'))");
            DB::statement('ALTER TABLE compliance_sources ADD CONSTRAINT compliance_sources_name_check CHECK (length(btrim(name)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_sources');
    }
};
