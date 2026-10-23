<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\SavedNoticeStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A42: a tender case with decisions, and a requirement that has been approved, are never deleted.
 * They are moved to history and rejected instead — the ways out the product already has. A case
 * saved by mistake, and an extracted requirement only rejected or never decided, are deleted as
 * before. AI usage counted against the customer stays with its case.
 */
class TenderDeletionProtectionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_an_approved_requirement_is_kept_and_offered_no_delete(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->approveRequirement($requirement, $world['owner']);

        $this->actingAs($world['owner'])
            ->delete(route('app.ai.requirements.destroy', ['savedNotice' => $world['notice']->id, 'requirement' => $requirement->id]))
            ->assertRedirect()
            ->assertSessionHas('error', __('procynia.ai.requirement_delete_kept_approved'));
        $this->assertNotNull($requirement->fresh());

        // Rejecting takes it out of the bid; its approval stays history, so it is still kept.
        app(RequirementEditorService::class)->rejectRequirement($requirement, $world['owner']);
        $this->assertFalse($requirement->fresh()->isDeletable());

        $this->actingAs($world['owner'])
            ->get(route('app.ai.show', ['savedNotice' => $world['notice']->id]))
            ->assertViewHas('page', fn (array $page): bool => collect(data_get($page, 'props.requirements'))
                ->firstWhere('id', $requirement->id)['delete_url'] === null);
    }

    public function test_the_service_refuses_an_approved_requirement_too(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->approveRequirement($requirement, $world['owner']);

        $this->expectException(\LogicException::class);
        app(RequirementEditorService::class)->deleteCandidate($requirement, $world['owner']);
    }

    public function test_delete_all_keeps_approved_requirements_and_says_how_many(): void
    {
        $world = $this->world();
        $approved = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->approveRequirement($approved, $world['owner']);
        $rejected = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->rejectRequirement($rejected, $world['owner']);
        $undecided = $this->requirement($world['notice']);

        $this->actingAs($world['owner'])
            ->delete(route('app.ai.requirements.destroy-all', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect()
            ->assertSessionHas('success', __('procynia.ai.requirements_deleted_kept', ['count' => 2, 'kept' => 1]));

        $this->assertNotNull($approved->fresh());
        $this->assertNull($rejected->fresh());
        $this->assertNull($undecided->fresh());
    }

    /** A case saved by mistake — requirements rejected or undecided, no status change — is deleted. */
    public function test_a_case_saved_by_mistake_is_still_deleted_by_the_person_who_saved_it(): void
    {
        $world = $this->world();
        $rejected = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->rejectRequirement($rejected, $world['owner']);
        $this->requirement($world['notice']);

        $this->assertListOffersDelete($world, true);

        $this->actingAs($world['owner'])
            ->delete(route('app.notices.saved.destroy', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($world['notice']->fresh());
    }

    public function test_a_case_with_a_status_change_is_moved_to_history_instead(): void
    {
        $world = $this->world();
        app(SavedNoticeStatusService::class)->transition($world['owner'], $world['notice'], SavedNotice::BID_STATUS_QUALIFYING);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
        $this->assertListOffersDelete($world, false);

        // Moving it to history is still there, and once there it stays.
        $this->actingAs($world['owner'])
            ->patch(route('app.notices.saved.archive', ['savedNotice' => $world['notice']->id]), ['history_type' => 'lost'])
            ->assertRedirect();
        $this->assertNotNull($world['notice']->fresh()->archived_at);

        $this->actingAs($world['owner'])
            ->delete(route('app.notices.history.destroy', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect()
            ->assertSessionHas('error', __('procynia.notices.history_delete_kept'));
        $this->assertNotNull($world['notice']->fresh());
    }

    public function test_a_no_go_case_is_kept(): void
    {
        $world = $this->world();
        app(SavedNoticeStatusService::class)->transition($world['owner'], $world['notice'], SavedNotice::BID_STATUS_NO_GO, SavedNotice::BID_CLOSURE_REASON_CAPACITY, 'Ingen kapasitet.');

        // No-Go moves the case out of the work list; no page offers to delete it, and no entry can.
        $notice = $world['notice']->fresh();
        $this->assertFalse($notice->isDeletable());

        try {
            $notice->delete();
            $this->fail('a No-Go case was deleted');
        } catch (\LogicException) {
            // refused before the query is sent; the database refuses the same (TenderHistoryProtectionTest)
        }

        $this->assertNotNull($world['notice']->fresh());
    }

    public function test_a_case_with_an_approved_requirement_is_kept(): void
    {
        $world = $this->world();
        app(RequirementEditorService::class)->approveRequirement($this->requirement($world['notice']), $world['owner']);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
    }

    /** AI usage counted against the customer stays traceable to its case, and is not given back. */
    public function test_a_case_with_ai_usage_is_kept_and_so_is_the_usage(): void
    {
        $world = $this->world();
        $usage = CustomerAiCaseUsage::query()->create([
            'customer_id' => $world['customer']->id,
            'saved_notice_id' => $world['notice']->id,
            'activated_at' => now(),
            'activated_by_user_id' => $world['owner']->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'source_operation_key' => 'tender.requirement_answer',
        ]);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
        $this->assertNotNull($usage->fresh());
    }

    /** Approved, then rejected: out of the bid, but its approval is history — kept, by the page and by SQL. */
    public function test_a_requirement_approved_then_rejected_is_kept_by_delete_all_and_by_the_database(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice']);
        app(RequirementEditorService::class)->approveRequirement($requirement, $world['owner']);
        app(RequirementEditorService::class)->rejectRequirement($requirement, $world['owner']);

        $this->actingAs($world['owner'])
            ->delete(route('app.ai.requirements.destroy-all', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect()
            ->assertSessionHas('success', __('procynia.ai.requirements_deleted_kept', ['count' => 0, 'kept' => 1]));
        $this->assertNotNull($requirement->fresh());

        $this->assertDatabaseRefuses(fn () => DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->delete(), 'an approved-then-rejected requirement');
        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
    }

    /** Approved before revisions were recorded: the row's own approval keeps it, and its case. */
    public function test_a_requirement_approved_without_a_revision_keeps_its_case(): void
    {
        $world = $this->world();
        $requirement = $this->requirement($world['notice']);
        $requirement->forceFill([
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'approved_at' => now()->subYear(),
        ])->save();

        $this->assertFalse($requirement->fresh()->isDeletable());
        $this->assertDatabaseRefuses(fn () => DB::table('saved_notice_ai_requirements')->where('id', $requirement->id)->delete(), 'a requirement approved without a revision');
        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
    }

    /** A No-Go recorded before status changes were (no status change row) still keeps the case. */
    public function test_a_legacy_no_go_without_a_status_change_keeps_the_case(): void
    {
        $world = $this->world();
        DB::table('saved_notice_no_go_decisions')->insert([
            'saved_notice_id' => $world['notice']->id,
            'customer_id' => $world['customer']->id,
            'closed_by_user_id' => $world['owner']->id,
            'closure_reason' => SavedNotice::BID_CLOSURE_REASON_CAPACITY,
            'closed_at' => now()->subYear(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
    }

    /**
     * P2: a case from before status changes were recorded has no history rows. What the case row
     * itself shows a person did keeps it: a status other than «Oppdaget», a registered submission,
     * or an outcome recorded in history. Nothing is reconstructed — no status change row and no
     * actor is written.
     */
    public function test_a_legacy_case_with_a_status_but_no_history_rows_is_kept(): void
    {
        $world = $this->world();
        DB::table('saved_notices')->where('id', $world['notice']->id)->update(['bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS]);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
        $this->assertSame(0, DB::table('saved_notice_status_changes')->where('saved_notice_id', $world['notice']->id)->count());
    }

    public function test_a_legacy_case_with_a_registered_submission_is_kept(): void
    {
        $world = $this->world();
        DB::table('bid_submissions')->insert([
            'saved_notice_id' => $world['notice']->id,
            'sequence_number' => 1,
            'label' => 'Innsending 1',
            'submitted_at' => now()->subYear(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $this->assertCaseKept($world, 'procynia.notices.delete_kept_history');
    }

    /** An outcome recorded on the way to history keeps the case; «Avbrutt» alone does not. */
    public function test_a_case_moved_to_history_keeps_by_its_recorded_outcome_only(): void
    {
        foreach ([SavedNotice::HISTORY_TYPE_WON => false, SavedNotice::HISTORY_TYPE_LOST => false, SavedNotice::HISTORY_TYPE_NO_GO => false, SavedNotice::HISTORY_TYPE_ABORTED => true] as $outcome => $deletable) {
            $world = $this->world();
            $world['notice']->forceFill(['archived_at' => now(), 'history_type' => $outcome])->save();

            $this->assertSame($deletable, $world['notice']->fresh()->isDeletable(), $outcome);

            if ($deletable) {
                $this->actingAs($world['owner'])
                    ->delete(route('app.notices.history.destroy', ['savedNotice' => $world['notice']->id]))
                    ->assertRedirect()
                    ->assertSessionHas('success');
                $this->assertNull($world['notice']->fresh(), $outcome);
            } else {
                $this->actingAs($world['owner'])
                    ->delete(route('app.notices.history.destroy', ['savedNotice' => $world['notice']->id]))
                    ->assertSessionHas('error', __('procynia.notices.history_delete_kept'));
                $this->assertDatabaseRefuses(fn () => DB::table('saved_notices')->where('id', $world['notice']->id)->delete(), "a case kept as {$outcome}");
            }
        }
    }

    private function assertCaseKept(array $world, string $message): void
    {
        $this->actingAs($world['owner'])
            ->delete(route('app.notices.saved.destroy', ['savedNotice' => $world['notice']->id]))
            ->assertRedirect()
            ->assertSessionHas('error', __($message));

        $this->assertNotNull($world['notice']->fresh());
        $this->assertFalse($world['notice']->fresh()->isDeletable());
        // And past every model: the database refuses the same (saved_notices_keep_decision_history).
        $this->assertDatabaseRefuses(fn () => DB::table('saved_notices')->where('id', $world['notice']->id)->delete(), 'the case');
    }

    private function assertDatabaseRefuses(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("The database deleted {$what}");
        } catch (QueryException) {
            // refused, as it should be
        } finally {
            DB::rollBack();
        }
    }

    private function assertListOffersDelete(array $world, bool $expected): void
    {
        $this->actingAs($world['owner'])
            ->get('/app/notices?mode=saved')
            ->assertOk()
            ->assertViewHas('page', function (array $page) use ($world, $expected): bool {
                $item = collect(data_get($page, 'props.savedNotices.data', data_get($page, 'props.notices.data', [])))
                    ->firstWhere('saved_notice_id', $world['notice']->id);
                $this->assertNotNull($item, 'the case is in the saved list');
                $this->assertSame($expected, $item['is_deletable']);

                return true;
            });
    }

    /** @return array{customer: Customer, owner: User, notice: SavedNotice} */
    private function world(): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Sletting AS',
            'slug' => 'sletting-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
        ]);

        $owner = User::factory()->create([
            'name' => 'Eier',
            'email' => 'eier.'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $notice = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $owner->id,
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'A42-'.Str::upper(Str::random(8)),
            'title' => 'Driftsavtale',
            'buyer_name' => 'Kommunen',
            'status' => 'ACTIVE',
        ]);

        return compact('customer', 'owner', 'notice');
    }

    private function requirement(SavedNotice $notice): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()->create([
            'saved_notice_id' => $notice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'published_at' => now(),
            'requirement_text' => 'Krav '.Str::random(6),
            'original_requirement_text' => 'Krav',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
        ]);
    }
}
