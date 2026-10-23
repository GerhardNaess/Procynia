<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\SavedNoticeAiRequirementWikiAnswerRevision;
use App\Models\User;
use App\Services\Ai\Wiki\EnterpriseWikiSemanticSearchPlanAiClient;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiAssessmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAssessmentService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * P5: a tender answer records the exact published Wiki version it was produced from — the version
 * whose text research actually read, with its number and a fingerprint of that text — and every
 * stored generation and hand-edit is kept, append-only, with who and when.
 *
 * Research runs for real against published pages; only the AI clients are fakes.
 */
class TenderAnswerSourceVersionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use UsesProjectPostgresConnection;

    private const REQUIREMENT = 'Beskriv beredskapsplan for driftstjenesten og hvordan den testes.';

    /** Called once the catalog is built, before any page is read — see the race test. */
    private ?\Closure $afterCatalog = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->mock(EnterpriseWikiSemanticSearchPlanAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('planWikiReading')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $input, array $index): array {
                if ($this->afterCatalog !== null) {
                    ($this->afterCatalog)();
                }

                return [
                    'query_understanding' => ['topic' => 'unknown', 'intent' => 'find', 'explicit_entities' => [], 'explicit_services_or_systems' => [], 'scope' => 'unknown'],
                    'selected_pages' => array_map(static fn (array $page): array => ['page_id' => $page['page_id'], 'intended_use' => 'primary_evidence', 'reason' => 'Test.'], $index),
                    'model' => 'stub/1.0',
                ];
            }));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_each_source_names_the_published_version_that_was_read(): void
    {
        $world = $this->world();
        $first = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $second = $this->publishedPage($world['customer'], 'Driftstjeneste', 'Driftstjenesten har beredskapsplan og vaktordning.');
        $this->fakeAi([$first->id, $second->id]);

        $answer = $this->generate($world);

        $sources = collect($answer->sources)->keyBy('enterprise_wiki_page_id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $sources->keys()->all());

        foreach ([$first, $second] as $page) {
            $version = EnterpriseWikiPageVersion::query()->findOrFail($page->published_version_id);
            $source = $sources[$page->id];
            $this->assertSame($version->id, $source['enterprise_wiki_page_version_id']);
            $this->assertSame((int) $version->version_number, $source['version_number']);
            // The reference is checked against the version row, not taken on trust.
            $this->assertSame(hash('sha256', (string) $version->content_markdown), $source['version_content_sha256']);

            // The claims cited belong to that same version.
            foreach ($source['supporting_claim_ids'] as $claimId) {
                $this->assertSame($version->id, (int) EnterpriseWikiClaim::query()->whereKey($claimId)->value('enterprise_wiki_page_version_id'));
            }
        }

        // The stored generation is kept with the same sources, who and when.
        $revision = SavedNoticeAiRequirementWikiAnswerRevision::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->sole();
        $this->assertSame(SavedNoticeAiRequirementWikiAnswerRevision::EVENT_GENERATED, $revision->event);
        $this->assertSame($world['owner']->id, (int) $revision->actor_user_id);
        $this->assertEquals($answer->sources, $revision->sources);
        $this->assertNotNull($revision->created_at);
    }

    /** Publishing a newer version later leaves the answer's reference — and its history — as it was. */
    public function test_a_later_wiki_update_does_not_move_the_reference(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $this->fakeAi([$page->id]);
        $answer = $this->generate($world);
        $originalVersionId = $page->published_version_id;
        $sourcesBefore = $answer->sources;

        $newer = $this->publishNewVersion($page, 'Beredskapsplanen er erstattet av en ny plan.');
        $page->forceFill(['title' => 'Ny beredskap'])->save();

        $this->assertNotSame($originalVersionId, $newer->id);
        $stored = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->sole();
        $this->assertEquals($sourcesBefore, $stored->sources);
        $this->assertSame($originalVersionId, $stored->sources[0]['enterprise_wiki_page_version_id']);
        $this->assertSame(hash('sha256', (string) EnterpriseWikiPageVersion::query()->findOrFail($originalVersionId)->content_markdown), $stored->sources[0]['version_content_sha256']);
        $this->assertEquals($sourcesBefore, SavedNoticeAiRequirementWikiAnswerRevision::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->sole()->sources);
    }

    /** A newer unpublished working version is never what the answer cites. */
    public function test_an_unpublished_working_version_is_not_cited(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $publishedId = $page->published_version_id;
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => 'Utkast: beredskapsplan for driftstjenesten under revisjon.',
        ]);
        $this->fakeAi([$page->id]);

        $answer = $this->generate($world);

        $this->assertSame($publishedId, $answer->sources[0]['enterprise_wiki_page_version_id']);
        $this->assertSame(1, $answer->sources[0]['version_number']);
    }

    /**
     * A publication landing while research runs: the text was read from the version the catalog
     * held, and the cited claims come from that same version — not from a second lookup of the
     * page's pointer.
     */
    public function test_claims_come_from_the_version_whose_text_was_read(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $readVersionId = $page->published_version_id;
        $this->afterCatalog = function () use ($page): void {
            $this->afterCatalog = null;
            $this->publishNewVersion($page, 'Ny tekst om beredskapsplan for driftstjenesten.');
        };
        $this->fakeAi([$page->id]);

        $answer = $this->generate($world);

        $source = $answer->sources[0];
        $this->assertSame($readVersionId, $source['enterprise_wiki_page_version_id']);
        $this->assertNotEmpty($source['supporting_claim_ids']);

        foreach ($source['supporting_claim_ids'] as $claimId) {
            $this->assertSame($readVersionId, (int) EnterpriseWikiClaim::query()->whereKey($claimId)->value('enterprise_wiki_page_version_id'));
        }
    }

    /** Another customer's published pages are never read or cited. */
    public function test_another_customers_versions_are_never_cited(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');
        $own = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $foreign = $this->publishedPage($other['customer'], 'Beredskapsplan', 'Annen kundes beredskapsplan for driftstjenesten.');
        $this->fakeAi([$own->id, $foreign->id]);

        $answer = $this->generate($world);

        $this->assertSame([$own->id], array_column($answer->sources, 'enterprise_wiki_page_id'));
        $this->assertSame([$own->published_version_id], array_column($answer->sources, 'enterprise_wiki_page_version_id'));
        $this->assertNotContains($foreign->published_version_id, array_column($answer->sources, 'enterprise_wiki_page_version_id'));
    }

    /** An answer from before versions were recorded shows as before; nothing is filled in for it. */
    public function test_a_legacy_answer_without_version_references_still_shows_and_stays_unfilled(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $legacySources = [['enterprise_wiki_page_id' => $page->id, 'page_title' => 'Beredskapsplan', 'page_slug' => $page->slug, 'page_type' => 'concept', 'selection_type' => 'direct_search']];
        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $world['requirement']->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => 'Gammelt svar.',
            'sources' => $legacySources,
            'generated_at' => now()->subYear(),
        ]);

        $this->actingAs($world['owner'])
            ->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))
            ->assertOk();

        $this->assertSame($legacySources, SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->sole()->sources);
        $this->assertSame(0, SavedNoticeAiRequirementWikiAnswerRevision::query()->count());
    }

    /** An assessment names the exact published version it read, as an answer does. */
    public function test_an_assessment_names_the_version_it_read(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $this->fakeAi([$page->id]);
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
                'has_possible_conflict' => false,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
                'requirement_summary' => 'Beredskap.',
                'coverage_rationale' => 'Dekket.',
                'missing_information' => '',
                'recommended_next_step' => 'Ingen.',
            ]));

        $assessment = app(RequirementWikiAssessmentService::class)
            ->assessRequirement($world['requirement'], (int) $world['customer']->id, 'no', $world['owner']);

        $source = $assessment->wiki_sources_snapshot[0];
        $version = EnterpriseWikiPageVersion::query()->findOrFail($page->published_version_id);
        $this->assertSame($version->id, $source['enterprise_wiki_page_version_id']);
        $this->assertSame((int) $version->version_number, $source['version_number']);
        $this->assertSame(hash('sha256', (string) $version->content_markdown), $source['version_content_sha256']);
    }

    /** Each stored generation and hand-edit is a row of its own, kept by the database. */
    public function test_generations_and_edits_are_kept_append_only(): void
    {
        $world = $this->world();
        $page = $this->publishedPage($world['customer'], 'Beredskapsplan', 'Beredskapsplan for driftstjenesten testes årlig.');
        $this->fakeAi([$page->id]);
        $this->generate($world);

        app(RequirementWikiAnswerService::class)->updateAnswerText($world['requirement'], 'Redigert av bidragsyter.', $world['contributor']);

        $revisions = SavedNoticeAiRequirementWikiAnswerRevision::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->orderBy('id')->get();
        $this->assertSame(['generated', 'edited'], $revisions->pluck('event')->all());
        $this->assertSame([$world['owner']->id, $world['contributor']->id], $revisions->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('Redigert av bidragsyter.', $revisions[1]->answer_text);
        $this->assertSame($revisions[0]->sources, $revisions[1]->sources);

        foreach ([
            'changing the text' => fn () => DB::table('saved_notice_ai_requirement_wiki_answer_revisions')->where('id', $revisions[0]->id)->update(['answer_text' => 'Omskrevet']),
            'changing the actor' => fn () => DB::table('saved_notice_ai_requirement_wiki_answer_revisions')->where('id', $revisions[0]->id)->update(['actor_user_id' => $world['contributor']->id]),
            'deleting a revision' => fn () => DB::table('saved_notice_ai_requirement_wiki_answer_revisions')->where('id', $revisions[0]->id)->delete(),
        ] as $what => $statement) {
            DB::beginTransaction();

            try {
                $statement();
                $this->fail("The database accepted {$what}");
            } catch (QueryException) {
                // refused
            } finally {
                DB::rollBack();
            }
        }

        // A deleted person is nulled; the row stays.
        $world['contributor']->delete();
        $this->assertNull($revisions[1]->fresh()->actor_user_id);
        $this->assertSame('Redigert av bidragsyter.', $revisions[1]->fresh()->answer_text);
    }

    private function generate(array $world): SavedNoticeAiRequirementWikiAnswer
    {
        return app(RequirementWikiAnswerService::class)->generate($world['requirement'], (int) $world['customer']->id, 'no', $world['owner']);
    }

    /** @param list<int> $pageIds */
    private function fakeAi(array $pageIds): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->andReturnUsing(function (string $id, string $text, array $candidates, array $alreadyRead) use ($pageIds): array {
                $allowed = array_values(array_intersect($pageIds, array_column($candidates, 'page_id')));

                return $alreadyRead === [] && $allowed !== []
                    ? ['action' => RequirementWikiResearchAiClient::ACTION_READ_PAGES, 'page_ids' => $allowed, 'search_terms' => [], 'reason' => 'Relevant.']
                    : ['action' => RequirementWikiResearchAiClient::ACTION_ENOUGH_CONTEXT, 'page_ids' => [], 'search_terms' => [], 'reason' => 'Nok.'];
            }));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->andReturnUsing(fn (string $id, string $text, array $pages): array => ['answer_sections' => [[
                'key' => 'S1', 'heading' => '', 'text' => 'Vi har en beredskapsplan som testes årlig.', 'used_page_ids' => array_column($pages, 'page_id'),
            ]]]));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->andReturnUsing(fn (string $id, string $text, array $sections, array $pages): array => [[
                'section_key' => 'S1', 'alignment_status' => 'aligned', 'supporting_page_ids' => array_column($pages, 'page_id'),
                'supported_points' => [], 'uncovered_points' => [], 'conflict_summary' => null, 'review_note' => null,
            ]]));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));
    }

    private function publishedPage(Customer $customer, string $title, string $text): EnterpriseWikiPage
    {
        $page = $this->createPublishedWikiPage($customer, $title, "# {$title}\n\n{$text}");
        $this->createWikiClaim($page, $text, ['content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED]);

        return $page->refresh();
    }

    /** A newer version with its own claim, published: what a later Wiki update looks like. */
    private function publishNewVersion(EnterpriseWikiPage $page, string $text): EnterpriseWikiPageVersion
    {
        EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->update(['is_current' => false]);
        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => (int) EnterpriseWikiPageVersion::query()->where('enterprise_wiki_page_id', $page->id)->max('version_number') + 1,
            'is_current' => true,
            'content_markdown' => "# {$page->title}\n\n{$text}",
        ]);
        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
        ]);
        $this->approveWikiPageVersionAsDocumentOwner($version);
        $page->forceFill(['published_version_id' => $version->id])->save();

        return $version;
    }

    /** @return array{customer: Customer, owner: User, contributor: User, notice: SavedNotice, requirement: SavedNoticeAiRequirement} */
    private function world(string $name = 'Kildeversjon AS'): array
    {
        $customer = $this->createWikiCustomer($name);
        $customer->forceFill(['subscription_plan' => Customer::PLAN_PRO, 'billing_interval' => Customer::BILLING_MONTHLY, 'included_ai_credits' => 20])->save();

        $person = fn (string $label, string $bidRole, string $role = User::ROLE_USER): User => User::factory()->create([
            'name' => $label,
            'email' => Str::slug($label).'.'.Str::lower(Str::random(6)).'@example.test',
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $owner = $person('Eier', User::BID_ROLE_SYSTEM_OWNER, User::ROLE_CUSTOMER_ADMIN);
        $contributor = $person('Bidragsyter', User::BID_ROLE_CONTRIBUTOR);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $owner->id,
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'P5-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'status' => 'ACTIVE',
        ]);

        $requirement = SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_identifier' => 'K-1',
            'requirement_text' => self::REQUIREMENT,
            'original_requirement_text' => self::REQUIREMENT,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);

        return compact('customer', 'owner', 'contributor', 'notice', 'requirement');
    }
}
