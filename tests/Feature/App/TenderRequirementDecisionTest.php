<?php

namespace Tests\Feature\App;

use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementRevision;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\SavedNoticeUserAccess;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use App\Services\Security\ActorChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * B4 (phase 0, step 16): approving, rejecting and restoring a tender requirement is a person's
 * decision. It takes `decide_bid_requirements` in the Tilganger matrix, the right to work on the
 * case (readers never), an active case and a customer holding Anbud — enforced in
 * RequirementEditorService, so no entry can reach it without the rule.
 *
 * Alongside it, the AI side of Anbud keeps working: extraction never produces a decided
 * requirement, and ordinary case work and answer drafts need no decision permission.
 */
class TenderRequirementDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const DECISIONS = ['approve', 'reject', 'restore'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_contributor_on_the_case_approves_rejects_and_restores_and_is_named_each_time(): void
    {
        $world = $this->world();
        $requirement = $world['requirement'];
        $url = route('app.ai.requirements.review-status.update', ['savedNotice' => $world['notice']->id, 'requirement' => $requirement->id]);

        $this->actingAs($world['contributor'])->patch($url, ['review_status' => 'confirmed'])->assertRedirect();
        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED, $requirement->approval_status);
        $this->assertSame($world['contributor']->id, (int) $requirement->approved_by_user_id);

        $this->actingAs($world['contributor'])->patch($url, ['review_status' => 'rejected'])->assertRedirect();
        $requirement->refresh();
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED, $requirement->approval_status);
        $this->assertSame($world['contributor']->id, (int) $requirement->rejected_by_user_id);

        $this->actingAs($world['contributor'])->patch($url, ['review_status' => 'pending'])->assertRedirect();
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $requirement->fresh()->approval_status);

        $revisions = SavedNoticeAiRequirementRevision::query()
            ->where('saved_notice_ai_requirement_id', $requirement->id)
            ->whereIn('change_type', [
                SavedNoticeAiRequirementRevision::CHANGE_TYPE_APPROVE,
                SavedNoticeAiRequirementRevision::CHANGE_TYPE_REJECT,
                SavedNoticeAiRequirementRevision::CHANGE_TYPE_RESTORE,
            ])
            ->orderBy('id')
            ->get();

        $this->assertSame(['approve', 'reject', 'restore'], $revisions->pluck('change_type')->all());
        $this->assertSame([$world['contributor']->id], $revisions->pluck('changed_by_user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
    }

    public function test_reject_all_names_the_person_on_every_requirement(): void
    {
        $world = $this->world();
        $second = $this->requirement($world['notice'], 'Krav to');

        $this->actingAs($world['contributor'])
            ->patch(route('app.ai.requirements.reject-all', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect();

        foreach ([$world['requirement'], $second] as $requirement) {
            $requirement->refresh();
            $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED, $requirement->approval_status);
            $this->assertSame($world['contributor']->id, (int) $requirement->rejected_by_user_id);
        }
    }

    /** Readers and people outside the matrix are refused at the door, and nothing is written. */
    public function test_people_without_the_decision_are_refused_over_http_and_nothing_changes(): void
    {
        $world = $this->world();
        $narrowed = $this->world('Smal matrise AS', [Customer::PERMISSION_DECIDE_BID_REQUIREMENTS => ['system_owner']]);

        $cases = [
            'per-case viewer' => [$world, $world['caseViewer']],
            'bid_role viewer who saved the case' => [$world, $world['bidViewer']],
            'contributor outside the matrix' => [$narrowed, $narrowed['contributor']],
        ];

        foreach ($cases as $who => [$w, $actor]) {
            $before = $this->snapshot();

            foreach (['confirmed', 'rejected', 'pending'] as $status) {
                $this->actingAs($actor)
                    ->patch(route('app.ai.requirements.review-status.update', ['savedNotice' => $w['notice']->id, 'requirement' => $w['requirement']->id]), ['review_status' => $status])
                    ->assertForbidden();
            }

            $this->actingAs($actor)
                ->patch(route('app.ai.requirements.reject-all', ['savedNotice' => $w['notice']->id]))
                ->assertForbidden();

            $this->assertSame($before, $this->snapshot(), $who);
        }
    }

    public function test_the_page_offers_the_decision_only_to_those_who_hold_it(): void
    {
        $world = $this->world();

        foreach ([
            'contributor' => [$world['contributor'], true],
            'system owner' => [$world['owner'], true],
            'per-case viewer' => [$world['caseViewer'], false],
            'bid_role viewer' => [$world['bidViewer'], false],
        ] as $who => [$actor, $expected]) {
            $this->actingAs($actor)
                ->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))
                ->assertOk()
                ->assertViewHas('page', fn (array $page): bool => data_get($page, 'props.can_decide_requirements') === $expected);
        }
    }

    /** Called directly on the service, past every controller: the rule still holds. */
    public function test_the_service_refuses_every_decision_to_anyone_outside_the_rule(): void
    {
        $world = $this->world();
        $other = $this->world('Annen kunde AS');

        $integration = $world['contributor']->replicate();
        $integration->forceFill(['email' => 'integration.'.Str::random(6).'@example.test', 'role' => User::ROLE_INTEGRATION])->save();
        $this->grantCase($world['notice'], $integration, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);

        $inactive = $world['contributor']->replicate();
        $inactive->forceFill(['email' => 'inactive.'.Str::random(6).'@example.test', 'is_active' => false])->save();
        $this->grantCase($world['notice'], $inactive, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR);

        $narrowed = $this->world('Smal AS', [Customer::PERMISSION_DECIDE_BID_REQUIREMENTS => ['system_owner']]);

        $this->assertRefused($world, $integration, HumanDecisionRefused::class, 'integration with case access');
        $this->assertRefused($world, $inactive, HumanDecisionRefused::class, 'inactive person');
        $this->assertRefused($world, $world['caseViewer'], AuthorizationException::class, 'per-case viewer');
        $this->assertRefused($world, $world['bidViewer'], AuthorizationException::class, 'bid_role viewer');
        $this->assertRefused($world, $world['bystander'], AuthorizationException::class, 'colleague without the case');
        $this->assertRefused($world, $other['owner'], AuthorizationException::class, 'another customer\'s System Owner');
        $this->assertRefused($world, $other['contributor'], AuthorizationException::class, 'another customer\'s contributor');
        $this->assertRefused($narrowed, $narrowed['contributor'], AuthorizationException::class, 'contributor outside the matrix');

        $archived = $this->world('Arkiv AS');
        $archived['notice']->forceFill(['archived_at' => now(), 'history_type' => 'lost'])->save();
        $this->assertRefused($archived, $archived['contributor'], AuthorizationException::class, 'archived case');

        $withoutModule = $this->world('Uten Anbud AS');
        $withoutModule['customer']->packageEntitlements()->delete();
        $this->assertRefused($withoutModule, $withoutModule['contributor'], AuthorizationException::class, 'customer without Anbud');
    }

    public function test_the_api_channel_is_refused_even_for_a_person_who_holds_the_decision(): void
    {
        $world = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertRefused($world, $world['contributor'], HumanDecisionRefused::class, 'api channel');
    }

    /** The QA column works for this row as for every other one. */
    public function test_the_matrix_row_can_be_narrowed_and_reached_through_qa(): void
    {
        $world = $this->world();

        $this->actingAs($world['owner'])
            ->get(route('app.customer-environment.index', ['tab' => 'permissions']))
            ->assertOk()
            ->assertViewHas('page', fn (array $page): bool => collect(data_get($page, 'props.permissionSettings.permission_rows', []))
                ->contains(fn (array $row): bool => $row['key'] === Customer::PERMISSION_DECIDE_BID_REQUIREMENTS
                    && $row['roles'] === ['system_owner', 'bid_manager', 'contributor']));

        $this->actingAs($world['owner'])
            ->patch(route('app.customer-environment.permissions.update'), [
                'permission' => Customer::PERMISSION_DECIDE_BID_REQUIREMENTS,
                'roles' => [Customer::ROLE_QA],
            ])
            ->assertRedirect();

        $this->assertFalse($world['contributor']->fresh()->canDecideBidRequirements());
        $world['contributor']->forceFill(['is_qa' => true])->save();
        $this->assertTrue($world['contributor']->fresh()->canDecideBidRequirements());

        app(RequirementEditorService::class)->approveRequirement($world['requirement'], $world['contributor']->fresh());
        $this->assertSame($world['contributor']->id, (int) $world['requirement']->fresh()->approved_by_user_id);
    }

    /** Extraction proposes; it never decides, whatever reaches the editor. */
    public function test_an_extracted_requirement_is_always_born_undecided(): void
    {
        $world = $this->world();
        $document = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $world['notice']->id,
            'original_filename' => 'krav.docx',
            'stored_path' => 'ai/krav.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size_bytes' => 100,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_COMPLETED,
        ]);
        $candidate = RequirementExtractionCandidateData::fromLegacyArray([
            'requirement_text' => 'Leverandøren skal ha ISO 27001.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
        ], $document);

        $requirement = app(RequirementEditorService::class)->createAiCandidate(
            $document,
            null,
            $candidate->toPersistenceData($document, null, []),
            null,
            [
                'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
                'approved_at' => now(),
                'approved_by_user_id' => $world['owner']->id,
            ],
        );

        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT, $requirement->approval_status);
        $this->assertSame(SavedNoticeAiRequirement::REVIEW_STATUS_PENDING, $requirement->review_status);
        $this->assertNull($requirement->approved_by_user_id);
        $this->assertNull($requirement->approved_at);
    }

    /**
     * Ordinary case work and the AI answer draft need no decision permission: a contributor taken
     * out of the matrix still edits requirements and gets a Wiki answer generated.
     */
    public function test_case_work_and_answer_drafts_do_not_need_the_decision(): void
    {
        $world = $this->world('Arbeid AS', [Customer::PERMISSION_DECIDE_BID_REQUIREMENTS => ['system_owner']]);
        $contributor = $world['contributor'];
        $this->assertFalse($contributor->canDecideBidRequirements());

        $this->actingAs($contributor)
            ->patch(route('app.ai.requirements.update', ['savedNotice' => $world['notice']->id, 'requirement' => $world['requirement']->id]), [
                'requirement_text' => 'Leverandøren skal dokumentere rutiner for avvik.',
                'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            ])
            ->assertRedirect();
        $this->assertSame('Leverandøren skal dokumentere rutiner for avvik.', $world['requirement']->fresh()->requirement_text);

        $this->fakeAnswerAi('Vi dokumenterer avvik i et eget register.');

        $this->actingAs($contributor)
            ->postJson(route('app.ai.requirements.wiki-answer.generate', ['savedNotice' => $world['notice']->id, 'requirement' => $world['requirement']->id]))
            ->assertOk()
            ->assertJsonPath('wiki_answer.text', 'Vi dokumenterer avvik i et eget register.');

        $this->actingAs($contributor)
            ->patchJson(route('app.ai.requirements.wiki-answer.update', ['savedNotice' => $world['notice']->id, 'requirement' => $world['requirement']->id]), [
                'answer_text' => 'Redigert av bidragsyter.',
            ])
            ->assertOk();
        $this->assertSame('Redigert av bidragsyter.', SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $world['requirement']->id)->value('answer_text'));
    }

    private function assertRefused(array $world, User $actor, string $refusal, string $who): void
    {
        $before = $this->snapshot();
        $service = app(RequirementEditorService::class);
        $requirement = $world['requirement'];

        $attempts = [
            'approve' => fn () => $service->approveRequirement($requirement, $actor),
            'reject' => fn () => $service->rejectRequirement($requirement, $actor),
            'restore' => fn () => $service->restoreRequirement($world['rejected'], $actor),
        ];

        foreach (self::DECISIONS as $decision) {
            try {
                $attempts[$decision]();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e::class.': '.$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'requirements' => DB::table('saved_notice_ai_requirements')->orderBy('id')
                ->get(['id', 'approval_status', 'review_status', 'approved_by_user_id', 'rejected_by_user_id', 'approved_at', 'rejected_at'])
                ->map(fn ($row): array => (array) $row)->all(),
            'revisions' => DB::table('saved_notice_ai_requirement_revisions')->count(),
        ];
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

    /**
     * One customer with Anbud and AI, one case, and the people around it (with «Se alle saker» held
     * by System Owner only):
     * - owner: System Owner;
     * - contributor: works on the case (per-case contributor access);
     * - caseViewer: a contributor by role, but only a reader of this case;
     * - bidViewer: bid_role viewer, and the one who saved the case (so they can manage it);
     * - bystander: a contributor with no part in the case.
     *
     * @return array<string, mixed>
     */
    private function world(string $name = 'Anbud AS', array $permissionSettings = []): array
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
            // «Se alle saker» is narrowed so that access to the case comes only from being part of
            // it. Under the default, a contributor attached to the company works on every case and
            // a per-case viewer grant would not make them a reader.
            'permission_settings' => array_merge([Customer::PERMISSION_VIEW_ALL_CASES => ['system_owner']], $permissionSettings),
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
            'external_id' => 'B4-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'external_url' => 'https://doffin.no/notices/b4',
            'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-12-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ]);

        $this->grantCase($notice, $contributor, SavedNoticeUserAccess::ACCESS_ROLE_CONTRIBUTOR, $owner);
        $this->grantCase($notice, $caseViewer, SavedNoticeUserAccess::ACCESS_ROLE_VIEWER, $owner);

        return [
            'customer' => $customer,
            'notice' => $notice,
            'owner' => $owner,
            'contributor' => $contributor,
            'caseViewer' => $caseViewer,
            'bidViewer' => $bidViewer,
            'bystander' => $bystander,
            'requirement' => $this->requirement($notice, 'Leverandøren skal ha beredskapsplan.'),
            'rejected' => $this->requirement($notice, 'Avvist krav.', SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED),
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

    private function grantCase(SavedNotice $notice, User $user, string $accessRole, ?User $grantedBy = null): void
    {
        SavedNoticeUserAccess::query()->create([
            'saved_notice_id' => $notice->id,
            'user_id' => $user->id,
            'granted_by_user_id' => $grantedBy?->id ?? $notice->saved_by_user_id,
            'access_role' => $accessRole,
        ]);
    }
}
