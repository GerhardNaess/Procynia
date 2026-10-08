<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fagmodul → kilde til → Enterprise Wiki-kunnskap, for every module through one table.
 *
 * A row says: this Wiki SOURCE document was handed over from that record in that module, by this
 * person. It replaces Risiko's own `risk_wiki_sources` (rows carried over, ids preserved) and gives
 * Kvalitet, Etterlevelse, Leverandøroppfølging, Avvik and Mål the same provenance without a table
 * each. Kvalitet keeps `quality_activity_wiki_pages` as well — that table also carries the
 * activity key and the page-or-document shape its graph projection depends on.
 *
 * Provenance only: no title, text or status, because each would be a copy of what the Wiki owns.
 *
 * ONE DIRECTION FOR READERS. The source module reads its own rows to show what it handed over. The
 * Wiki UI never joins this table, so a Wiki reader cannot learn which risk, audit or supplier — or
 * that any — was behind a page. The only Wiki-side reader is internal usage attribution
 * (RunsInAiCallContext), which names the source on `ai_usage_attempts` so AI cost is traceable to
 * the module work that caused it.
 *
 * TENANCY. (enterprise_wiki_document_id, customer_id) references the document's own pair, so a
 * row can never point at another customer's document. The source side is polymorphic and cannot
 * carry a foreign key; the handoff service resolves the source through the module's own access
 * service, scoped to the same customer, before a row is written.
 *
 * DELETION, BOTH WAYS. Deleting the document cascades the row. Deleting the source record removes
 * its rows through a model listener (WikiKnowledgeSourceRegistry); the Wiki is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_wiki_documents', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id']);
        });

        Schema::create('enterprise_wiki_document_origins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('enterprise_wiki_document_id');
            // risk | quality | compliance | supplier | improvements | objectives
            $table->string('source_module', 32);
            // The record type inside the module: risk, quality_item, compliance_audit, ...
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['enterprise_wiki_document_id', 'customer_id'])
                ->references(['id', 'customer_id'])->on('enterprise_wiki_documents')->cascadeOnDelete();
            $table->unique(['enterprise_wiki_document_id', 'source_type', 'source_id'], 'ewdo_document_source_unique');
            $table->index(['customer_id', 'source_type', 'source_id'], 'ewdo_customer_source_index');
        });

        $now = now();

        if (Schema::hasTable('risk_wiki_sources')) {
            DB::table('risk_wiki_sources')->orderBy('id')->each(function (object $row): void {
                DB::table('enterprise_wiki_document_origins')->insert([
                    'customer_id' => $row->customer_id,
                    'enterprise_wiki_document_id' => $row->enterprise_wiki_document_id,
                    'source_module' => 'risk',
                    'source_type' => 'risk',
                    'source_id' => $row->risk_id,
                    'created_by_user_id' => $row->created_by_user_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

            Schema::drop('risk_wiki_sources');
        }

        // Kvalitet's document-bound articles: one origin per (item, document), however many
        // activities point at it.
        DB::table('quality_activity_wiki_pages')
            ->whereNotNull('enterprise_wiki_document_id')
            ->selectRaw('customer_id, quality_item_id, enterprise_wiki_document_id, MIN(created_by_user_id) AS created_by_user_id, MIN(created_at) AS created_at')
            ->groupBy('customer_id', 'quality_item_id', 'enterprise_wiki_document_id')
            ->orderBy('enterprise_wiki_document_id')
            ->get()
            ->each(function (object $row) use ($now): void {
                DB::table('enterprise_wiki_document_origins')->insert([
                    'customer_id' => $row->customer_id,
                    'enterprise_wiki_document_id' => $row->enterprise_wiki_document_id,
                    'source_module' => 'quality',
                    'source_type' => 'quality_item',
                    'source_id' => $row->quality_item_id,
                    'created_by_user_id' => $row->created_by_user_id,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::create('risk_wiki_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_document_id')->constrained('enterprise_wiki_documents')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['risk_id', 'enterprise_wiki_document_id']);
            $table->index(['customer_id', 'risk_id']);
        });

        DB::table('enterprise_wiki_document_origins')
            ->where('source_type', 'risk')
            ->whereIn('source_id', DB::table('risks')->select('id'))
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('risk_wiki_sources')->insert([
                    'customer_id' => $row->customer_id,
                    'risk_id' => $row->source_id,
                    'enterprise_wiki_document_id' => $row->enterprise_wiki_document_id,
                    'created_by_user_id' => $row->created_by_user_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });

        Schema::dropIfExists('enterprise_wiki_document_origins');

        Schema::table('enterprise_wiki_documents', function (Blueprint $table): void {
            $table->dropUnique(['id', 'customer_id']);
        });
    }
};
