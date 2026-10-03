<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-defined roles.
 *
 * WHY A SECOND ROLE MODEL BESIDE bid_role.
 *
 * `users.bid_role` names what a person does in an anbud — Bid Manager, Kommersiell eier,
 * Contributor — and Procynia decides that vocabulary because the tilbudsprosess is the product.
 * Outside anbud the vocabulary is the customer's own: one virksomhet calls the person who owns the
 * kvalitetssystem «Kvalitetsleder», the next «Kvalitetssjef», the next «Kvalitetsdirektør», and a
 * fourth splits the job between a «Wiki-ansvarlig» and a «Prosesseier». A fixed enum cannot hold
 * that, and widening the existing one would have made every anbud authorization check depend on
 * names Procynia does not control.
 *
 * So this is additive and deliberately separate. Nothing in anbud reads these tables. A user keeps
 * exactly the bid_role they have, and may in addition hold any number of customer roles.
 *
 * WHY THE PERMISSION CATALOGUE IS NOT A TABLE.
 *
 * `customer_role_permissions.permission_key` references App\Support\CustomerPermissionCatalog, which
 * lives in code. A permission key is a thing the code branches on — a row nobody can branch on is
 * not a permission, it is a label. Keeping the catalogue in code means a key cannot exist without
 * an enforcement point, and a deployed enforcement point cannot be missing its key. Unknown keys
 * are rejected on write and ignored on read, so a key retired in a later release degrades to "that
 * role grants nothing extra" rather than to an error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The customer's own word for the job. Unique per customer: two roles with the same
            // name are indistinguishable in every list and assignment dialogue in the product.
            $table->string('name');
            $table->text('description')->nullable();

            // Deactivation rather than deletion is the ordinary way to retire a role: an inactive
            // role keeps its assignments and its permission set, and simply stops granting
            // anything. That makes "turn this off for a while" reversible, which deleting is not.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'name']);
            $table->index(['customer_id', 'is_active']);
        });

        Schema::create('customer_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key');
            $table->timestamps();

            $table->unique(['customer_role_id', 'permission_key']);
        });

        Schema::create('customer_user_roles', function (Blueprint $table): void {
            $table->id();

            // customer_id is denormalised onto the assignment on purpose. It is the column a tenant
            // guard can read without joining, and it makes a cross-tenant assignment visible as bad
            // data rather than only as a wrong answer from the resolver.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_role_id')->constrained('customer_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'customer_role_id']);
            $table->index(['customer_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user_roles');
        Schema::dropIfExists('customer_role_permissions');
        Schema::dropIfExists('customer_roles');
    }
};
