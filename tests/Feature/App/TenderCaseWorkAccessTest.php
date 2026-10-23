<?php

namespace Tests\Feature\App;

use App\Data\Ai\Requirements\RequirementEditData;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiAnswerBasisItem;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementAnswerBasisService;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiAssessmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAssessmentService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\SavedNoticeAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A41: reading a case never lets a person write to it. Everything in the AI workspace that creates,
 * changes or deletes information or starts AI work takes the right to work on the case
 * (SavedNoticeAccessService::mayWork), and so do new aksjoner, comments and archiving on the case
 * page. The services ask again where the work is done, so a second way in cannot skip the rule.
 * A person who works on the case keeps every AI function.
 */
class TenderCaseWorkAccessTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /** Readers of the case get 403 on every write, nothing is written and no AI is called. */
    public function test_readers_are_refused_every_case_write_and_nothing_is_written(): void
    {
        $world = $this->world();
        $this->expectNoAi();

        foreach (['per-case reader' => $world['caseViewer'], 'bid_role viewer who saved the case' => $world['bidViewer']] as $who => $reader) {
            $before = $this->snapshot();

            foreach ($this->writes($world) as $label => [$method, $url, $payload]) {
                $this->actingAs($reader)->{$method}($url, $payload)->assertForbidden();
                $this->assertSame($before, $this->snapshot(), "{$who}: {$label}");
            }
        }
    }

    /** A person who cannot see the case, or belongs to another customer, gets 404 as before. */
    public function test_people_outside_the_case_do_not_find_it(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');
        $this->expectNoAi();
        $before = $this->snapshot();

        foreach (['colleague without the case' => $world['bystander'], 'another customer\'s System Owner' => $other['owner']] as $who => $outsider) {
            foreach ($this->writes($world) as $label => [$method, $url, $payload]) {
                if (str_starts_with($label, 'case page:')) {
                    continue;
                }

                $this->actingAs($outsider)->{$method}($url, $payload)->assertNotFound();
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    /** Readers still read and export. */
    public function test_readers_still_read_and_export_the_case(): void
    {
        $world = $this->world();
        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $world['approved']->id,
            'coverage_status' => 'covered',
            'answer_text' => 'Vi har en beredskapsplan.',
            'generated_at' => now(),
        ]);

        foreach ([$world['caseViewer'], $world['bidViewer']] as $reader) {
            $this->actingAs($reader)->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))->assertOk();
            $this->actingAs($reader)->get(route('app.ai.instructions.show', ['savedNotice' => $world['notice']->id]))->assertOk();
            $this->actingAs($reader)->get(route('app.notices.saved.show', ['savedNotice' => $world['notice']->id]))->assertOk();
            $this->actingAs($reader)->get(route('app.ai.requirements.export.docx', ['savedNotice' => $world['notice']->id]))->assertOk();
        }
    }

    /** The pages offer case work only to those who may do it, so no button leads to a 403. */
    public function test_the_pages_offer_case_work_only_to_those_who_may_do_it(): void
    {
        $world = $this->world();
        $writeProps = ['requirements_store_url', 'requirements_delete_all_url', 'assessment_refresh_url', 'documents_upload_url', 'answer_basis_documents_upload_url', 'answer_basis_text_store_url'];
        $rowProps = ['edit_url', 'work_update_url', 'assigned_user_update_url', 'answer_basis_selection_sync_url', 'delete_url', 'wiki_answer_generate_url', 'wiki_answer_update_url'];

        foreach ([
            'contributor on the case' => [$world['contributor'], true],
            'system owner' => [$world['owner'], true],
            'per-case reader' => [$world['caseViewer'], false],
            'bid_role viewer' => [$world['bidViewer'], false],
        ] as $who => [$actor, $mayWork]) {
            $this->actingAs($actor)
                ->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))
                ->assertOk()
                ->assertViewHas('page', function (array $page) use ($mayWork, $writeProps, $rowProps, $who): bool {
                    $this->assertSame($mayWork, data_get($page, 'props.can_work'), $who);

                    foreach ($writeProps as $prop) {
                        $this->assertSame($mayWork, data_get($page, "props.{$prop}") !== null, "{$who}: {$prop}");
                    }

                    $row = collect(data_get($page, 'props.requirements'))->firstWhere('source_type', SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE);

                    foreach ($rowProps as $prop) {
                        $this->assertSame($mayWork, data_get($row, $prop) !== null, "{$who}: requirement {$prop}");
                    }

                    $this->assertSame($mayWork, data_get($page, 'props.documents.0.delete_url') !== null, "{$who}: document delete_url");
                    $this->assertSame($mayWork, data_get($page, 'props.answer_basis_items.0.delete_url') !== null, "{$who}: answer basis delete_url");

                    return true;
                });

            $this->actingAs($actor)
                ->get(route('app.ai.instructions.show', ['savedNotice' => $world['notice']->id]))
                ->assertViewHas('page', fn (array $page): bool => (data_get($page, 'props.ai_instructions_update_url') !== null) === $mayWork);

            $this->actingAs($actor)
                ->get(route('app.notices.saved.show', ['savedNotice' => $world['notice']->id]))
                ->assertViewHas('page', function (array $page) use ($mayWork, $who): bool {
                    $this->assertSame($mayWork, data_get($page, 'props.notice.info_items.can_create'), "{$who}: new aksjon");
                    $this->assertSame($mayWork, data_get($page, 'props.notice.info_items.store_url') !== null, "{$who}: aksjon url");
                    $this->assertSame($mayWork, data_get($page, 'props.notice.phase_comments.can_comment'), "{$who}: comment");

                    return true;
                });
        }
    }

    /** Someone who works on the case keeps the whole AI workspace, answer generation included. */
    public function test_a_contributor_on_the_case_still_does_all_the_work(): void
    {
        $world = $this->world();
        $contributor = $world['contributor'];
        $notice = $world['notice'];
        $at = fn (string $name, array $more = []): string => route($name, array_merge(['savedNotice' => $notice->id], $more));
        $requirement = ['requirement' => $world['approved']->id];

        $this->actingAs($contributor)->post($at('app.ai.requirements.store'), [
            'requirement_text' => 'Leverandøren skal ha ISO 9001.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
        ])->assertRedirect();
        $this->assertTrue(SavedNoticeAiRequirement::query()->where('saved_notice_id', $notice->id)->where('requirement_text', 'Leverandøren skal ha ISO 9001.')->exists());

        $this->actingAs($contributor)->post($at('app.ai.answer-basis.texts.store'), ['title' => 'Referanse', 'body_text' => 'Tidligere leveranse.'])->assertRedirect();
        $this->assertTrue(SavedNoticeAiAnswerBasisItem::query()->where('saved_notice_id', $notice->id)->where('title', 'Referanse')->exists());

        $this->actingAs($contributor)->patch($at('app.ai.requirements.work.update', $requirement), ['work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS])->assertRedirect();
        $this->assertSame(SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS, $world['approved']->fresh()->work_status);

        $this->fakeAnswerAi('Vi har en beredskapsplan som testes årlig.');
        $this->actingAs($contributor)->postJson($at('app.ai.requirements.wiki-answer.generate', $requirement))
            ->assertOk()
            ->assertJsonPath('wiki_answer.text', 'Vi har en beredskapsplan som testes årlig.');
        $this->assertSame($contributor->id, (int) SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $world['approved']->id)->value('generated_by_user_id'));

        $this->actingAs($contributor)->patchJson($at('app.ai.requirements.wiki-answer.update', $requirement), ['answer_text' => 'Redigert svar.'])->assertOk();

        $this->fakeAssessmentAi();
        $this->actingAs($contributor)->post($at('app.ai.requirements.assessment.refresh'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(
            $contributor->id,
            (int) SavedNoticeAiRequirementAssessment::query()->where('saved_notice_ai_requirement_id', $world['approved']->id)->value('assessed_by_user_id'),
        );

        $this->actingAs($contributor)->post($at('app.notices.saved.info-items.store'), [
            'type' => 'note', 'direction' => 'internal', 'channel' => 'manual', 'body' => 'Følg opp.', 'status' => 'open',
        ])->assertRedirect();
        $this->assertSame(1, $notice->infoItems()->count());
    }

    /** Called directly on the services, past every controller: the rule still holds. */
    public function test_the_services_refuse_case_work_to_anyone_outside_the_rule(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');
        $this->expectNoAi();

        $integration = $world['contributor']->replicate();
        $integration->forceFill(['email' => 'integration.'.Str::random(6).'@example.test', 'role' => User::ROLE_INTEGRATION])->save();
        $this->grantCase($world['notice'], $integration, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);

        foreach ([
            'per-case reader' => [$world, $world['caseViewer']],
            'bid_role viewer who saved the case' => [$world, $world['bidViewer']],
            'colleague without the case' => [$world, $world['bystander']],
            'another customer\'s System Owner' => [$world, $other['owner']],
            'integration with case access' => [$world, $integration],
        ] as $who => [$w, $actor]) {
            $this->assertServicesRefuse($w, $actor, $who);
        }

        $archived = $this->world('Arkiv AS');
        $archived['notice']->forceFill(['archived_at' => now(), 'history_type' => 'lost'])->save();
        $this->assertServicesRefuse($archived, $archived['contributor'], 'archived case');

        $withoutModule = $this->world('Uten Anbud AS');
        $withoutModule['customer']->packageEntitlements()->delete();
        $this->assertServicesRefuse($withoutModule, $withoutModule['contributor'], 'customer without Anbud');
    }

    /** A per-case reader grant shows a case on the case page too, but never moves it. */
    public function test_a_reader_grant_does_not_allow_comments_or_archiving(): void
    {
        $world = $this->world();
        $access = app(SavedNoticeAccessService::class);

        $this->assertTrue($access->canView($world['caseViewer'], $world['notice']));
        $this->assertFalse($access->canComment($world['caseViewer'], $world['notice']));
        $this->assertFalse($access->canArchive($world['caseViewer'], $world['notice']));
        $this->assertTrue($access->canComment($world['contributor'], $world['notice']));
        $this->assertTrue($access->canArchive($world['contributor'], $world['notice']));
    }

    /**
     * Structure: every route of the AI workspace that is not a read goes through the case-work rule
     * (or the decision rule, which contains it). A new write cannot be added on visibility alone.
     */
    public function test_every_write_in_the_ai_workspace_goes_through_the_case_work_rule(): void
    {
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'app.ai.') || $route->methods() === ['GET', 'HEAD']) {
                continue;
            }

            [$controller, $method] = explode('@', $route->getActionName());
            $reflection = new \ReflectionMethod($controller, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

            $this->assertTrue(
                str_contains($source, 'workableAiSavedNotice(') || str_contains($source, '->mayDecide('),
                "{$route->getName()} ({$method}) writes on visibility alone",
            );
            $checked++;
        }

        $this->assertGreaterThanOrEqual(18, $checked);
    }

    private function assertServicesRefuse(array $world, User $actor, string $who): void
    {
        $before = $this->snapshot();
        $notice = $world['notice'];
        $requirement = $world['approved'];
        $edit = RequirementEditData::fromArray(['requirement_text' => 'Endret av feil person.', 'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION]);

        $attempts = [
            'create requirement' => fn () => app(RequirementEditorService::class)->createManualRequirement($notice, $edit, $actor),
            'edit requirement' => fn () => app(RequirementEditorService::class)->updateRequirement($requirement, $edit, $actor),
            'answer basis text' => fn () => app(RequirementAnswerBasisService::class)->createTextItem($notice, 'Tittel', 'Tekst', $actor),
            'answer basis document' => fn () => app(RequirementAnswerBasisService::class)->createDocumentItems($notice, [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], $actor),
            'delete answer basis' => fn () => app(RequirementAnswerBasisService::class)->deleteItem($world['basis'], $actor),
            'answer basis selection' => fn () => app(RequirementAnswerBasisService::class)->syncRequirementSelection($requirement, [$world['basis']->id], $actor),
            'generate answer' => fn () => app(RequirementWikiAnswerService::class)->generate($requirement, (int) $notice->customer_id, 'no', $actor),
            'edit answer' => fn () => app(RequirementWikiAnswerService::class)->updateAnswerText($requirement, 'Ny tekst.', $actor),
            'assess requirement' => fn () => app(RequirementWikiAssessmentService::class)->assessRequirement($requirement, (int) $notice->customer_id, 'no', $actor),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$label} must be refused");
            } catch (AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$label} — ".$e::class.': '.$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    private function writes(array $world): array
    {
        $notice = $world['notice'];
        $at = fn (string $name, array $more = []): string => route($name, array_merge(['savedNotice' => $notice->id], $more));
        $requirement = ['requirement' => $world['approved']->id];
        $candidate = ['requirement' => $world['candidate']->id];
        $edit = ['requirement_text' => 'Endret av leser.', 'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION];

        return [
            'AI instruction' => ['patch', $at('app.ai.instructions.update'), ['ai_instructions' => 'Skriv kort.']],
            'upload tender document' => ['post', $at('app.ai.documents.store'), ['documents' => [UploadedFile::fake()->create('krav.pdf', 10, 'application/pdf')]]],
            'delete tender document' => ['delete', $at('app.ai.documents.destroy', ['document' => $world['document']->id]), []],
            'upload answer basis' => ['post', $at('app.ai.answer-basis.documents.store'), ['documents' => [UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')]]],
            'answer basis text' => ['post', $at('app.ai.answer-basis.texts.store'), ['title' => 'Tittel', 'body_text' => 'Tekst']],
            'delete answer basis' => ['delete', $at('app.ai.answer-basis.destroy', ['answerBasisItem' => $world['basis']->id]), []],
            'add requirement' => ['post', $at('app.ai.requirements.store'), $edit],
            'edit requirement' => ['patch', $at('app.ai.requirements.update', $requirement), $edit],
            'delete requirement' => ['delete', $at('app.ai.requirements.destroy', $candidate), []],
            'delete all requirements' => ['delete', $at('app.ai.requirements.destroy-all'), []],
            'responsible' => ['patchJson', $at('app.ai.requirements.assigned-user.update', $requirement), ['assigned_user_id' => $world['contributor']->id]],
            'work status' => ['patch', $at('app.ai.requirements.work.update', $requirement), ['work_status' => SavedNoticeAiRequirement::WORK_STATUS_IN_PROGRESS]],
            'answer basis selection' => ['patchJson', $at('app.ai.requirements.answer-basis.sync', $requirement), ['answer_basis_item_ids' => [$world['basis']->id]]],
            'generate answer' => ['postJson', $at('app.ai.requirements.wiki-answer.generate', $requirement), []],
            'edit answer' => ['patchJson', $at('app.ai.requirements.wiki-answer.update', $requirement), ['answer_text' => 'Ny tekst fra leser.']],
            'assess requirements' => ['post', $at('app.ai.requirements.assessment.refresh'), []],
            'case page: new aksjon' => ['post', $at('app.notices.saved.info-items.store'), ['type' => 'note', 'direction' => 'internal', 'channel' => 'manual', 'body' => 'Fra leser.', 'status' => 'open']],
            'case page: comment' => ['post', $at('app.notices.saved.phase-comments.store'), ['comment' => 'Fra leser.']],
            'case page: archive' => ['patch', $at('app.notices.saved.archive'), ['history_type' => 'lost']],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'requirements' => $rows('saved_notice_ai_requirements', ['id', 'requirement_text', 'work_status', 'assigned_user_id', 'approval_status']),
            'revisions' => DB::table('saved_notice_ai_requirement_revisions')->count(),
            'answers' => $rows('saved_notice_ai_requirement_wiki_answers', ['id', 'answer_text', 'generated_by_user_id']),
            'assessments' => DB::table('saved_notice_ai_requirement_assessments')->count(),
            'documents' => DB::table('saved_notice_ai_documents')->count(),
            'basis' => DB::table('saved_notice_ai_answer_basis_items')->count(),
            'selections' => DB::table('saved_notice_ai_requirement_answer_basis_selections')->count(),
            'info_items' => DB::table('saved_notice_info_items')->count(),
            'comments' => DB::table('saved_notice_phase_comments')->count(),
            'cases' => $rows('saved_notices', ['id', 'archived_at']),
            'instructions' => DB::table('customers')->orderBy('id')->pluck('ai_instructions')->all(),
        ];
    }

    private function expectNoAi(): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessRequirement'));
    }

    private function fakeAnswerAi(string $text): void
    {
        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => $text, 'used_page_ids' => []]]]));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));
    }

    private function fakeAssessmentAi(): void
    {
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->once()
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
                'has_possible_conflict' => false,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
                'requirement_summary' => 'Beredskap.',
                'coverage_rationale' => 'Dekket.',
                'missing_information' => '',
                'recommended_next_step' => 'Ingen.',
            ]));
    }

    /**
     * One customer with Anbud and AI, one case, and the people around it («Se alle saker» held by
     * System Owner only, so access comes from being part of the case):
     * - owner: System Owner;
     * - contributor: works on the case (per-case contributor access);
     * - caseViewer: a contributor by role, but only a reader of this case;
     * - bidViewer: bid_role viewer, and the one who saved the case;
     * - bystander: a contributor with no part in the case.
     *
     * @return array<string, mixed>
     */
    private function world(string $name = 'Anbud AS'): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
            'permission_settings' => [Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner']],
        ]);

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
        $caseViewer = $person('Leser', User::BID_ROLE_CONTRIBUTOR);
        $bidViewer = $person('Viewer', User::BID_ROLE_VIEWER);
        $bystander = $person('Kollega', User::BID_ROLE_CONTRIBUTOR);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $bidViewer->id,
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'A41-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'external_url' => 'https://doffin.no/notices/a41',
            'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        $this->grantCase($notice, $contributor, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);
        $this->grantCase($notice, $caseViewer, SavedNoticeUserAccess::ACCESS_ROLE_VIEWER);

        // A failed upload with nothing behind it: the one kind of document the page lets anyone delete.
        $document = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $notice->id,
            'original_filename' => 'gammel.pdf',
            'stored_path' => 'saved-notices/'.$notice->id.'/ai-documents/gammel.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_FAILED,
        ]);

        $basis = SavedNoticeAiAnswerBasisItem::query()->create([
            'saved_notice_id' => $notice->id,
            'created_by_user_id' => $owner->id,
            'answer_basis_type' => SavedNoticeAiAnswerBasisItem::ANSWER_BASIS_TYPE_TEXT,
            'title' => 'Grunnlag',
            'body_text' => 'Tekst.',
        ]);

        $approved = $this->requirement($notice, 'Leverandøren skal ha beredskapsplan.', SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED);

        return [
            'customer' => $customer,
            'notice' => $notice,
            'owner' => $owner,
            'contributor' => $contributor,
            'caseViewer' => $caseViewer,
            'bidViewer' => $bidViewer,
            'bystander' => $bystander,
            'document' => $document,
            'basis' => $basis,
            'approved' => $approved,
            'candidate' => $this->requirement($notice, 'Uttrukket krav.'),
        ];
    }

    private function requirement(SavedNotice $notice, string $text, string $reviewStatus = SavedNoticeAiRequirement::REVIEW_STATUS_PENDING): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::approvalStatusForReviewStatus($reviewStatus),
            'review_status' => $reviewStatus,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_text' => $text,
            'original_requirement_text' => $text,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);
    }

    private function grantCase(SavedNotice $notice, User $user, string $accessRole): void
    {
        SavedNoticeUserAccess::query()->create([
            'saved_notice_id' => $notice->id,
            'user_id' => $user->id,
            'granted_by_user_id' => $notice->saved_by_user_id,
            'access_role' => $accessRole,
        ]);
    }
}
