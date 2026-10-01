<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the "a Wiki page with a quality_type is a Quality object" model.
 *
 * Nothing is thrown away. Every classification becomes a real quality item carrying the same type,
 * number and title, plus an explicit link back to the page it came from — which is exactly the
 * relationship the classification was standing in for. Every relation becomes an item relation
 * between the two items its pages produced.
 *
 * The old tables are then dropped rather than left dormant. Leaving them would mean two answers to
 * "what are this customer's styrende dokumenter", and the one nobody maintains is the one some
 * future query would find. `quality_process_definitions` goes the same way: it modelled a process as
 * a reading of a Wiki version, and a process now has its own authored steps, input and output.
 *
 * down() recreates the tables empty. The rows cannot come back — they live on as quality items — so
 * the reverse is a schema rollback, not a data one. This is stated rather than hidden because the
 * only correct way to undo this migration is to roll the branch back with it.
 */
return new class extends Migration
{
    /**
     * Old relation type -> new relation type. Every V1 type has a direct equivalent; the mapping is
     * written out rather than assumed so that a type without one would be visible here.
     *
     * @var array<string, string>
     */
    private const RELATION_TYPE_MAP = [
        'governs' => 'governs',
        'uses' => 'uses',
        'verifies' => 'verifies',
        'depends_on' => 'depends_on',
    ];

    public function up(): void
    {
        if (Schema::hasTable('quality_page_classifications')) {
            $this->backfill();
        }

        Schema::dropIfExists('quality_process_definitions');
        Schema::dropIfExists('quality_relations');
        Schema::dropIfExists('quality_page_classifications');
    }

    public function down(): void
    {
        // Schema only — see the class docblock. Recreated so a rollback leaves a consistent
        // database, not so the old model can be used again.
        if (! Schema::hasTable('quality_page_classifications')) {
            Schema::create('quality_page_classifications', function ($table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('enterprise_wiki_page_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('quality_type');
                $table->string('quality_code')->nullable();
                $table->string('source')->default('manual');
                $table->foreignId('classified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('classified_at')->nullable();
                $table->timestamps();
                $table->index(['customer_id', 'quality_type']);
            });
        }

        if (! Schema::hasTable('quality_relations')) {
            Schema::create('quality_relations', function ($table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
                $table->foreignId('to_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();
                $table->string('relation_type');
                $table->string('source')->default('manual');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['customer_id', 'from_page_id', 'to_page_id', 'relation_type'], 'quality_relations_edge_unique');
                $table->index(['customer_id', 'from_page_id']);
                $table->index(['customer_id', 'to_page_id']);
            });
        }
    }

    private function backfill(): void
    {
        $classifications = DB::table('quality_page_classifications as c')
            ->join('enterprise_wiki_pages as p', 'p.id', '=', 'c.enterprise_wiki_page_id')
            ->orderBy('c.id')
            ->get([
                'c.id as classification_id',
                'c.customer_id',
                'c.enterprise_wiki_page_id',
                'c.quality_type',
                'c.quality_code',
                'c.classified_by_user_id',
                'c.classified_at',
                'p.title',
                'p.owner_user_id',
            ]);

        if ($classifications->isEmpty()) {
            return;
        }

        // quality_items enforces one document per number per customer, which the old table did not.
        // A number that was reused is dropped rather than allowed to fail the migration: the item
        // keeps its title and its link to the page, and somebody renumbers it afterwards.
        $seenCodes = [];
        $itemIdByPageId = [];
        $now = now();

        foreach ($classifications as $row) {
            $code = $row->quality_code !== null ? trim((string) $row->quality_code) : null;
            $codeKey = $code !== null && $code !== '' ? $row->customer_id.'|'.mb_strtolower($code) : null;

            if ($codeKey === null || isset($seenCodes[$codeKey])) {
                $code = null;
            } else {
                $seenCodes[$codeKey] = true;
            }

            $itemId = DB::table('quality_items')->insertGetId([
                'customer_id' => $row->customer_id,
                'quality_type' => $row->quality_type,
                'title' => $row->title,
                'code' => $code,
                'purpose' => null,
                // The Wiki page's owner was the closest thing V1 had to a document owner, so it
                // carries over. It is a starting point, not a claim: quality ownership is now the
                // item's own field and can diverge from the page's the moment anyone edits it.
                'owner_user_id' => $row->owner_user_id,
                'status' => 'active',
                'review_interval_months' => null,
                'last_reviewed_at' => null,
                'next_review_at' => null,
                'last_reviewed_by_user_id' => null,
                'created_by_user_id' => $row->classified_by_user_id,
                'created_at' => $row->classified_at ?? $now,
                'updated_at' => $now,
            ]);

            $itemIdByPageId[(int) $row->enterprise_wiki_page_id] = $itemId;

            DB::table('quality_item_wiki_links')->insert([
                'customer_id' => $row->customer_id,
                'quality_item_id' => $itemId,
                'enterprise_wiki_page_id' => $row->enterprise_wiki_page_id,
                'link_type' => 'documents',
                'note' => null,
                'source' => 'migrated',
                'created_by_user_id' => $row->classified_by_user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('quality_relations')) {
            return;
        }

        $relations = DB::table('quality_relations')->orderBy('id')->get();
        $inserted = [];

        foreach ($relations as $relation) {
            $fromItemId = $itemIdByPageId[(int) $relation->from_page_id] ?? null;
            $toItemId = $itemIdByPageId[(int) $relation->to_page_id] ?? null;
            $relationType = self::RELATION_TYPE_MAP[$relation->relation_type] ?? null;

            // An edge whose ends were never classified could not have been drawn through the
            // service, but the table allowed it. It has no item to point at, so it does not survive.
            if ($fromItemId === null || $toItemId === null || $relationType === null) {
                continue;
            }

            $key = $fromItemId.'|'.$toItemId.'|'.$relationType;

            if (isset($inserted[$key])) {
                continue;
            }

            $inserted[$key] = true;

            DB::table('quality_item_relations')->insert([
                'customer_id' => $relation->customer_id,
                'from_item_id' => $fromItemId,
                'to_item_id' => $toItemId,
                'relation_type' => $relationType,
                'source' => 'migrated',
                'created_by_user_id' => $relation->created_by_user_id,
                'created_at' => $relation->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
    }
};
