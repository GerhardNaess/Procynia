<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality as its own domain.
 *
 * WHAT CHANGED, AND WHY.
 *
 * V1 modelled a styrende dokument as a Wiki page carrying a `quality_type`. That made Wiki the
 * container for two unrelated things: the knowledge the virksomhet has written down, and the
 * governing documents the kvalitetssystem is made of. The two have different lifecycles — a policy
 * exists, is owned and falls due for review whether or not anyone has written a Wiki page about it,
 * and a Wiki page is useful to many quality objects at once or to none. Modelling the first as a
 * property of the second meant a policy could not exist before its page did, could never be
 * supported by two pages, and silently stole a page from the Wiki catalogue the moment it was
 * classified.
 *
 * So a Quality object is now a row of its own. Wiki keeps its catalogue untouched, and the
 * connection between the two is an explicit many-to-many — see quality_item_wiki_links.
 *
 * WHY ONE quality_items TABLE AND NOT SIX.
 *
 * Every type answers the same governance questions: who owns it, what number does it carry, what
 * state is it in, when is it next reviewed. Those are the columns a listing, a review dashboard and
 * an ownership report all read, and splitting them across six tables would mean six-way unions for
 * every one of those reads. What differs between types is *structure*, not metadata — and structure
 * is where the separate tables are: a process has ordered steps, a checklist has items to tick, a
 * control has a criterion and a frequency. A policy, a procedure and an arbeidsinstruks have no
 * structure beyond their description, so they get no table rather than an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // policy | process | procedure | work_instruction | checklist | control.
            // Immutable in practice: QualityItemService refuses to retype an item, because the
            // structure rows hanging off it belong to one type and could not follow it across.
            $table->string('quality_type');

            $table->string('title');
            // The virksomhet's own document number ("P-03", "RUT-12"). Optional and free text —
            // every QMS numbers its documents and none of them agree how — but unique per customer
            // where it is given, because a number that identifies two documents identifies neither.
            $table->string('code')->nullable();
            $table->text('purpose')->nullable();

            // The person accountable for the document, not the one who typed it. nullOnDelete:
            // losing the owner must leave the document standing and ownerless, which is a state a
            // kvalitetsleder needs to see, not one that deletes the document.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            // draft | active | under_review | retired. Quality's own lifecycle, unrelated to a Wiki
            // page's approval status — a retired policy may still have a perfectly current Wiki
            // page describing what it used to require.
            $table->string('status')->default('draft');

            // Review metadata. The interval is what makes next_review_at derivable rather than a
            // date somebody has to remember to move; it is stored alongside so a listing can sort
            // and filter on the date without recomputing it per row.
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->date('last_reviewed_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->foreignId('last_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'quality_type']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'next_review_at']);
        });

        // Partial unique: a code is optional, so NULLs must not collide. Postgres would allow
        // repeated NULLs under a plain unique index too, but stating the predicate makes the rule
        // readable as what it is — "a given number names one document".
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX quality_items_customer_code_unique
            ON quality_items (customer_id, code)
            WHERE code IS NOT NULL
        SQL);

        /**
         * Process structure: the ordered steps the process is carried out in.
         *
         * Rows rather than a JSON payload, unlike the derived process definition this replaces.
         * These steps are authored and edited one at a time, referenced individually, and will
         * later carry their own links to controls and records. A JSON blob would make every edit a
         * rewrite of the whole process and leave nothing for a foreign key to point at.
         */
        Schema::create('quality_process_steps', function (Blueprint $table): void {
            $table->id();
            // Denormalised from the item so that every quality read can be scoped on customer_id
            // directly, the way the rest of the codebase scopes tenancy, without a join back.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            // Free text, not a user id: a process step is carried out by a role ("Innkjøpsansvarlig"),
            // which outlives the person holding it and often has no account at all.
            $table->string('responsibility')->nullable();

            $table->timestamps();

            $table->unique(['quality_item_id', 'position'], 'quality_process_steps_position_unique');
        });

        /**
         * What the process needs to start and what it leaves behind.
         *
         * One table with a direction rather than two near-identical ones: input and output are the
         * same kind of thing seen from two ends, and one process's output is the next one's input.
         */
        Schema::create('quality_process_io', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            // input | output
            $table->string('direction');
            $table->unsignedInteger('position');
            $table->string('label');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['quality_item_id', 'direction', 'position'], 'quality_process_io_position_unique');
        });

        /**
         * What must actually be ticked off while doing the work.
         */
        Schema::create('quality_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->text('text');
            $table->text('guidance')->nullable();
            // A checklist that cannot distinguish "must" from "consider" is a checklist nobody can
            // be held to, so the distinction is a column rather than a convention in the text.
            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->unique(['quality_item_id', 'position'], 'quality_checklist_items_position_unique');
        });

        /**
         * The control's own fields: what is checked, by whom, how often.
         *
         * One row per control — a 1:1 extension rather than six nullable columns on quality_items,
         * which would be null for every item that is not a control. The unique constraint on
         * quality_item_id is the 1:1.
         */
        Schema::create('quality_control_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->unique()->constrained('quality_items')->cascadeOnDelete();

            // What good looks like. The sentence an auditor reads to decide pass or fail.
            $table->text('criterion')->nullable();
            // A role, for the same reason a process step's responsibility is a role.
            $table->string('responsibility')->nullable();
            // continuous | daily | weekly | monthly | quarterly | annually | ad_hoc
            $table->string('frequency')->nullable();
            // How the check is actually performed — sampling, full review, system report.
            $table->text('method')->nullable();

            $table->timestamps();
        });

        /**
         * How the governing documents govern each other.
         *
         * Edges between quality items, not between Wiki pages. V1 put them on pages, which meant an
         * edge could only exist once somebody had written a page for both ends — the structure of
         * the kvalitetssystem was hostage to the state of the documentation. It is the other way
         * round now: the structure exists, and Wiki is evidence attached to it.
         *
         * Stored one-directional. "Policy governs process" is not the same statement as its
         * reverse, so a mirrored row would assert something false; reverse traversal is a
         * to_item_id lookup, which the index covers.
         *
         * Which pairs are legal lives in QualityItemRelation::TYPE_MATRIX, not here: the types sit
         * on the rows being joined, so no table constraint can express it.
         */
        Schema::create('quality_item_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_item_id')->constrained('quality_items')->cascadeOnDelete();
            $table->foreignId('to_item_id')->constrained('quality_items')->cascadeOnDelete();
            $table->string('relation_type');
            $table->string('source')->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['customer_id', 'from_item_id', 'to_item_id', 'relation_type'],
                'quality_item_relations_edge_unique',
            );
            $table->index(['customer_id', 'from_item_id']);
            $table->index(['customer_id', 'to_item_id']);
        });

        /**
         * The seam between the two domains.
         *
         * A Wiki page may back any number of quality items, and a quality item may draw on any
         * number of Wiki pages — including none, which is the normal state of a policy somebody has
         * just registered. Crucially the page learns nothing from being linked: no type, no label,
         * no change to how Wiki lists or treats it. That is the whole difference from V1, where the
         * link *was* a property of the page.
         *
         * `link_type` says what the page is to the item, because "this page documents the process"
         * and "this page is evidence the control was run" are not interchangeable.
         */
        Schema::create('quality_item_wiki_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_item_id')->constrained('quality_items')->cascadeOnDelete();
            $table->foreignId('enterprise_wiki_page_id')->constrained('enterprise_wiki_pages')->cascadeOnDelete();

            // documents | supports | evidence
            $table->string('link_type')->default('documents');
            $table->text('note')->nullable();
            $table->string('source')->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['quality_item_id', 'enterprise_wiki_page_id', 'link_type'],
                'quality_item_wiki_links_unique',
            );
            $table->index(['customer_id', 'enterprise_wiki_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_item_wiki_links');
        Schema::dropIfExists('quality_item_relations');
        Schema::dropIfExists('quality_control_details');
        Schema::dropIfExists('quality_checklist_items');
        Schema::dropIfExists('quality_process_io');
        Schema::dropIfExists('quality_process_steps');
        Schema::dropIfExists('quality_items');
    }
};
