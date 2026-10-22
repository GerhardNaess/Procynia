<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimActor;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimDecisionRecorder;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Concerns\GrantsWikiPermissions;
use Tests\TestCase;

/**
 * A4 (docs/external-api-phase-0-plan.md §2a): a claim's approval status and blocking are quality
 * metadata and change in place — on the published version too — but never without a trace.
 *
 * Every change is an event in enterprise_wiki_claim_decisions, written in the same transaction:
 * a person's decision under their name, a rule Procynia applies as a system event (and the person
 * whose action set it off, if any). A source someone links says who and when. The history cannot
 * be changed or deleted, and it always ends where the claim's current state is.
 */
class EnterpriseWikiClaimDecisionHistoryTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsWikiPermissions;

    private const SUGGESTION = 'Virksomheten bør gjennomføre en årlig tilgangsgjennomgang.';

    private const SECOND_SUGGESTION = 'Virksomheten bør loggføre alle administrative tilganger.';

    private const UNSUPPORTED = 'Alle tilganger fornyes automatisk hvert kvartal.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** 1, 6. A person's decisions on a published page: recorded under their name, and the published text untouched. */
    public function test_every_decision_a_person_takes_is_in_the_history_under_their_name(): void
    {
        $case = $this->publishedPage();
        $publishedText = $this->publishedText($case['published']);

        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['comment' => 'Stemmer.'])->assertRedirect();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'unapprove'))->assertRedirect();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['unsupported'], 'blocking'), ['blocking' => false, 'comment' => 'Ikke vesentlig.'])->assertRedirect();

        $events = $this->history($case['claim']);
        $this->assertSame([['user', 'approval_status', 'pending', 'approved'], ['user', 'approval_status', 'approved', 'pending']], $this->summary($events, 'approval_status'));
        $this->assertTrue($events->every(fn (EnterpriseWikiClaimDecision $e): bool => (int) $e->decided_by_user_id === (int) $case['qa']->id && $e->system_process === null));
        $this->assertSame('Stemmer.', $events->first()->comment);

        $blocking = $this->history($case['unsupported'])->sole();
        $this->assertSame([EnterpriseWikiClaimDecision::ACTOR_USER, (int) $case['qa']->id], [$blocking->actor_type, (int) $blocking->decided_by_user_id]);
        $this->assertSame(['blocking_override' => false], $blocking->new_state);

        // Status is quality metadata: it changed in place, the published text did not, and the page
        // stayed published on the same version (no new working version, no new review).
        $page = $case['page']->fresh();
        $this->assertSame((int) $case['published']->id, (int) $page->published_version_id);
        $this->assertSame(EnterpriseWikiPage::STATUS_APPROVED, $page->status);
        $this->assertSame(1, EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->count());
        $this->assertSame($publishedText, $this->publishedText($case['published']));

        $this->assertHistoryEndsAtCurrentState($case['claim'], $case['unsupported']);
    }

    /** 1, 2. «Koble kilde» names the person; the reset it causes is Procynia's, set off by them. */
    public function test_linking_a_source_names_the_person_and_the_reset_it_causes_is_a_system_event(): void
    {
        $case = $this->publishedPage();
        $document = $this->document($case['customer']);

        $this->actingAs($case['qa'])->patch($this->url($case, $case['secondClaim'], 'approve'))->assertRedirect();
        $this->actingAs($case['qa'])->post($this->url($case, $case['secondClaim'], 'source-references'), [
            'source_document_id' => $document->id,
            'excerpt' => 'Administrative tilganger logges.',
        ])->assertRedirect();

        $reference = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $case['secondClaim']->id)->sole();
        $this->assertSame(EnterpriseWikiSourceReference::LINKED_BY_USER, $reference->linked_by_type);
        $this->assertSame((int) $case['qa']->id, (int) $reference->linked_by_user_id);
        $this->assertNull($reference->linked_by_process);
        $this->assertNotNull($reference->linked_at);

        [$approved, $linked, $reset] = $this->history($case['secondClaim'])->all();
        $this->assertSame([EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, 'user'], [$approved->decision_type, $approved->actor_type]);
        $this->assertSame([EnterpriseWikiClaimDecision::TYPE_SOURCE_REFERENCE, 'user', (int) $case['qa']->id], [$linked->decision_type, $linked->actor_type, (int) $linked->decided_by_user_id]);
        $this->assertSame((int) $reference->id, $linked->new_state['source_reference_id']);
        $this->assertSame((int) $document->id, $linked->new_state['source_id']);

        $this->assertSame(EnterpriseWikiClaimDecision::ACTOR_SYSTEM, $reset->actor_type);
        $this->assertNull($reset->decided_by_user_id, 'a system event is never under a person');
        $this->assertSame(EnterpriseWikiClaimDecision::PROCESS_RESET_ON_FIRST_SOURCE, $reset->system_process);
        $this->assertSame((int) $case['qa']->id, (int) $reset->initiated_by_user_id);
        // What the reset cleared is kept, since no person's decision row says it.
        $this->assertSame('approved', $reset->previous_state['approval_status']);
        $this->assertSame((int) $case['qa']->id, $reset->previous_state['approved_by_user_id']);
        $this->assertSame(['approval_status' => 'pending'], $reset->new_state);

        $this->assertTrue($case['secondClaim']->fresh()->isPending());
        $this->assertHistoryEndsAtCurrentState($case['secondClaim']);
    }

    /** 1. The same reset from the pipeline (verification, reconciliation) names no person at all. */
    public function test_a_reset_by_the_pipeline_is_a_system_event_with_no_person(): void
    {
        $case = $this->publishedPage();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['secondClaim'], 'approve'))->assertRedirect();

        app(EnterpriseWikiAppliedRunLintService::class)->resetClaimDecisionAfterFirstSourceReference($case['secondClaim']->fresh(), true);

        $reset = $this->history($case['secondClaim'])->last();
        $this->assertSame([EnterpriseWikiClaimDecision::ACTOR_SYSTEM, EnterpriseWikiClaimDecision::PROCESS_RESET_ON_FIRST_SOURCE], [$reset->actor_type, $reset->system_process]);
        $this->assertNull($reset->decided_by_user_id);
        $this->assertNull($reset->initiated_by_user_id);
        $this->assertHistoryEndsAtCurrentState($case['secondClaim']);
    }

    /** 1. A deleted document takes a claim's only source: the reopened decision is a system event the deleter set off. */
    public function test_deleting_the_only_source_reopens_the_decision_as_a_system_event(): void
    {
        $case = $this->publishedPage();
        $document = $this->document($case['customer']);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $case['secondClaim']->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Administrative tilganger logges.',
        ] + EnterpriseWikiSourceReference::linkedBySystem(EnterpriseWikiSourceReference::PROCESS_CLAIM_VERIFICATION));
        $this->actingAs($case['qa'])->patch($this->url($case, $case['secondClaim'], 'approve'))->assertRedirect();

        $systemOwner = $this->user($case['customer'], bidRole: User::BID_ROLE_SYSTEM_OWNER);
        app(EnterpriseWikiDocumentDeletionService::class)->delete($document, $systemOwner);

        $reset = $this->history($case['secondClaim'])->last();
        $this->assertSame(EnterpriseWikiClaimDecision::ACTOR_SYSTEM, $reset->actor_type);
        $this->assertSame(EnterpriseWikiClaimDecision::PROCESS_RESET_ON_LOST_SOURCE, $reset->system_process);
        $this->assertSame((int) $systemOwner->id, (int) $reset->initiated_by_user_id);
        $this->assertNull($reset->decided_by_user_id);
        $this->assertTrue($case['secondClaim']->fresh()->isPending());
        $this->assertHistoryEndsAtCurrentState($case['secondClaim']);
    }

    /** A claim copied into a new version says where its decision came from; its sources keep who linked them. */
    public function test_a_copy_for_a_new_version_says_where_its_state_came_from(): void
    {
        $case = $this->publishedPage();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'))->assertRedirect();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['unsupported'], 'blocking'), ['blocking' => true])->assertRedirect();

        // «Fjern teksten» on the published version moves the decision to a new working version (F1d).
        $this->actingAs($case['qa'])->patch($this->url($case, $case['secondClaim'], 'reject'))->assertRedirect();

        $working = EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $case['page']->id)->where('is_current', true)->sole();
        $this->assertNotSame((int) $case['published']->id, (int) $working->id);

        $copy = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->where('content_block_key', 'block-0003')->sole();
        $carried = $this->history($copy)->sole();
        $this->assertSame([EnterpriseWikiClaimDecision::ACTOR_SYSTEM, EnterpriseWikiClaimDecision::PROCESS_COPIED_TO_NEW_VERSION], [$carried->actor_type, $carried->system_process]);
        $this->assertNull($carried->decided_by_user_id);
        $this->assertSame((int) $case['claim']->id, $carried->new_state['copied_from_claim_id']);
        $this->assertSame('approved', $carried->new_state['approval_status']);
        $this->assertSame((int) $case['qa']->id, $carried->new_state['approved_by_user_id']);

        $unsupportedCopy = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->where('content_block_key', 'block-0006')->sole();
        $this->assertSame([EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE], $this->history($unsupportedCopy)->pluck('decision_type')->all());

        // A pending claim carries nothing over and gets no event.
        $pendingCopy = EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $working->id)->where('content_block_key', 'block-0002')->sole();
        $this->assertCount(0, $this->history($pendingCopy));

        $original = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $case['claim']->id)->sole();
        $copiedReference = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $copy->id)->sole();
        $this->assertSame(
            [$original->linked_by_type, $original->linked_by_process, $original->linked_at?->toIso8601String()],
            [$copiedReference->linked_by_type, $copiedReference->linked_by_process, $copiedReference->linked_at?->toIso8601String()],
        );
        $this->assertSame(EnterpriseWikiSourceReference::PROCESS_CLAIM_VERIFICATION, $copiedReference->linked_by_process);

        $this->assertHistoryEndsAtCurrentState(...EnterpriseWikiClaim::query()->whereIn('enterprise_wiki_page_version_id', [$case['published']->id, $working->id])->get()->all());
    }

    /** 4. The change and its event are one write: if either fails, neither is there. */
    public function test_a_status_change_and_its_event_are_written_together_or_not_at_all(): void
    {
        $case = $this->publishedPage();
        $recorder = app(EnterpriseWikiClaimDecisionRecorder::class);
        $approve = fn () => $recorder->change($case['claim'], EnterpriseWikiClaimActor::user($case['qa']), EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, [
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_by_user_id' => $case['qa']->id,
            'approved_at' => now(),
        ]);

        // The event is written, then the claim update fails: the event goes too.
        $this->failingOn(EnterpriseWikiClaim::class, 'updating', $approve);
        $this->assertTrue($case['claim']->fresh()->isPending());
        $this->assertCount(0, $this->history($case['claim']));

        // The event cannot be written: the claim is not changed.
        $this->failingOn(EnterpriseWikiClaimDecision::class, 'creating', $approve);
        $this->assertTrue($case['claim']->fresh()->isPending());
        $this->assertCount(0, $this->history($case['claim']));

        // And with nothing failing, both.
        $approve();
        $this->assertTrue($case['claim']->fresh()->isApproved());
        $this->assertCount(1, $this->history($case['claim']));
        $this->assertHistoryEndsAtCurrentState($case['claim']);
    }

    /** 3. Append-only: neither the application nor the database changes or deletes an event; it goes with its claim. */
    public function test_the_history_cannot_be_changed_or_deleted_but_goes_with_its_claim(): void
    {
        $case = $this->publishedPage();
        $this->actingAs($case['qa'])->patch($this->url($case, $case['claim'], 'approve'), ['comment' => 'Stemmer.'])->assertRedirect();
        $event = $this->history($case['claim'])->sole();

        foreach ([
            fn () => $event->update(['comment' => 'Omskrevet']),
            fn () => $event->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the model must refuse');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $table = fn () => DB::table('enterprise_wiki_claim_decisions')->where('id', $event->id);
        $this->assertRefused(fn () => $table()->update(['comment' => 'Omskrevet']));
        $this->assertRefused(fn () => $table()->update(['new_state' => json_encode(['approval_status' => 'rejected'])]));
        $this->assertRefused(fn () => $table()->update(['decided_by_user_id' => $case['owner']->id]));
        $this->assertRefused(fn () => $table()->update(['actor_type' => 'system', 'system_process' => 'history_backfill', 'decided_by_user_id' => null]));
        $this->assertRefused(fn () => $table()->update(['created_at' => now()->subYear()]));
        $this->assertRefused(fn () => $table()->delete());

        // A deleted person leaves the event behind without their name (it used to delete it).
        $case['qa']->forceDelete();
        $this->assertNull(DB::table('enterprise_wiki_claim_decisions')->where('id', $event->id)->value('decided_by_user_id'));
        $this->assertSame('Stemmer.', DB::table('enterprise_wiki_claim_decisions')->where('id', $event->id)->value('comment'));

        // The claim's own deletion (its version, page or customer) takes its history along.
        $case['claim']->delete();
        $this->assertSame(0, DB::table('enterprise_wiki_claim_decisions')->where('id', $event->id)->count());
    }

    /** An event names a person or a system process — never a stand-in person for the system, never neither. */
    public function test_an_event_names_a_person_or_a_system_process_never_both_or_neither(): void
    {
        $case = $this->publishedPage();
        $base = [
            'enterprise_wiki_claim_id' => $case['claim']->id,
            'decision_type' => EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS,
            'new_state' => ['approval_status' => 'pending'],
        ];

        foreach ([
            'neither' => ['actor_type' => 'user', 'decided_by_user_id' => null],
            'system under a person' => ['actor_type' => 'system', 'system_process' => 'reset_on_first_source', 'decided_by_user_id' => $case['qa']->id],
            'system without a process' => ['actor_type' => 'system', 'decided_by_user_id' => null],
            'person with a process' => ['actor_type' => 'user', 'decided_by_user_id' => $case['qa']->id, 'system_process' => 'reset_on_first_source'],
        ] as $name => $actor) {
            try {
                EnterpriseWikiClaimDecision::query()->create($base + $actor);
                $this->fail("{$name} must be refused");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            // The database cannot tell «neither» from an event whose person was deleted since; the
            // model refuses it when it is written.
            if ($name !== 'neither') {
                $this->assertRefused(fn () => DB::table('enterprise_wiki_claim_decisions')->insert(array_merge($base, $actor, ['new_state' => json_encode($base['new_state'])])), $name);
            }
        }

        $this->assertRefused(fn () => DB::table('enterprise_wiki_source_references')->insert([
            'enterprise_wiki_claim_id' => $case['claim']->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_MANUAL,
            'source_id' => 0,
            'source_label' => 'x',
            'linked_by_type' => EnterpriseWikiSourceReference::LINKED_BY_SYSTEM,
        ]));
    }

    /** Existing data: the migration's backfill makes every history end where its claim's state is. */
    public function test_the_backfill_closes_state_that_has_no_history(): void
    {
        $case = $this->publishedPage();
        // A copy from before this change: decided, and no event at all.
        $case['claim']->forceFill(['approval_status' => 'approved', 'approved_by_user_id' => $case['qa']->id, 'approved_at' => now()])->saveQuietly();
        // A silent system reset from before: the history says approved, the claim is pending.
        $this->actingAs($case['qa'])->patch($this->url($case, $case['secondClaim'], 'approve'))->assertRedirect();
        DB::table('enterprise_wiki_claims')->where('id', $case['secondClaim']->id)->update(['approval_status' => 'pending', 'approved_by_user_id' => null]);
        // Blocking with no event.
        DB::table('enterprise_wiki_claims')->where('id', $case['unsupported']->id)->update(['blocking_override' => true]);

        $migration = require database_path('migrations/2026_10_22_000004_complete_enterprise_wiki_claim_decision_history.php');
        (fn () => $this->backfillStateWithoutHistory())->call($migration);

        $copy = $this->history($case['claim'])->sole();
        $this->assertSame([EnterpriseWikiClaimDecision::ACTOR_SYSTEM, EnterpriseWikiClaimDecision::PROCESS_HISTORY_BACKFILL], [$copy->actor_type, $copy->system_process]);
        $this->assertNull($copy->previous_state);
        $this->assertSame('approved', $copy->new_state['approval_status']);

        $reset = $this->history($case['secondClaim'])->last();
        $this->assertSame(['approval_status' => 'approved'], $reset->previous_state);
        $this->assertSame('pending', $reset->new_state['approval_status']);

        $this->assertSame(['blocking_override' => true], $this->history($case['unsupported'])->sole()->new_state);

        $this->assertHistoryEndsAtCurrentState($case['claim'], $case['secondClaim'], $case['unsupported']);

        // Run again, it adds nothing.
        $before = EnterpriseWikiClaimDecision::query()->count();
        (fn () => $this->backfillStateWithoutHistory())->call($migration);
        $this->assertSame($before, EnterpriseWikiClaimDecision::query()->count());
    }

    /**
     * 5. The invariant this work establishes: read the history alone and you arrive at the claim's
     * current approval status and blocking. A change without its event breaks it.
     */
    private function assertHistoryEndsAtCurrentState(EnterpriseWikiClaim ...$claims): void
    {
        foreach ($claims as $claim) {
            $claim = $claim->fresh();
            $history = $this->history($claim);

            foreach ([
                EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS => ['approval_status', EnterpriseWikiClaim::APPROVAL_STATUS_PENDING],
                EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE => ['blocking_override', null],
            ] as $type => [$column, $initial]) {
                $last = $history->where('decision_type', $type)->last();
                $recorded = $last === null ? $initial : ($last->new_state[$column] ?? null);

                $this->assertSame($claim->getAttribute($column), $recorded, "claim {$claim->id}: {$column} differs from what its history last recorded");
            }
        }
    }

    /** @return Collection<int, EnterpriseWikiClaimDecision> */
    private function history(EnterpriseWikiClaim $claim)
    {
        return EnterpriseWikiClaimDecision::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{0: string, 1: string, 2: mixed, 3: mixed}> */
    private function summary($events, string $column): array
    {
        return $events->map(fn (EnterpriseWikiClaimDecision $e): array => [$e->actor_type, $e->decision_type, $e->previous_state[$column] ?? null, $e->new_state[$column] ?? null])->all();
    }

    /** Runs $write with a listener that throws on the model event, then removes the listener again. */
    private function failingOn(string $model, string $event, callable $write): void
    {
        $model::{$event}(static fn () => throw new RuntimeException('Simulated failure'));

        try {
            $write();
            $this->fail('the simulated failure must surface');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure', $e->getMessage());
        } finally {
            $model::flushEventListeners();
            Model::clearBootedModels();
        }
    }

    private function assertRefused(callable $statement, ?string $message = null): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail('the database must refuse this'.($message ? " ({$message})" : ''));
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    private function publishedText(EnterpriseWikiPageVersion $version): array
    {
        $version = $version->fresh();

        return [
            $version->content_markdown,
            $version->content_blocks_json,
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->orderBy('id')->pluck('claim_text')->all(),
        ];
    }

    /** @param array<string, mixed> $case */
    private function url(array $case, EnterpriseWikiClaim $claim, string $action): string
    {
        return "/app/wiki/{$case['page']->slug}/claims/{$claim->id}/{$action}";
    }

    /** @return array<string, mixed> */
    private function publishedPage(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Påstandshistorikk AS',
            'slug' => 'historikk-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = $this->user($customer);
        $qa = $this->user($customer, isQa: true);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'owner_user_id' => $owner->id,
            'slug' => 'historikk-'.Str::lower(Str::random(6)),
            'title' => 'Tilgangsstyring',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $published = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Tilgangsstyring\n\nTilgang gis etter behov.\n\n".self::SUGGESTION."\n\n## Logging\n\n".self::SECOND_SUGGESTION."\n\n".self::UNSUPPORTED,
            'content_blocks_json' => [
                ['block_key' => 'block-0001', 'position' => 0, 'markdown' => '# Tilgangsstyring'],
                ['block_key' => 'block-0002', 'position' => 1, 'markdown' => 'Tilgang gis etter behov.'],
                ['block_key' => 'block-0003', 'position' => 2, 'markdown' => self::SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
                ['block_key' => 'block-0004', 'position' => 3, 'markdown' => '## Logging'],
                ['block_key' => 'block-0005', 'position' => 4, 'markdown' => self::SECOND_SUGGESTION, 'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE],
                ['block_key' => 'block-0006', 'position' => 5, 'markdown' => self::UNSUPPORTED],
            ],
            'generated_by_model' => 'gpt-5',
        ]);

        $page->forceFill(['status' => EnterpriseWikiPage::STATUS_APPROVED, 'published_version_id' => $published->id])->save();

        $claim = $this->claim($page, $published, self::SUGGESTION, 'block-0003', 0, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        $secondClaim = $this->claim($page, $published, self::SECOND_SUGGESTION, 'block-0005', 1, EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE);
        $unsupported = $this->claim($page, $published, self::UNSUPPORTED, 'block-0006', 2, EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT);
        $plain = $this->claim($page, $published, 'Tilgang gis etter behov.', 'block-0002', 3, EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'tilgangspolicy.pdf',
            'excerpt' => 'Tilgang skal gjennomgås.',
            'source_hash' => str_pad('kilde', 64, '0'),
        ] + EnterpriseWikiSourceReference::linkedBySystem(EnterpriseWikiSourceReference::PROCESS_CLAIM_VERIFICATION));

        return compact('customer', 'owner', 'qa', 'page', 'published', 'claim', 'secondClaim', 'unsupported', 'plain');
    }

    private function claim(EnterpriseWikiPage $page, EnterpriseWikiPageVersion $version, string $text, string $blockKey, int $position, string $origin): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'page_excerpt' => $text,
            'content_block_key' => $blockKey,
            'content_origin' => $origin,
            'generation_issue' => $origin === EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT ? 'unsupported_generated_content' : null,
            'position_order' => $position,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function document(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'loggpolicy.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(12).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Administrative tilganger logges.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function user(Customer $customer, bool $isQa = false, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return $this->grantWikiPermissions($customer, User::factory()->create([
            'customer_id' => $customer->id,
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'is_active' => true,
            'is_qa' => $isQa,
        ]));
    }
}
