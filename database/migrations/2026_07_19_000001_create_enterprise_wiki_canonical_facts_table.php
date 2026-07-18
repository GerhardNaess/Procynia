<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_wiki_canonical_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Identity signals (Del 3): none of these alone is sufficient — together they scope
            // which claim occurrences are even CANDIDATES for the same fact. content_origin is
            // part of the key because a source restatement and a best-practice recommendation
            // drawn from the same source elements are different kinds of fact, not variants of
            // one. source_hash pins the fact to the document version it was verified against —
            // see EnterpriseWikiClaimCanonicalizationService for how a hash change invalidates it.
            $table->string('content_origin', 40);
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->json('source_element_keys');
            $table->string('source_element_keys_hash', 64);

            // normalized_fingerprint is a DB-level exact-duplicate race guard only (two concurrent
            // claims with byte-identical normalized text under the same source scope collapse into
            // one fact atomically) — it is NOT the equivalence decision. Near-equivalent-but-not-
            // identical text is matched by EnterpriseWikiClaimCanonicalizationService's application-
            // level Tier-2 check, never by this column alone.
            $table->string('normalized_fingerprint', 64);
            $table->text('canonical_text');

            $table->string('verification_status', 30)->default('pending');
            $table->text('verification_reason')->nullable();
            $table->timestamp('verified_at')->nullable();

            // Best-practice decision (Del 9) — one decision shared by every occurrence attached to
            // this fact, mirroring enterprise_wiki_claims' own approval fields.
            $table->string('approval_status', 20)->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_comment')->nullable();

            $table->boolean('is_stale')->default(false);
            $table->string('stale_reason', 60)->nullable();

            $table->timestamps();

            $table->unique([
                'customer_id',
                'content_origin',
                'source_type',
                'source_id',
                'source_hash',
                'source_element_keys_hash',
                'normalized_fingerprint',
            ], 'enterprise_wiki_canonical_facts_identity_unique');

            $table->index(['customer_id', 'content_origin', 'source_type', 'source_id', 'source_hash', 'source_element_keys_hash'], 'enterprise_wiki_canonical_facts_lookup_idx');
        });

        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->foreignId('canonical_fact_id')->nullable()->after('content_block_key')
                ->constrained('enterprise_wiki_canonical_facts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_wiki_claims', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_fact_id');
        });

        Schema::dropIfExists('enterprise_wiki_canonical_facts');
    }
};
