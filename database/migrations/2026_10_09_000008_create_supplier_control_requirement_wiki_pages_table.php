<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Veiledning fra Enterprise Wiki» on a control requirement (docs/supplier-assurance-v2-plan.md §28):
 * which existing Wiki pages explain how the requirement is controlled. A reference, never a copy — no
 * title, text or status of the page is stored here; the Wiki owns the content and is read live, with
 * the person's own Wiki access.
 *
 * Both sides by (id, customer_id), so a requirement can never point at another customer's page. The
 * pages table gets unique(id, customer_id) for that, as enterprise_wiki_documents did for
 * enterprise_wiki_document_origins (2026_10_08_000021) — an index, nothing else about the Wiki
 * changes. Deleting the page or the requirement deletes the link: the Wiki is never blocked by a
 * supplier reference, and the link is not history (controls keep their own snapshot).
 *
 * One row per (requirement, page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id'], 'enterprise_wiki_pages_id_customer_unique');
        });

        Schema::create('supplier_control_requirement_wiki_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('enterprise_wiki_page_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['requirement_id', 'customer_id'], 'supplier_requirement_wiki_pages_requirement_fk')
                ->references(['id', 'customer_id'])->on('supplier_control_requirements')->cascadeOnDelete();
            $table->foreign(['enterprise_wiki_page_id', 'customer_id'], 'supplier_requirement_wiki_pages_page_fk')
                ->references(['id', 'customer_id'])->on('enterprise_wiki_pages')->cascadeOnDelete();
            $table->unique(['requirement_id', 'enterprise_wiki_page_id'], 'supplier_requirement_wiki_pages_unique');
            $table->index(['customer_id', 'enterprise_wiki_page_id'], 'supplier_requirement_wiki_pages_page_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_control_requirement_wiki_pages');

        Schema::table('enterprise_wiki_pages', function (Blueprint $table): void {
            $table->dropUnique('enterprise_wiki_pages_id_customer_unique');
        });
    }
};
